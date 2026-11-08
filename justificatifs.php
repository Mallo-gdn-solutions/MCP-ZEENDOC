<?php
/**
 * Justificatifs manquants — module de rapprochement GDN Solutions
 * ----------------------------------------------------------------
 * 1. Cherche dans Outlook (classique, sur ce PC) les mails avec une facture PDF jointe
 *    autour des dates des débits sans justificatif (chercher-mails.ps1, lecture seule).
 * 2. Note chaque PDF face à chaque débit : montant trouvé dans le PDF / le mail,
 *    expéditeur ou objet qui ressemble au libellé bancaire, écart de dates.
 * 3. Dépose dans Zeendoc (classeur Fournisseurs) le PDF que l'utilisateur a validé,
 *    via le compte RAPPROCHEMENT (ecriture.php) — journalisé, anti-doublon par empreinte.
 */
declare(strict_types=1);

const JM_CACHE   = __DIR__ . '/cache-mails';
const JM_PS      = __DIR__ . '/chercher-mails.ps1';
require_once __DIR__ . '/config.php';
/** Classeur où sont déposés les justificatifs retrouvés : celui des factures fournisseurs (module-config.json) */
function jm_classeur(): string { $c = mc_coll('fournisseurs'); if (!$c) throw new RuntimeException('Classeur fournisseurs non configuré'); return $c; }

/* ---------- outils texte ---------- */

function jm_norm(string $s): string
{
    // Sans dépendre de l'extension mbstring (pas toujours activée sous Windows)
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    return preg_replace('/[^A-Z0-9]+/', ' ', strtoupper($t !== false ? $t : $s));
}

/** Montants présents dans un texte, en centimes */
function jm_montants(string $t): array
{
    $t = str_replace(["\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x89"], ' ', $t);
    preg_match_all('/(?<![\d,.])(\d{1,3}(?:[ .]\d{3})+|\d+)[,.](\d{2})(?!\d)/', $t, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) {
        $ent = preg_replace('/[ .]/', '', $x[1]);
        if (strlen($ent) > 7) continue;
        $out[(int) $ent * 100 + (int) $x[2]] = true;
    }
    return $out;
}

/** Texte « au mieux » d'un PDF sans bibliothèque : flux décompressés + opérateurs Tj/TJ */
function jm_texte_pdf(string $pdf): string
{
    $txt = '';
    if (preg_match_all('/stream\r?\n(.*?)\s*endstream/s', $pdf, $m)) {
        foreach ($m[1] as $flux) {
            if (str_ends_with(rtrim($flux), '~>')) $flux = jm_ascii85(rtrim($flux));
            $d = @gzuncompress($flux);
            if ($d === false) $d = @gzinflate(substr($flux, 2));
            if ($d === false) $d = $flux;
            if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)\s*T[jJ\'"]|\[(?:[^\]]*)\]\s*TJ/s', $d, $ops)) {
                foreach ($ops[0] as $op) {
                    if (preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)/s', $op, $parts)) {
                        foreach ($parts[1] as $p) $txt .= stripcslashes($p);
                    }
                    $txt .= ' ';
                }
                $txt .= "\n";
            }
        }
    }
    return $txt;
}

function jm_ascii85(string $s): string
{
    $s = preg_replace('/\s+/', '', substr($s, 0, -2));
    $out = ''; $n = 0; $v = 0;
    for ($i = 0, $l = strlen($s); $i < $l; $i++) {
        if ($s[$i] === 'z' && $n === 0) { $out .= "\0\0\0\0"; continue; }
        $v = $v * 85 + (ord($s[$i]) - 33);
        if (++$n === 5) { $out .= pack('N', $v); $n = 0; $v = 0; }
    }
    if ($n > 0) { for ($k = $n; $k < 5; $k++) $v = $v * 85 + 84; $out .= substr(pack('N', $v), 0, $n - 1); }
    return $out;
}

/** Mots utiles d'un libellé bancaire (on enlève PRLV, SEPA, CB, dates, numéros…) */
function jm_mots_libelle(string $lib, array $alias): array
{
    $bruit = ['PRLV', 'SEPA', 'CB', 'CARTE', 'VIR', 'VIREMENT', 'FACTURE', 'FACT', 'FAC', 'PRELEVEMENT', 'ECH', 'INST',
        'DU', 'DE', 'LA', 'LE', 'LES', 'DES', 'ET', 'SAS', 'SARL', 'SA', 'EURL', 'FRANCE', 'FR', 'INT', 'ENT', 'PAIEMENT', 'COTISATION', 'REF'];
    $mots = [];
    foreach (explode(' ', jm_norm($lib)) as $w) {
        if (strlen($w) < 3 || ctype_digit($w) || preg_match('/\d{3,}/', $w) || in_array($w, $bruit, true)) continue;
        $mots[$w] = true;
    }
    // Alias fournisseurs : si le libellé contient un motif connu, on ajoute le nom du fournisseur
    $n = jm_norm($lib);
    foreach ($alias as $fournisseur => $motifs) {
        foreach ((array) $motifs as $mo) {
            if ($mo !== '' && str_contains($n, trim(jm_norm((string) $mo)))) {
                foreach (explode(' ', jm_norm((string) $fournisseur)) as $w) if (strlen($w) >= 3 && !in_array($w, $bruit, true)) $mots[$w] = true;
            }
        }
    }
    return array_keys($mots);
}

/* ---------- recherche ---------- */

/**
 * $debits = [['id'=>'...', 'date'=>'AAAA-MM-JJ', 'montant'=>'41,04', 'libelle'=>'...'], ...]
 * Renvoie ['ok'=>true, 'resultats'=>[id => [candidats...]], 'stats'=>[...]]
 */
function jm_chercher(array $debits): array
{
    if (!$debits) return ['ok' => true, 'resultats' => [], 'stats' => ['mails' => 0, 'pdf' => 0]];
    if (stripos(PHP_OS_FAMILY, 'Windows') === false) throw new RuntimeException('La recherche Outlook ne fonctionne que sous Windows.');

    $dates = array_map(fn($d) => strtotime((string) $d['date']), $debits);
    $du = date('Y-m-d', min($dates) - 60 * 86400);
    $au = date('Y-m-d', min(time(), max($dates) + 10 * 86400));

    if (!is_dir(JM_CACHE)) mkdir(JM_CACHE, 0700, true);
    $sortie = JM_CACHE . '/derniere-recherche.json';
    @unlink($sortie);
    $cmd = 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File ' . escapeshellarg(JM_PS)
        . ' -Du ' . escapeshellarg($du) . ' -Au ' . escapeshellarg($au)
        . ' -Sortie ' . escapeshellarg($sortie) . ' -DossierPj ' . escapeshellarg(JM_CACHE . '/pdf');
    @set_time_limit(300);
    $sortieConsole = (string) shell_exec($cmd . ' 2>&1');
    if (!is_file($sortie)) throw new RuntimeException('Outlook n\'a rien renvoyé. ' . substr(trim($sortieConsole), 0, 400));
    $brut = (string) file_get_contents($sortie);
    $brut = preg_replace('/^\xEF\xBB\xBF/', '', $brut);
    $j = json_decode($brut, true);
    if (!is_array($j)) throw new RuntimeException('Réponse Outlook illisible.');
    if (empty($j['ok'])) throw new RuntimeException((string) ($j['erreur'] ?? 'Erreur Outlook'));

    return jm_noter($debits, $j) + ['stats' => ['mails' => (int) ($j['vus'] ?? 0), 'tronque' => !empty($j['tronque']), 'du' => $du, 'au' => $au]];
}

/** Note les PDF reçus par mail face aux débits (séparé de la lecture Outlook pour pouvoir le tester) */
function jm_noter(array $debits, array $j): array
{
    $mails = $j['mails'] ?? [];
    if (isset($mails['recu'])) $mails = [$mails]; // un seul mail : PowerShell n'a pas fait de tableau

    $alias = is_file(__DIR__ . '/alias_fournisseurs.json') ? (json_decode((string) file_get_contents(__DIR__ . '/alias_fournisseurs.json'), true) ?: []) : [];
    $deposes = jm_empreintes_deposees();

    // Pré-calcul par PDF
    $pdfs = [];
    $index = [];
    foreach ($mails as $ml) {
        $pjs = $ml['pjs'] ?? [];
        if (isset($pjs['token'])) $pjs = [$pjs];
        $txtMail = (string) ($ml['objet'] ?? '') . "\n" . (string) ($ml['corps'] ?? '');
        $montMail = jm_montants($txtMail);
        $identite = jm_norm(($ml['expediteur'] ?? '') . ' ' . ($ml['email'] ?? '') . ' ' . ($ml['objet'] ?? ''));
        foreach ($pjs as $pj) {
            $tok = (string) ($pj['token'] ?? '');
            $f = JM_CACHE . "/pdf/$tok.pdf";
            if (!preg_match('/^[a-f0-9]{40}$/', $tok) || !is_file($f)) continue;
            $bin = (string) file_get_contents($f);
            $empreinte = hash('sha3-256', $bin);
            $txtPdf = jm_texte_pdf($bin);
            $pdfs[] = [
                'token' => $tok, 'nom' => (string) $pj['nom'], 'recu' => (string) $ml['recu'],
                'objet' => (string) ($ml['objet'] ?? ''), 'expediteur' => (string) ($ml['expediteur'] ?? ''), 'email' => (string) ($ml['email'] ?? ''),
                'dossier' => (string) ($ml['dossier'] ?? ''),
                'montPdf' => jm_montants($txtPdf), 'montMail' => $montMail,
                'identite' => ' ' . $identite . ' ' . jm_norm($txtPdf === '' ? '' : substr($txtPdf, 0, 3000)) . ' ',
                'identiteMail' => ' ' . $identite . ' ',
                'deja' => isset($deposes[$empreinte]),
            ];
            $index[$tok] = ['nom' => (string) $pj['nom'], 'recu' => (string) $ml['recu'], 'objet' => (string) ($ml['objet'] ?? ''), 'expediteur' => (string) ($ml['expediteur'] ?? '')];
        }
    }
    file_put_contents(JM_CACHE . '/index.json', json_encode($index, JSON_UNESCAPED_UNICODE), LOCK_EX);

    $resultats = [];
    foreach ($debits as $d) {
        $id = (string) $d['id'];
        $cents = abs(rb_cents((string) $d['montant']));
        $td = strtotime((string) $d['date']);
        $mots = jm_mots_libelle((string) $d['libelle'], $alias);
        $cands = [];
        foreach ($pdfs as $p) {
            $ecart = (int) round(($td - strtotime($p['recu'])) / 86400); // > 0 : mail reçu avant le débit
            if ($ecart < -10 || $ecart > 60) continue;
            $mtPdf = $cents > 0 && isset($p['montPdf'][$cents]);
            $mtMail = $cents > 0 && isset($p['montMail'][$cents]);
            $nom = false;
            foreach ($mots as $w) {
                if (str_contains($p['identiteMail'], " $w ") || str_contains($p['identite'], " $w ")) { $nom = true; break; }
            }
            if (!$mtPdf && !$mtMail && !$nom) continue;
            $score = ($mtPdf ? 60 : ($mtMail ? 45 : 0)) + ($nom ? 30 : 0) + ($ecart >= 0 && $ecart <= 35 ? 15 : 5);
            if (!$mtPdf && !$mtMail && $score < 40) continue;
            $raisons = [];
            if ($mtPdf) $raisons[] = 'montant trouvé dans le PDF';
            elseif ($mtMail) $raisons[] = 'montant cité dans le mail';
            if ($nom) $raisons[] = 'fournisseur reconnu';
            $raisons[] = $ecart >= 0 ? "reçu $ecart j avant le débit" : 'reçu ' . (-$ecart) . ' j après le débit';
            $cands[] = [
                'token' => $p['token'], 'nom' => $p['nom'], 'recu' => $p['recu'], 'objet' => $p['objet'],
                'expediteur' => $p['expediteur'], 'email' => $p['email'], 'dossier' => $p['dossier'],
                'confiance' => ($mtPdf || $mtMail) && $nom ? 'forte' : (($mtPdf || $mtMail) ? 'moyenne' : 'faible'),
                'raisons' => implode(' · ', $raisons), 'deja' => $p['deja'], 'score' => $score,
            ];
        }
        usort($cands, fn($a, $b) => $b['score'] <=> $a['score']);
        $resultats[$id] = array_map(function ($c) { unset($c['score']); return $c; }, array_slice($cands, 0, 3));
    }
    return ['ok' => true, 'resultats' => $resultats, 'nbPdf' => count($pdfs)];
}

/* ---------- PDF du cache ---------- */

function jm_chemin_pdf(string $token): string
{
    if (!preg_match('/^[a-f0-9]{40}$/', $token)) throw new InvalidArgumentException('Pièce jointe invalide');
    $f = JM_CACHE . "/pdf/$token.pdf";
    if (!is_file($f)) throw new RuntimeException('Pièce jointe introuvable : relance la recherche dans Outlook.');
    return $f;
}

function jm_empreintes_deposees(): array
{
    $out = [];
    foreach (noyau_journal_lire() as $j) {
        if (($j['type'] ?? '') === 'depot' && !empty($j['ok']) && !empty($j['empreinte'])) $out[$j['empreinte']] = $j;
    }
    return $out;
}

/* ---------- dépôt dans Zeendoc ---------- */

function jm_deposer(string $token, array $debit): array
{
    require_once __DIR__ . '/ecriture.php';
    $f = jm_chemin_pdf($token);
    $bin = (string) file_get_contents($f);
    if (strncmp($bin, '%PDF', 4) !== 0) throw new RuntimeException("Ce fichier n'est pas un PDF valide.");
    if (strlen($bin) > 20 * 1024 * 1024) throw new RuntimeException('PDF trop lourd (plus de 20 Mo).');
    $empreinte = hash('sha3-256', $bin);
    $deja = jm_empreintes_deposees();
    if (isset($deja[$empreinte])) throw new RuntimeException('Ce PDF a déjà été déposé dans Zeendoc le ' . date('d/m/Y à H:i', strtotime((string) $deja[$empreinte]['quand'])) . '.');

    $index = is_file(JM_CACHE . '/index.json') ? (json_decode((string) file_get_contents(JM_CACHE . '/index.json'), true) ?: []) : [];
    $nom = (string) ($index[$token]['nom'] ?? 'facture.pdf');
    $nom = preg_replace('/[^\w\-. ]+/u', '_', $nom);
    if (!preg_match('/\.pdf$/i', $nom)) $nom .= '.pdf';

    $r = ecr_http('POST', '/binders/' . jm_classeur() . '/documents', [
        'fileName' => $nom,
        'base64Document' => base64_encode($bin),
        'hash' => $empreinte,
    ]);
    $ok = $r['status'] >= 200 && $r['status'] < 300;
    $ligne = [
        'quand' => date('c'), 'type' => 'depot', 'collId' => jm_classeur(),
        'fichier' => $nom, 'empreinte' => $empreinte,
        'mail' => $index[$token] ?? null,
        'debit' => ['date' => $debit['date'] ?? '', 'libelle' => $debit['libelle'] ?? '', 'montant' => $debit['montant'] ?? ''],
        'http' => $r['status'], 'reponse' => $r['json'] ?? $r['brut'], 'ok' => $ok,
    ];
    noyau_journal_ajouter($ligne);
    if (!$ok) throw new RuntimeException("Zeendoc a refusé le dépôt (HTTP {$r['status']}) : " . $r['brut']);
    return ['uploadId' => $r['json']['uploadId'] ?? null, 'fichier' => $nom];
}
