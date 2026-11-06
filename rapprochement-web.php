<?php
/**
 * Interface web locale du module de rapprochement — GDN Solutions
 * Lancement : lancer-rapprochement.bat  (serveur PHP sur 127.0.0.1:8765, accessible depuis ce PC uniquement)
 * Lit les factures dans Zeendoc et le relevé CSV. N'écrit dans Zeendoc (via ecriture.php) qu'après un clic de validation.
 */
declare(strict_types=1);
date_default_timezone_set('Europe/Paris'); // heures de l'historique et des journaux à l'heure française

// Identifiants : variables d'environnement, sinon ceux de la configuration Claude Desktop (jamais affichés)
if (!getenv('ZEENDOC_LOGIN')) {
    $cfg = (getenv('APPDATA') ?: '') . '\\Claude\\claude_desktop_config.json';
    if (is_file($cfg)) {
        $j = json_decode((string) file_get_contents($cfg), true);
        // Premier serveur MCP de la config Claude Desktop qui porte des identifiants Zeendoc (nom libre : zeendoc-gdn, zeendoc-client…)
        foreach (($j['mcpServers'] ?? []) as $srv) {
            $env = (array) ($srv['env'] ?? []);
            if (empty($env['ZEENDOC_LOGIN'])) continue;
            foreach ($env as $k => $v) { if (str_starts_with((string) $k, 'ZEENDOC_')) putenv("$k=$v"); }
            break;
        }
    }
}
define('ZD_SANS_BOUCLE', true);
require __DIR__ . '/zeendoc-mcp.php'; // client Zeendoc (liste blanche lecture seule) + moteur

// Dans la page web, les erreurs PHP s'affichent à l'écran (le connecteur MCP les envoie sur stderr)
ini_set('display_errors', '1');
set_exception_handler(function (Throwable $e) {
    echo '<pre style="padding:16px;background:#FDECEA;color:#A3261B;white-space:pre-wrap">Erreur : ' . htmlspecialchars($e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine(), ENT_QUOTES, 'UTF-8') . '</pre>';
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo '<pre style="padding:16px;background:#FDECEA;color:#A3261B;white-space:pre-wrap">Erreur fatale : ' . htmlspecialchars($e['message'] . "\n" . $e['file'] . ':' . $e['line'], ENT_QUOTES, 'UTF-8') . '</pre>';
    }
});

// Seules les connexions locales sont servies
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Accès local uniquement');
}

$config = [
    'zeendoc_host'       => mc_host(),
    'zeendoc_url_client' => mc_url_client(),
    'zeendoc_login'      => getenv('ZEENDOC_LOGIN') ?: '',
    'zeendoc_password'   => getenv('ZEENDOC_PASSWORD') ?: '',
];

function h($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
/** Commentaire saisi dans Zeendoc (index « Commentaire », module-config.json : index.commentaire) */
function comm($c): string { $c = trim((string) $c); return $c === '' ? '' : '<div class="comm">💬 ' . h($c) . '</div>'; }

// Décisions mémorisées (sur ce PC uniquement) : paires document / ligne de relevé validées ou rejetées
const FICHIER_VALIDATIONS = __DIR__ . '/validations.json';
function lire_validations(): array
{
    $v = is_file(FICHIER_VALIDATIONS) ? json_decode((string) file_get_contents(FICHIER_VALIDATIONS), true) : null;
    return is_array($v) ? $v + ['valides' => [], 'rejets' => []] : ['valides' => [], 'rejets' => []];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ecrire') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require_once __DIR__ . '/ecriture.php';
        $m = json_decode((string) ($_POST['maj'] ?? ''), true);
        if (!is_array($m)) throw new InvalidArgumentException('Données invalides');
        // Un clic sur « Mettre à jour Zeendoc » = une série, annulable d'un bloc
        if (preg_match('/^[\w-]{6,40}$/', (string) ($_POST['serie'] ?? ''))) $m['serie'] = (string) $_POST['serie'];
        $l = ecr_appliquer($m);
        // Zeendoc réindexe en quelques secondes : on laisse un court délai avant de relire
        usleep(1500000);
        $relu = ecr_relire((string) $m['collId'], (int) $m['resId'], (string) ($m['refBancaire'] ?? ''));
        echo json_encode(['ok' => true, 'relu' => $relu], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(200);
        echo json_encode(['ok' => false, 'erreur' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
// Paiement groupé validé en un clic : le lot est repris dans le dernier rapprochement enregistré sur ce PC,
// chaque facture est relue dans Zeendoc avant écriture (conflits et doubles paiements contrôlés côté serveur)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['ecrire_lot', 'annuler_serie', 'lignes_releve', 'resoudre', 'classer', 'retablir'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        // Porte de sortie EXCEPTIONNELLE : classer sans suite un document ou un débit (rien n'est écrit dans Zeendoc).
        // Motif et explication obligatoires ; l'élément reste listé à part et peut être rétabli.
        if (in_array($_POST['action'], ['classer', 'retablir'], true)) {
            $coteC = ($_POST['cote'] ?? '') === 'clients' ? 'clients' : 'fournisseurs';
            // Un élément (cle, libelle, montant) ou plusieurs (elements = JSON), classés avec le même motif et la même explication
            $elements = isset($_POST['elements']) ? (array) json_decode((string) $_POST['elements'], true)
                : [['cle' => (string) ($_POST['cle'] ?? ''), 'libelle' => (string) ($_POST['libelle'] ?? ''), 'montant' => (string) ($_POST['montant'] ?? '')]];
            if (!$elements || count($elements) > 200) throw new InvalidArgumentException('Sélection vide ou trop grande (200 au plus)');
            foreach ($elements as $el) {
                if (!preg_match('/^(doc\|N?\d+|debit\|\d{4}-\d{2}-\d{2}\|\d+\|[^|]*\|.{0,200})$/u', (string) ($el['cle'] ?? ''))) throw new InvalidArgumentException('Élément invalide');
            }
            $v = lire_validations();
            if ($_POST['action'] === 'retablir') {
                foreach ($elements as $el) unset($v['classes']["$coteC|{$el['cle']}"]);
            } else {
                $motifs = ['anterieur' => 'Paiement antérieur au relevé (N-1)', 'perdu' => 'Justificatif perdu', 'hors_perimetre' => 'Hors périmètre (payé par un tiers, autre société…)', 'autre' => 'Autre'];
                $motif = (string) ($_POST['motif'] ?? '');
                $texte = trim((string) ($_POST['texte'] ?? ''));
                if (!isset($motifs[$motif])) throw new InvalidArgumentException('Choisissez un motif de classement.');
                if (strlen($texte) < 3) throw new InvalidArgumentException('Expliquez en quelques mots pourquoi cet élément est classé sans suite.');
                foreach ($elements as $el) {
                    $v['classes']["$coteC|{$el['cle']}"] = ['quand' => date('c'), 'motif' => $motifs[$motif], 'texte' => substr($texte, 0, 300),
                        'libelle' => substr((string) ($el['libelle'] ?? ''), 0, 200), 'montant' => substr((string) ($el['montant'] ?? ''), 0, 30)];
                }
            }
            file_put_contents(FICHIER_VALIDATIONS, json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        require_once __DIR__ . '/ecriture.php';
        // Bouton « Résoudre » : lignes du dernier relevé (débits côté fournisseurs, crédits côté clients)
        $lignesReleve = function (string $sens): array {
            if (!is_file(__DIR__ . '/dernier-releve.csv')) throw new RuntimeException('Aucun relevé enregistré : lancez d\'abord un rapprochement.');
            $out = [];
            foreach (rb_lire_csv((string) file_get_contents(__DIR__ . '/dernier-releve.csv')) as $op) {
                if ($op['sens'] !== $sens) continue;
                $out[$op['date'] . '|' . $op['cents'] . '|' . $op['ref'] . '|' . rb_norm($op['libelle'])] = $op;
            }
            return $out;
        };
        $sens = ($_POST['cote'] ?? '') === 'clients' ? 'credit' : 'debit';
        if ($_POST['action'] === 'lignes_releve') {
            // Documents qui utilisent déjà chaque ligne, d'après le dernier rapprochement (rapprochées, partielles, lots)
            $utilisees = [];
            $dernier = is_file(__DIR__ . '/historique/dernier.json') ? json_decode((string) file_get_contents(__DIR__ . '/historique/dernier.json'), true) : null;
            $rc = (array) ($dernier['resAll'][($_POST['cote'] ?? '') === 'clients' ? 'clients' : 'fournisseurs'] ?? []);
            foreach (array_merge((array) ($rc['rapprochees'] ?? []), (array) ($rc['partielles'] ?? [])) as $f) {
                foreach ((array) ($f['paiements'] ?? []) as $pm) {
                    $k = $pm['date'] . '|' . rb_cents((string) $pm['montant']) . '|' . $pm['ref'] . '|' . rb_norm((string) $pm['libelle']);
                    $nature = ['Note de frais' => 'note de frais', 'Note de frais avancée' => 'note de frais', 'Indemnités km' => 'IK'][$f['source'] ?? ''] ?? 'facture';
                    $utilisees[$k][(string) $f['resId']] = trim($f['fournisseur'] . ' ' . ($f['ref'] ?? '')) . " ($nature " . ltrim((string) $f['resId'], 'N') . ", $f[ttc] €)";
                }
            }
            $l = array_map(fn($k, $op) => ['cle' => $k, 'date' => $op['date'], 'libelle' => $op['libelle'], 'info' => $op['info'], 'ref' => $op['ref'],
                'cents' => $op['cents'], 'montant' => rb_eur($op['cents']), 'utilisee' => $utilisees[$k] ?? (object) []], array_keys($lr = $lignesReleve($sens)), $lr);
            usort($l, fn($a, $b) => strcmp($b['date'], $a['date']));
            echo json_encode(['ok' => true, 'lignes' => $l], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($_POST['action'] === 'resoudre') {
            $droits = ecr_droits();
            if (!$droits['ok']) throw new RuntimeException($droits['message']);
            $resId = (string) ($_POST['resId'] ?? '');
            if (!preg_match('/^N?\d+$/', $resId)) throw new InvalidArgumentException('Document invalide');
            $coll = (string) (str_starts_with($resId, 'N') ? mc_coll('notesDeFrais') : mc_coll(($_POST['cote'] ?? '') === 'clients' ? 'clients' : 'fournisseurs'));
            $toutes = $lignesReleve($sens);
            $choisies = [];
            foreach ((array) json_decode((string) ($_POST['lignes'] ?? '[]'), true) as $k) {
                if (!isset($toutes[$k])) throw new RuntimeException('Ligne du relevé introuvable (relevé changé ?) : relancez le rapprochement.');
                $choisies[] = $toutes[$k];
            }
            $motif = (string) ($_POST['motif'] ?? '');
            $res = rg_resolution($choisies, $motif, trim((string) ($_POST['texte'] ?? '')), (string) ($_POST['date'] ?? ''));
            if (isset($res['erreur'])) throw new InvalidArgumentException($res['erreur']);
            $attendu = json_decode((string) ($_POST['attendu'] ?? ''), true);
            $remplacer = !empty($_POST['remplacer']);
            $doc = ['collId' => $coll, 'resId' => (int) ltrim($resId, 'N'), 'libelle' => (string) ($_POST['libelle'] ?? "doc $resId"),
                    'justification' => $res['justification'], 'remplacer' => $remplacer] + (is_array($attendu) ? ['attendu' => $attendu] : []);
            $r = ecr_regler([$doc], $res['cible'], array_column($choisies, 'ref'), $remplacer ? ['*' => array_fill_keys(RG_CHAMPS, 'banque')] : [], true);
            $d = $r['documents'][0];
            if (!empty($d['ecrit']) || ($d['decision']['etat'] ?? '') === 'a_jour') {
                // Décision mémorisée : les lignes restent associées au document aux prochains rapprochements,
                // et un écart justifié ne fait plus apparaître le document en « paiement partiel »
                $v = lire_validations();
                foreach ($choisies as $op) $v['valides'][rb_cle($resId, $op)] = date('c');
                $ecart = (int) ($_POST['ttcCents'] ?? 0) - array_sum(array_column($choisies, 'cents'));
                if ($motif !== '' && $ecart !== 0 && !in_array($resId, (array) ($v['payes'] ?? []), true)) $v['payes'][] = $resId;
                $v['resolutions'][$resId] = ['quand' => date('c'), 'motif' => $motif, 'justification' => $res['justification'], 'refBancaire' => $res['cible']['refBancaire']];
                file_put_contents(FICHIER_VALIDATIONS, json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
            echo json_encode(['ok' => true, 'cible' => $res['cible'], 'document' => $d, 'serie' => $r['serie']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($_POST['action'] === 'annuler_serie') {
            echo json_encode(['ok' => true] + ecr_annuler_serie((string) ($_POST['serie'] ?? '')), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $droits = ecr_droits();
        if (!$droits['ok']) throw new RuntimeException($droits['message']);
        $dernier = is_file(__DIR__ . '/historique/dernier.json') ? json_decode((string) file_get_contents(__DIR__ . '/historique/dernier.json'), true) : null;
        $lot = null;
        foreach ((array) ($dernier['resAll']['fournisseurs']['lots'] ?? []) as $x) { if (($x['cle'] ?? '') === (string) ($_POST['cle'] ?? '')) { $lot = $x; break; } }
        if (!$lot) throw new RuntimeException('Lot introuvable dans le dernier rapprochement : relancez le rapprochement.');
        if (!$lot['factures']) throw new RuntimeException('Aucune facture de ce lot n\'a été trouvée dans Zeendoc.');
        $choix = json_decode((string) ($_POST['choix'] ?? '{}'), true);
        $choix = is_array($choix) ? $choix : [];
        $coll = (string) ($lot['collId'] ?? mc_coll('fournisseurs')); // un remboursement collaborateur vise le classeur Notes de frais
        // 'attendu' = valeurs affichées dans la carte : si Zeendoc a changé depuis, la facture n'est pas écrite
        $docs = array_map(fn($f) => ['collId' => $coll, 'resId' => (int) ltrim((string) $f['resId'], 'N'), 'libelle' => trim("$f[fournisseur] $f[ref]") . " ($f[ttc] €)",
            'attendu' => ['statut' => $f['statut'], 'datePaiement' => $f['datePaiementActuelle'], 'refBancaire' => $f['refBancaireActuelle']]], $lot['factures']);
        echo json_encode(['ok' => true] + ecr_regler($docs, rg_cible($lot), $lot['jetons'], $choix, true), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'erreur' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
// Justificatifs manquants : recherche dans Outlook, aperçu du PDF, dépôt dans Zeendoc
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['pdf'])) {
    require_once __DIR__ . '/justificatifs.php';
    try {
        $f = jm_chemin_pdf((string) $_GET['pdf']);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="justificatif.pdf"');
        header('X-Content-Type-Options: nosniff');
        readfile($f);
    } catch (Throwable $e) {
        http_response_code(404);
        echo h($e->getMessage());
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['chercher_mails', 'deposer'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require_once __DIR__ . '/justificatifs.php';
        if ($_POST['action'] === 'chercher_mails') {
            $debits = json_decode((string) ($_POST['debits'] ?? ''), true);
            if (!is_array($debits) || count($debits) > 500) throw new InvalidArgumentException('Liste des débits invalide');
            echo json_encode(jm_chercher($debits), JSON_UNESCAPED_UNICODE);
        } else {
            $debit = json_decode((string) ($_POST['debit'] ?? ''), true);
            $r = jm_deposer((string) ($_POST['token'] ?? ''), is_array($debit) ? $debit : []);
            echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'erreur' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $cle = (string) ($_POST['cle'] ?? '');
    $act = (string) $_POST['action'];
    if ($cle === '' || strlen($cle) > 400 || !in_array($act, ['valider', 'rejeter', 'annuler'], true)) {
        http_response_code(400);
        exit(json_encode(['ok' => false]));
    }
    $v = lire_validations();
    unset($v['valides'][$cle], $v['rejets'][$cle]);
    if ($act === 'valider') $v['valides'][$cle] = date('c');
    if ($act === 'rejeter') $v['rejets'][$cle]  = date('c');
    file_put_contents(FICHIER_VALIDATIONS, json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    exit(json_encode(['ok' => true, 'etat' => $act]));
}

// Lien vers un document dans Zeendoc : modèle à renseigner dans module-config.json ({collId}, {resId})
$modCfg = module_cfg() + ['classeurFactures' => mc_coll('fournisseurs'), 'classeurNotesDeFrais' => mc_coll('notesDeFrais')];
$collNdf = mc_coll('notesDeFrais') ?? '';
// Côtés disponibles selon les classeurs configurés (module-config.json)
$cotesDispo = array_values(array_filter(['fournisseurs', 'clients'], fn($k) => mc_classeur($k) !== null));
function lien_doc(string $resId, array $modCfg): ?string
{
    $modele = (string) ($modCfg['lienDocument'] ?? '');
    if ($modele === '') return null;
    $coll = str_starts_with($resId, 'N') ? ($modCfg['classeurNotesDeFrais'] ?? '') : ($modCfg['classeurFactures'] ?? '');
    if ($coll === '') return null;
    return strtr($modele, ['{collId}' => rawurlencode($coll), '{resId}' => rawurlencode(ltrim($resId, 'N'))]);
}
// Montant d'une opération bancaire : rouge avec « − » pour un débit, vert avec « + » pour un crédit
function mt(array $o): string
{
    $credit = ($o['sens'] ?? 'debit') === 'credit';
    return '<span class="mt ' . ($credit ? 'credit' : 'debit') . '" title="' . ($credit ? 'Crédit' : 'Débit') . '">' . ($credit ? '+' : '−') . '&#8239;' . h($o['montant']) . '&nbsp;€</span>';
}
// Échéance effective d'une facture : l'index échéance, sinon date de facture + 30 j (même règle que le moteur)
function ech_eff(array $f): string { return !empty($f['echeance']) ? (string) $f['echeance'] : date('Y-m-d', strtotime(($f['date'] ?? 'today') . ' +30 days')); }
function retard(array $f): int { return max(0, (int) floor((strtotime(date('Y-m-d')) - strtotime(ech_eff($f))) / 86400)); }
function dfr(?string $d): string { return $d ? date('d/m/Y', strtotime($d)) : '—'; }

// Historique : dernier rapprochement enregistré sur ce PC (réaffiché à l'ouverture du module)
const DOSSIER_HISTO = __DIR__ . '/historique';
const FICHIER_RUNS  = __DIR__ . '/historique/rapprochements.jsonl';
$snap = null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && is_file(DOSSIER_HISTO . '/dernier.json')) {
    $snap = json_decode((string) file_get_contents(DOSSIER_HISTO . '/dernier.json'), true) ?: null;
}

// Côté du rapprochement : factures fournisseurs ↔ débits, ou factures clients ↔ crédits (encaissements)
$cote = (($_GET['vue'] ?? $_POST['vue'] ?? ($snap['vue'] ?? $snap['cote'] ?? '')) === 'clients') ? 'clients' : 'fournisseurs';
$cli = $cote === 'clients';
if (!in_array($cote, $cotesDispo, true)) { $cote = $cotesDispo[0]; $cli = $cote === 'clients'; }
$collFact = (string) mc_coll($cote);
$modCfg['classeurFactures'] = $collFact;
$L = $cli
    ? ['op' => 'encaissement', 'ops' => 'Encaissement(s) bancaire(s)', 'sansOp' => 'Payées sans encaissement', 'orphelins' => 'Encaissements non identifiés',
       'echues' => 'Impayés échus', 'tiers' => 'Clients', 'aucun' => 'Aucun encaissement correspondant sur le relevé : à relancer.']
    : ['op' => 'débit', 'ops' => 'Débit(s) bancaire(s)', 'sansOp' => 'Payées sans débit', 'orphelins' => 'Débits sans justificatif',
       'echues' => 'Échues non réglées', 'tiers' => 'Fournisseurs et notes de frais', 'aucun' => 'Aucun débit correspondant sur le relevé.'];
$du  = $_POST['du'] ?? ($snap['du'] ?? (module_cfg()['periodeDebutParDefaut'] ?? '01/01/' . date('Y')));
$au  = $_POST['au'] ?? ($snap['au'] ?? date('d/m/Y'));
$res = null;
$resAll = [];
$err = null;
$nomCsv = null;
$duree = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Le dernier relevé est conservé sur ce PC pour pouvoir relancer sans le resélectionner
        $dernier = __DIR__ . '/dernier-releve.csv';
        if (!empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
            $contenuCsv = (string) file_get_contents($_FILES['csv']['tmp_name']);
            file_put_contents($dernier, $contenuCsv, LOCK_EX);
            file_put_contents($dernier . '.nom', basename((string) $_FILES['csv']['name']));
            $nomCsv = basename((string) $_FILES['csv']['name']);
        } elseif (!empty($_POST['reprendre']) && is_file($dernier)) {
            $contenuCsv = (string) file_get_contents($dernier);
            $nomCsv = (is_file($dernier . '.nom') ? (string) file_get_contents($dernier . '.nom') : 'dernier relevé') . ' (repris)';
        } else {
            throw new RuntimeException('Choisissez le fichier CSV du relevé bancaire.');
        }
        $t0 = microtime(true);
        $resAll = [];
        $errCote = [];
        foreach ($cotesDispo as $c) {
            try {
                $resAll[$c] = appeler_outil($config, 'zeendoc_rapprocher', [
                    'du' => $du, 'au' => $au,
                    'csvContenu' => $contenuCsv,
                    'detail' => true,
                    'inclureNotesDeFrais' => $c === 'fournisseurs', // toujours, dès que le classeur Notes de frais est configuré
                    'cote' => $c,
                    'valides' => array_keys(lire_validations()['valides']),
                    'rejets'  => array_keys(lire_validations()['rejets']),
                    'payes'   => array_values((array) (lire_validations()['payes'] ?? [])),
                ]);
            } catch (Throwable $e) {
                $errCote[$c] = $e->getMessage();
            }
        }
        if (!$resAll) throw new RuntimeException(implode(' · ', $errCote));
        $res = $resAll[$cote] ?? null;
        if (!$res) $err = 'Côté ' . ($cli ? 'Clients' : 'Fournisseurs') . ' : ' . ($errCote[$cote] ?? 'non calculé');
        $duree = round(microtime(true) - $t0, 1);
        try {
            if (!is_dir(DOSSIER_HISTO)) mkdir(DOSSIER_HISTO, 0700, true);
            $quand = date('c');
            file_put_contents(DOSSIER_HISTO . '/dernier.json', json_encode(['quand' => $quand, 'vue' => $cote, 'du' => $du, 'au' => $au,
                'nomCsv' => $nomCsv, 'duree' => $duree, 'ndf' => !empty($_POST['ndf']), 'resAll' => $resAll], JSON_UNESCAPED_UNICODE), LOCK_EX);
            foreach ($resAll as $c => $rc) {
                $syn = $rc['synthese'] ?? [];
                file_put_contents(FICHIER_RUNS, json_encode(['quand' => $quand, 'cote' => $c, 'du' => $du, 'au' => $au, 'releve' => $nomCsv,
                    'rapprochees' => $syn['rapprochees'] ?? '', 'echues' => $syn['echues_non_reglees'] ?? '', 'orphelins' => $syn['debits_sans_facture'] ?? '',
                    'aCorriger' => $syn['statuts_a_corriger'] ?? ''], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
            }
        } catch (Throwable $e) { /* l'historique ne doit jamais bloquer le rapprochement */ }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
} elseif ($snap && (is_array($snap['resAll'] ?? null) || is_array($snap['res'] ?? null))) {
    // Réouverture du module ou bascule de vue : on réaffiche le dernier résultat (instantané, à relancer pour rafraîchir)
    $resAll = $snap['resAll'] ?? [($snap['cote'] ?? 'fournisseurs') => $snap['res']];
    $res = $resAll[$cote] ?? null;
    if (!$res) $err = 'Ce côté n\'a pas été calculé lors du dernier rapprochement : relancez.';
    $nomCsv = (string) ($snap['nomCsv'] ?? '');
    $duree = $snap['duree'] ?? null;
    if (!empty($snap['ndf'])) $_POST['ndf'] = '1';
}

// Historique lisible : rapprochements lancés, écritures Zeendoc, dépôts de justificatifs, décisions
$histo = [];
$lireJsonl = function (string $f): array {
    if (!is_file($f)) return [];
    $out = [];
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) { $j = json_decode($l, true); if (is_array($j)) $out[] = $j; }
    return $out;
};
foreach ($lireJsonl(FICHIER_RUNS) as $r) {
    $histo[] = ['quand' => $r['quand'], 'type' => 'run', 'ok' => true,
        'titre' => 'Rapprochement ' . ($r['cote'] === 'clients' ? 'Clients' : 'Fournisseurs') . ' · ' . $r['du'] . ' → ' . $r['au'],
        'detail' => 'Relevé ' . ($r['releve'] ?? '') . ' · rapprochées ' . $r['rapprochees'] . ' · échues ' . $r['echues'] . ' · sans pièce ' . $r['orphelins'] . ' · statuts à corriger ' . $r['aCorriger']];
}
foreach ($lireJsonl(__DIR__ . '/ecritures-journal.jsonl') as $e) {
    if (($e['type'] ?? '') === 'annulation') {
        $histo[] = ['quand' => $e['quand'], 'type' => 'ecriture', 'ok' => !empty($e['ok']),
            'titre' => 'Annulation : ' . ($e['document'] ?? ('doc ' . ($e['resId'] ?? ''))) . ' remis à son état d\'avant',
            'detail' => 'Série ' . ($e['serie'] ?? '') . (empty($e['ok']) ? ' · refusé par Zeendoc (HTTP ' . ($e['http'] ?? '?') . ')' : '')];
        continue;
    }
    if (($e['type'] ?? '') === 'depot') {
        $histo[] = ['quand' => $e['quand'], 'type' => 'depot', 'ok' => !empty($e['ok']),
            'titre' => 'Justificatif déposé dans Zeendoc : ' . ($e['fichier'] ?? ''),
            'detail' => 'Pour le débit du ' . dfr($e['debit']['date'] ?? null) . ' · ' . ($e['debit']['libelle'] ?? '') . ' · ' . ($e['debit']['montant'] ?? '') . ' €' . (empty($e['ok']) ? ' · refusé (HTTP ' . ($e['http'] ?? '?') . ')' : '')];
        continue;
    }
    $ap = $e['apres'] ?? [];
    $histo[] = ['quand' => $e['quand'], 'type' => 'ecriture', 'ok' => !empty($e['ok']),
        'titre' => ($e['document'] ?? ('doc ' . ($e['resId'] ?? ''))) . ' → ' . ($ap['statut'] ?? ''),
        'detail' => mc_nom_classeur((string) ($e['collId'] ?? '')) . ' · payé le ' . dfr($ap['datePaiement'] ?? null) . ' · réf. ' . ($ap['refBancaire'] ?? '')
            . (empty($e['ok']) ? ' · refusé par Zeendoc (HTTP ' . ($e['http'] ?? '?') . ')' : ''),
        'lien' => lien_doc(($e['collId'] === $collNdf ? 'N' : '') . ($e['resId'] ?? ''), ['classeurFactures' => $e['collId']] + $modCfg)];
}
foreach (['valides' => 'Association validée', 'rejets' => 'Association rejetée'] as $k => $lib) {
    foreach ((array) (lire_validations()[$k] ?? []) as $cleV => $q) {
        $p = explode('|', (string) $cleV, 4);
        $histo[] = ['quand' => $q, 'type' => 'decision', 'ok' => $k === 'valides',
            'titre' => $lib . ' : doc ' . ltrim($p[0] ?? '', 'N') . (str_starts_with($p[0] ?? '', 'N') ? ' (note de frais)' : ''),
            'detail' => 'Opération du ' . dfr($p[1] ?? null) . ' · ' . rb_eur((int) ($p[2] ?? 0)) . ' € · ' . ($p[3] ?? '')];
    }
}
usort($histo, fn($a, $b) => strcmp((string) $b['quand'], (string) $a['quand']));

$S = $res['synthese'] ?? [];
$nb = fn(string $k) => (int) ($S[$k] ?? 0);
$montant = function (string $k) use ($S) { return preg_match('/\(([\d\s\x{00A0},.]+)/u', (string) ($S[$k] ?? ''), $m) ? trim($m[1]) : ''; };

// Anomalies "À traiter"
$aTraiter = [];
if ($res) {
    foreach ($res['partielles'] as $f) {
        $aTraiter[] = ['res' => (string) $f['resId'], 'resoudre' => $f, 'comm' => $f['commentaire'] ?? '', 'lien' => lien_doc((string) $f['resId'], $modCfg), 'k' => 'Paiement partiel', 'c' => 'warn', 'm' => $f['ttc'], 't' => "$f[fournisseur] · $f[ref]",
            'd' => 'Réglé : ' . implode(' + ', array_map(fn($p) => dfr($p['date']) . ' ' . $p['impute'] . ' € (' . $p['methode'] . ')', $f['paiements'])) . ". Reste $f[reste] €. Statut actuel : $f[statut]."];
    }
    foreach ($res['echues_non_reglees'] as $f) {
        $aTraiter[] = ['res' => (string) $f['resId'], 'resoudre' => $f, 'comm' => $f['commentaire'] ?? '', 'lien' => lien_doc((string) $f['resId'], $modCfg), 'k' => 'Échue non réglée', 'c' => 'bad', 'm' => $f['ttc'], 't' => "$f[fournisseur] · $f[ref]",
            'd' => 'Échéance au ' . dfr(ech_eff($f)) . (empty($f['echeance']) ? ' (estimée : facture + 30 j)' : '') . ' · ' . retard($f) . ' j de retard. '
                . (!empty($f['avoirs']) ? 'Net après avoir ' . implode(', ', array_column($f['avoirs'], 'ref')) . ' (brut ' . $f['ttcBrut'] . ' €). ' : '') . $L['aucun']];
    }
    foreach ($res['ndf_sans_debit'] ?? [] as $f) {
        $aTraiter[] = ['res' => (string) $f['resId'], 'resoudre' => $f, 'comm' => $f['commentaire'] ?? '', 'lien' => lien_doc((string) $f['resId'], $modCfg), 'k' => 'Ticket NDF sans débit', 'c' => 'info', 'm' => $f['ttc'], 't' => "$f[fournisseur] · note de frais du " . dfr($f['date']),
            'd' => 'Payé par CB professionnelle selon la note de frais, mais aucun débit CB de ce montant autour de cette date. Vérifier le montant saisi ou la carte utilisée.'];
    }
    foreach ($res['payees_sans_debit'] as $f) {
        $aTraiter[] = ['res' => (string) $f['resId'], 'resoudre' => $f, 'comm' => $f['commentaire'] ?? '', 'lien' => lien_doc((string) $f['resId'], $modCfg), 'k' => $cli ? 'Payée, sans encaissement' : 'Payée, sans débit', 'c' => 'info', 'm' => $f['ttc'], 't' => "$f[fournisseur] · $f[ref]",
            'd' => 'Marquée « ' . $f['statut'] . ' » dans Zeendoc, mais aucun ' . $L['op'] . ' trouvé (échéance ' . dfr($f['echeance']) . ').'];
    }
    foreach ($res['rapprochees'] as $f) {
        foreach ($f['paiements'] ?? [] as $p) {
            if (preg_match('/valider|confirmer|inférieur/i', $p['methode'])) {
                $aTraiter[] = ['comm' => $f['commentaire'] ?? '', 'k' => count($f['paiements']) > 1 ? 'À valider · ' . count($f['paiements']) . ' paiements' : 'À valider', 'c' => 'warn', 'm' => $f['ttc'], 't' => "$f[fournisseur] · $f[ref]",
                    'pair' => true, 'cle' => implode("\n", array_column($f['paiements'], 'cle')), 'ops' => $f['paiements'], 'op' => $p, 'doc' => $f, 'lien' => lien_doc((string) $f['resId'], $modCfg)];
                break;
            }
        }
    }
}
// Instantané réaffiché (réouverture, bascule d'onglet, F5) : il n'est pas rafraîchi après écriture.
// On écarte donc du plan ce qui a déjà été écrit dans Zeendoc depuis cet instantané (journal des écritures).
$dejaEcrits = [];
$depuisSnap = ($_SERVER['REQUEST_METHOD'] !== 'POST' && $snap) ? strtotime((string) ($snap['quand'] ?? '')) : false;
if ($depuisSnap && is_file(__DIR__ . '/ecritures-journal.jsonl')) {
    foreach (file(__DIR__ . '/ecritures-journal.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $lj) {
        $ej = json_decode($lj, true);
        if (!is_array($ej) || empty($ej['ok']) || strtotime((string) ($ej['quand'] ?? '')) < $depuisSnap) continue;
        $cleE = ($ej['collId'] ?? '') . '-' . ($ej['resId'] ?? '');
        // Une annulation postérieure remet le document dans son état de l'instantané
        if (($ej['type'] ?? '') === 'annulation') { unset($dejaEcrits[$cleE]); continue; }
        if (($ej['type'] ?? '') !== '') continue; // dépôts de justificatifs : pas de valeurs d'index
        $dejaEcrits[$cleE] = array_filter((array) ($ej['apres'] ?? []), fn($x) => $x !== null && $x !== '') + ($dejaEcrits[$cleE] ?? []);
    }
}
// Plan d'écriture : uniquement les rapprochements sûrs ou validés par l'utilisateur
$plan = [];
if ($res) {
    $exclure = mc_exclure_ecriture();
    $sur = fn(string $m) => !preg_match('/valider|confirmer|inférieur/i', $m);
    $ajouter = function (array $f, string $statut) use (&$plan, $exclure, $sur, $collFact, $collNdf, $dejaEcrits) {
        $ps = $f['paiements'] ?? [];
        if (!$ps) return;
        // Paiements groupés : écrits depuis l'onglet « Paiements groupés » (contrôle des conflits, lot en un clic)
        foreach ($ps as $p) { if (str_starts_with((string) $p['methode'], 'paiement groupé')) return; }
        // Fournisseurs exclus (frais bancaires) : seulement quand la référence du prélèvement a été retrouvée dans la facture
        $parRef = !array_filter($ps, fn($p) => stripos((string) $p['methode'], 'référence du prélèvement') !== 0 && $p['methode'] !== 'validé par vous');
        if (!$parRef) { foreach ($exclure as $x) { if ($x !== '' && stripos((string) $f['fournisseur'], $x) !== false) return; } }
        foreach ($ps as $p) { if (!$sur($p['methode'])) return; }
        $dates = array_column($ps, 'date'); sort($dates);
        $dp = end($dates);
        $ref = implode(' + ', array_filter(array_column($ps, 'ref')));
        $statutActuel = $f['statut'] === '(vide)' ? '' : $f['statut'];
        if ($statutActuel === $statut && $f['datePaiementActuelle'] === $dp && $f['refBancaireActuelle'] === $ref) return;
        $cleJ = (str_starts_with((string) $f['resId'], 'N') ? $collNdf : $collFact) . '-' . ltrim((string) $f['resId'], 'N');
        $ecrit = $dejaEcrits[$cleJ] ?? null;
        if ($ecrit && ($ecrit['statut'] ?? '') === $statut && ($ecrit['datePaiement'] ?? '') === $dp && ($ecrit['refBancaire'] ?? '') === $ref) return;
        $id = (string) $f['resId'];
        $plan[] = [
            'collId' => str_starts_with($id, 'N') ? $collNdf : $collFact,
            'resId' => (int) ltrim($id, 'N'),
            'libelle' => trim("$f[fournisseur] $f[ref]") . " ($f[ttc] €)",
            'source' => $f['source'] ?? 'Facture',
            'statut' => $statut, 'datePaiement' => $dp, 'refBancaire' => $ref,
            'avant' => ['statut' => $statutActuel, 'datePaiement' => $f['datePaiementActuelle'], 'refBancaire' => $f['refBancaireActuelle']],
            'methode' => implode(' + ', array_unique(array_column($ps, 'methode'))),
        ];
    };
    foreach ($res['rapprochees'] as $f) $ajouter($f, 'Payé');
    // « Paiement partiel » n'est proposé que pour un vrai échéancier détecté, jamais pour une paire validée avec un écart
    foreach ($res['partielles'] as $f) {
        if (array_filter($f['paiements'] ?? [], fn($p) => $p['methode'] === 'validé par vous')) continue;
        $ajouter($f, 'Paiement partiel');
    }
}

$aMaj = [];
foreach (($res['statuts_a_corriger'] ?? []) as $s) $aMaj[$s['resId']] = $s;
// Clé commune onglet « Rapprochées » / plan d'écriture : coll_13-143, coll_14-170
$cleDoc = fn($id) => (str_starts_with((string) $id, 'N') ? $collNdf : $collFact) . '-' . ltrim((string) $id, 'N');
$dansPlan = [];
foreach ($plan as $m) $dansPlan[$m['collId'] . '-' . $m['resId']] = true;
$listeExclus = mc_exclure_ecriture();
$estExclu = function (string $fourn) use ($listeExclus) { foreach ($listeExclus as $x) { if ($x !== '' && stripos($fourn, $x) !== false) return true; } return false; };

// Paiements groupés (1 débit ↔ N factures), contrôle inverse, droits du compte d'écriture, dernière série annulable
$lots = $cli ? [] : (array) ($res['lots'] ?? []);
$nonProuves = $cli ? [] : (array) ($res['paiements_non_prouves'] ?? []);
// Instantané réaffiché : une référence bancaire écrite depuis (lot, Résoudre, Mise à jour) prouve le paiement
$nonProuves = array_values(array_filter($nonProuves, fn($f) => ($dejaEcrits[$collFact . '-' . $f['resId']]['refBancaire'] ?? '') === ''));
$nonRemboursees = $cli ? [] : array_values(array_filter((array) ($res['ik_non_remboursees'] ?? []), fn($f) => ($dejaEcrits[$cleDoc($f['resId'])]['refBancaire'] ?? '') === ''));
foreach ($lots as $kl => $lot) {
    foreach ($lot['factures'] as $kf => $f) {
        $e = $dejaEcrits[$cleDoc($f['resId'])] ?? null;
        if (!$e) continue;
        // Valeurs écrites depuis l'instantané : on les affiche comme valeurs Zeendoc actuelles
        $lots[$kl]['factures'][$kf]['comparaison'] = rg_comparer(['statut' => $e['statut'] ?? $f['statut'], 'datePaiement' => $e['datePaiement'] ?? $f['datePaiementActuelle'],
            'refBancaire' => $e['refBancaire'] ?? $f['refBancaireActuelle']], rg_cible($lot), $lot['jetons']);
    }
}
$pgInfo = (array) ($res['paiements_groupes'] ?? []);
$droits = ['ok' => false, 'message' => ''];
$derniereSerie = null;
if ($res) {
    try {
        require_once __DIR__ . '/ecriture.php';
        $droits = ecr_droits();
        $derniereSerie = ecr_derniere_serie();
    } catch (Throwable $e) {
        $droits = ['ok' => false, 'message' => $e->getMessage()];
    }
}
// Propositions en attente de validation (cartes « À valider » de l'onglet À traiter), par document
$propositions = [];
foreach ($aTraiter as $i) { if (!empty($i['pair'])) $propositions[(string) $i['doc']['resId']] = true; }
// Données du panneau « Résoudre » pour un document (valeurs affichées = garde-fou à l'écriture)
$docResoudre = function (array $f): string {
    $statut = (string) ($f['statut'] ?? '');
    return h(json_encode([
        'resId' => (string) $f['resId'], 'fournisseur' => (string) $f['fournisseur'], 'ref' => (string) ($f['ref'] ?? ''), 'date' => (string) $f['date'],
        'libelle' => trim("$f[fournisseur] " . ($f['ref'] ?? '')) . " ($f[ttc] €)", 'ttc' => (string) $f['ttc'], 'ttcCents' => rb_cents((string) $f['ttc']),
        'attendu' => ['statut' => $statut === '(vide)' ? '' : $statut, 'datePaiement' => (string) ($f['datePaiementActuelle'] ?? $f['datePaiement'] ?? ''), 'refBancaire' => (string) ($f['refBancaireActuelle'] ?? '')],
    ], JSON_UNESCAPED_UNICODE));
};
$boutonsDoc = function (array $f) use ($propositions, $docResoudre): string {
    $id = (string) $f['resId'];
    $b = isset($propositions[$id]) ? '<button type="button" class="btn2 voir-prop" data-res="' . h($id) . '">Voir la proposition</button> ' : '';
    $b .= '<button type="button" class="btn2 resoudre" data-doc="' . $docResoudre($f) . '">Résoudre…</button>';
    return $b === '' ? '' : '<div class="actions-doc">' . $b . '</div>';
};
// Lots déjà entièrement à jour dans Zeendoc (rien à écrire, rien de manquant) : regroupés à part, repliés
$lotsAJour = [];
$lotsATraiter = [];
foreach ($lots as $lot) {
    $fait = $lot['etat'] === 'complet' && $lot['factures'];
    foreach ($lot['factures'] as $f) { if (rg_decider($f['comparaison'])['etat'] !== 'a_jour') { $fait = false; break; } }
    if ($fait) $lotsAJour[] = $lot; else $lotsATraiter[] = $lot;
}
$etatsLot = ['complet' => ['ok', 'Lot complet'], 'incomplet' => ['bad', 'Lot incomplet'], 'ecart' => ['warn', 'Écart de montant']];
$casChamp = ['vide' => ['info', 'à écrire'], 'identique' => ['ok', 'identique'], 'conflit' => ['warn', 'conflit']];
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rapprochement bancaire — GDN Solutions</title>
<style>
:root{--bg:#F4F6F8;--card:#FFFFFF;--ink:#15202B;--mut:#5B6672;--line:#DDE2E7;--soft:#EEF1F4;--acc:#1F5FBF;
--ok-bg:#E6F4EC;--ok:#17633F;--info-bg:#E8EEF7;--info:#174A94;--warn-bg:#FDF0E3;--warn:#8A4205;--bad-bg:#FDECEA;--bad:#A3261B}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.45 "Segoe UI",system-ui,sans-serif}
.mono{font-family:Consolas,"IBM Plex Mono",monospace}
header{background:var(--card);border-bottom:1px solid var(--line)}
.wrap{max-width:1360px;margin:0 auto;padding:14px 24px}
.top{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px}
.logo{width:36px;height:36px;border-radius:8px;background:var(--acc);display:flex;align-items:center;justify-content:center}
.brand{display:flex;align-items:center;gap:14px}.brand b{font-size:16px}.sub{color:var(--mut);font-size:12px}
main{max-width:1360px;margin:0 auto;padding:24px;display:flex;flex-direction:column;gap:20px}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px}
form.params{padding:16px 20px;display:flex;flex-wrap:wrap;align-items:flex-end;gap:16px}
.f{display:flex;flex-direction:column;gap:6px}.f label{font-size:12px;color:var(--mut);font-weight:600}
input[type=text]{height:40px;width:130px;padding:0 12px;border:1px solid #C9D0D7;border-radius:6px;font:inherit}
input[type=file]{height:40px;padding:8px;border:1px dashed #C9D0D7;border-radius:6px;background:#FAFBFC;font:inherit;max-width:100%}
.btn{height:40px;padding:0 18px;border:0;border-radius:6px;background:var(--acc);color:#fff;font:inherit;font-weight:600;cursor:pointer}
.btn2[aria-pressed="true"]{background:var(--acc);color:#fff;border-color:var(--acc)}
.btn2{min-height:36px;padding:0 14px;border:1px solid var(--line);border-radius:6px;background:#fff;color:var(--ink);font:inherit;cursor:pointer}
.btn:disabled{background:#9DB3D6;cursor:not-allowed}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}
.kpi{padding:16px;display:flex;flex-direction:column;gap:6px}.kpi .l{font-size:12px;color:var(--mut);font-weight:600;display:flex;align-items:center;gap:8px}
.kpi .v{font-size:28px;font-weight:600;line-height:1.1}.kpi .s{font-size:12px;color:var(--mut)}
.dot{width:8px;height:8px;border-radius:50%;display:inline-block}
.bascule{display:inline-flex;gap:4px;padding:4px;background:var(--card);border:1px solid var(--line);border-radius:10px;align-self:flex-start;flex-wrap:wrap}
.bascule a{display:flex;align-items:center;gap:8px;padding:8px 16px;border-radius:7px;color:var(--ink);text-decoration:none;font-weight:600}
.bascule a:hover{background:var(--soft)}.bascule a.on{background:var(--acc);color:#fff}.bascule a.on .small{color:rgba(255,255,255,.85)}
.mt{font-weight:600;white-space:nowrap;padding:1px 6px;border-radius:4px}.mt.debit{color:#A3261B;background:#FDECEA}.mt.credit{color:#17633F;background:#E6F4EC}
.kpi{background:var(--kc);border-color:var(--kc);color:#fff}.kpi .l,.kpi .s{color:rgba(255,255,255,.92)}.kpi .v{color:#fff}.kpi .dot{background:#fff}
.tabs{display:flex;flex-wrap:wrap;gap:4px;padding:8px 12px 0;border-bottom:1px solid var(--line)}
.tab{min-height:44px;padding:0 16px;border:0;background:transparent;cursor:pointer;font:inherit;font-weight:500;color:var(--mut);border-bottom:3px solid transparent}
.tab[aria-selected=true]{color:var(--ink);font-weight:600;border-bottom-color:var(--acc)}
.cnt{margin-left:6px;padding:2px 8px;border-radius:10px;background:var(--soft);color:var(--ink);font-size:12px}
.panel{display:none}.panel.on{display:block}
.scroll{overflow-x:auto}
table{width:100%;border-collapse:collapse}th{text-align:left;color:var(--mut);font-size:12px;font-weight:600;padding:12px 8px}
td{padding:12px 8px;border-top:1px solid var(--soft);vertical-align:top}td:first-child,th:first-child{padding-left:16px}td:last-child,th:last-child{padding-right:16px}
.r{text-align:right;white-space:nowrap}.small{color:var(--mut);font-size:12px}
.badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600}
.ok{background:var(--ok-bg);color:var(--ok)}.info{background:var(--info-bg);color:var(--info)}.warn{background:var(--warn-bg);color:var(--warn)}.bad{background:var(--bad-bg);color:var(--bad)}
.grid{padding:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:12px}
.issue{border:1px solid var(--line);border-radius:10px;padding:16px;display:flex;flex-direction:column;gap:8px}
.issue .h{display:flex;justify-content:space-between;align-items:center;gap:8px}
.foot{border-top:1px solid var(--line);padding:14px 16px;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;background:#FAFBFC;border-radius:0 0 10px 10px}
.err{padding:14px 16px;border-radius:10px;background:var(--bad-bg);color:var(--bad)}
.empty{padding:40px 24px;text-align:center;color:var(--mut)}
.pair{display:grid;grid-template-columns:1fr 1fr;gap:10px}.side{border:1px solid var(--soft);border-radius:8px;padding:10px;display:flex;flex-direction:column;gap:2px;min-width:0}
.side a{font-size:13px;margin-top:4px}
.decide,.lot-actions{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:4px}.chk{display:flex;align-items:center;gap:8px;min-height:36px;cursor:pointer;font-weight:600}
.chk input{width:20px;height:20px;accent-color:#17633F}
.issue.valide,.sugg.valide{border-color:#8CC7A5;background:#F4FAF6}.issue.rejete,.sugg.rejete{opacity:.55}
.sugg{border:1px solid var(--soft);border-radius:8px;padding:8px 10px;margin-bottom:6px}
.jm-barre{display:flex;flex-wrap:wrap;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--line)}.jm-barre .btn:disabled{opacity:.6;cursor:wait}
.sugg.mail{border-left:4px solid #1F5FBF}.sugg.mail.forte{border-left-color:#1E7B4F}.sugg.mail.faible{border-left-color:#B35408}.sugg.mail .act{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:6px}.sugg.mail.depose{background:#F4FAF6;border-color:#8CC7A5}.sugg .decide{margin-top:2px}.sugg .chk{font-weight:500}
[hidden]{display:none!important}
.actions-doc{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}.issue.flash{outline:3px solid var(--acc);outline-offset:2px}
dialog#resoudre{width:min(860px,96vw);max-height:92vh;border:1px solid var(--line);border-radius:12px;padding:0}dialog#resoudre::backdrop{background:rgba(21,32,43,.45)}
.rs{display:flex;flex-direction:column;gap:10px;padding:16px 18px}.rs-h{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;cursor:move;user-select:none}
dialog#resoudre.deplace{margin:0;position:fixed}
.rs input[type=text],.rs select,.rs input[type=date]{height:38px;width:100%;padding:0 10px;border:1px solid #C9D0D7;border-radius:6px;font:inherit}
.rs-lignes{max-height:300px;overflow:auto;border:1px solid var(--line);border-radius:8px}.rs-ligne{display:grid;grid-template-columns:24px 88px 1fr auto;gap:8px;align-items:center;padding:6px 10px;border-top:1px solid var(--soft);cursor:pointer}
.rs-ligne .deja{color:var(--warn);font-weight:600}.rs-alerte{color:var(--warn);font-weight:600}
.rs-ligne:first-child{border-top:0}.rs-ligne.on{background:#EEF4FC}.rs-ligne input{width:18px;height:18px}.rs-somme{font-weight:600}.rs-grille{display:grid;grid-template-columns:1fr 1fr;gap:10px}
#cl-classer{background:#8A4205}#cl-classer:disabled{background:#D9B99A;cursor:not-allowed}
.rs-sortie{border:1px dashed #C98A4B;border-radius:8px;padding:8px 12px;background:#FFFBF5}.rs-sortie summary{cursor:pointer;font-weight:600;color:var(--warn)}
.rs-chk{display:flex;gap:8px;align-items:center}.rs-apercu{background:var(--soft);border-radius:6px;padding:8px 10px}.rs-pied{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
@media (max-width:640px){.rs-grille{grid-template-columns:1fr}.rs-ligne{grid-template-columns:24px 1fr auto}.rs-ligne .d{display:none}}
.comm{margin-top:4px;padding:4px 8px;border-left:3px solid #E0B341;background:#FFF8E1;border-radius:4px;font-size:12px;color:var(--ink);white-space:pre-line}
.todo{color:var(--warn);font-weight:600}.done{color:var(--ok);font-weight:600}
@media (max-width:640px){main{padding:12px}.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<header><div class="wrap top">
  <div class="brand">
    <div class="logo"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 7h11l-3-3"/><path d="M17 17H6l3 3"/></svg></div>
    <div><b>Rapprochement bancaire</b><div class="sub">Armoire <?= h(strtoupper(str_replace('_', ' ', $config['zeendoc_url_client']))) ?> · <?= h($L['tiers']) ?> · écriture uniquement après validation</div></div>
  </div>
  <span class="sub">Module développé par <b style="color:var(--ink)">GDN Solutions</b></span>
</div></header>

<main>
  <form class="card params" method="post" enctype="multipart/form-data">
    <input type="hidden" name="vue" value="<?= h($cote) ?>">
    <div class="f"><label for="du">Factures du</label><input id="du" name="du" type="text" value="<?= h($du) ?>" required pattern="\d{2}/\d{2}/\d{4}"></div>
    <div class="f"><label for="au">au</label><input id="au" name="au" type="text" value="<?= h($au) ?>" required pattern="\d{2}/\d{2}/\d{4}"></div>
    <div class="f" style="flex:1 1 320px;min-width:0"><label for="csv">Relevé bancaire (CSV)</label><input id="csv" name="csv" type="file" accept=".csv,text/csv" <?= is_file(__DIR__ . '/dernier-releve.csv') ? '' : 'required' ?>><?php if (is_file(__DIR__ . '/dernier-releve.csv')): ?><span class="small">Vide = reprendre le dernier relevé</span><input type="hidden" name="reprendre" value="1"><?php endif; ?></div>
    <button class="btn" type="submit">Lancer le rapprochement</button>
  </form>

<?php if ($err): ?>
  <div class="err" role="alert"><b>Erreur :</b> <?= h($err) ?></div>
<?php endif; ?>

<?php if ($snap && $res): ?>
  <div class="card" style="padding:12px 16px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;border-left:4px solid var(--acc)">
    <span>🕘 <b>Dernier rapprochement</b> du <?= h(date('d/m/Y à H:i', strtotime((string) $snap['quand']))) ?> · période <?= h($du) ?> → <?= h($au) ?> · relevé <?= h($nomCsv) ?>
      <span class="small">· instantané : les statuts ont pu changer dans Zeendoc depuis</span></span>
    <form method="post" style="margin:0"><input type="hidden" name="du" value="<?= h($du) ?>"><input type="hidden" name="au" value="<?= h($au) ?>"><input type="hidden" name="vue" value="<?= h($cote) ?>"><input type="hidden" name="reprendre" value="1"><?php if (!empty($snap['ndf'])): ?><input type="hidden" name="ndf" value="1"><?php endif; ?>
      <button class="btn" type="submit" title="Relit Zeendoc (nouvelles factures fournisseurs, clients, notes de frais, index modifiés) et les sources de paiements groupés, avec le même relevé bancaire">🔄 Actualiser depuis Zeendoc</button></form>
  </div>
<?php endif; ?>

<?php if (!$res && !$err): ?>
  <div class="card empty">Choisissez la période et le relevé CSV, puis lancez le rapprochement.<br>Les factures sont lues en direct dans Zeendoc ; rien n'y est modifié.</div>
<?php endif; ?>

<?php if ($res): ?>
  <div class="small">Relevé <b><?= h($nomCsv) ?></b> · <?= h($S['source_releve'] ?? '') ?> · <?= h($S['factures_analysees'] ?? 0) ?> factures analysées · calculé en <?= h($duree) ?> s</div>

  <?php
    $badge = function (string $c) use ($resAll) {
        $r = $resAll[$c] ?? null; if (!$r) return '';
        $n = count($r['echues_non_reglees'] ?? []) + count($r['debits_sans_facture'] ?? []) + count($r['partielles'] ?? []);
        return $n ? '<span class="cnt">' . $n . '</span>' : '';
    };
  ?>
  <nav class="bascule" aria-label="Vue du rapprochement">
    <?php if (in_array('fournisseurs', $cotesDispo, true)): ?><a href="?vue=fournisseurs" class="<?= $cli ? '' : 'on' ?>" <?= $cli ? '' : 'aria-current="page"' ?>><span class="mt debit">−</span> Décaissements <span class="small">· Fournisseurs</span> <?= $badge('fournisseurs') ?></a><?php endif; ?>
    <?php if (in_array('clients', $cotesDispo, true)): ?><a href="?vue=clients" class="<?= $cli ? 'on' : '' ?>" <?= $cli ? 'aria-current="page"' : '' ?>><span class="mt credit">+</span> Encaissements <span class="small">· Clients</span> <?= $badge('clients') ?></a><?php endif; ?>
  </nav>

  <section class="kpis" aria-label="Synthèse">
    <?php foreach ([
      ['Rapprochées', $nb('rapprochees'), $montant('rapprochees') . ' €', '#1E7B4F'],
      ['Statuts à mettre à jour', count($aMaj), 'Payé / Paiement partiel', '#1F5FBF'],
      ['Paiements partiels', $nb('partielles'), 'reste à régler', '#B35408'],
      [$L['sansOp'], $nb('payees_sans_debit'), $montant('payees_sans_debit') . ' €', '#1F5FBF'],
      [$L['echues'], $nb('echues_non_reglees'), $montant('echues_non_reglees') . ' €', '#B42318'],
      [$L['orphelins'], $nb('debits_sans_facture'), $montant('debits_sans_facture') . ' €', '#5F6B76'],
    ] as [$l, $v, $s, $c]): ?>
      <div class="card kpi" style="--kc:<?= $c ?>"><div class="l"><span class="dot"></span><?= h($l) ?></div><div class="v"><?= h($v) ?></div><div class="s mono"><?= h($s) ?></div></div>
    <?php endforeach; ?>
  </section>

  <?php if ($derniereSerie): ?>
  <div class="card" id="serie" style="padding:12px 16px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;border-left:4px solid var(--ok)">
    <span>✏️ <b>Dernière série d'écritures</b> du <?= h(date('d/m/Y à H:i', strtotime((string) $derniereSerie['quand']))) ?> · <?= count($derniereSerie['lignes']) ?> document(s)
      <span class="small">· <?= h(implode(', ', array_slice(array_map(fn($l) => (string) $l['document'], $derniereSerie['lignes']), 0, 4))) ?><?= count($derniereSerie['lignes']) > 4 ? '…' : '' ?></span></span>
    <span><button type="button" class="btn2" id="annuler-serie" data-serie="<?= h($derniereSerie['serie']) ?>">Annuler cette série</button> <span class="small" id="annuler-etat" aria-live="polite"></span></span>
  </div>
  <?php endif; ?>

  <section class="card">
    <div class="tabs" role="tablist" aria-label="Résultats">
      <button class="tab" role="tab" aria-selected="true" data-p="p1">Rapprochées <span class="cnt"><?= count($res['rapprochees']) ?></span></button>
      <button class="tab" role="tab" aria-selected="false" data-p="p2">À traiter <span class="cnt"><?= count($aTraiter) ?></span></button>
      <button class="tab" role="tab" aria-selected="false" data-p="p3"><?= h($L['orphelins']) ?> <span class="cnt"><?= count($res['debits_sans_facture']) ?></span></button>
      <button class="tab" role="tab" aria-selected="false" data-p="p5">Mise à jour Zeendoc <span class="cnt"><?= count($plan) ?></span></button>
      <?php if (!$cli): ?><button class="tab" role="tab" aria-selected="false" data-p="p7">Paiements groupés et remboursements <span class="cnt" id="cnt-lots"><?= count($lotsATraiter) ?></span></button>
      <button class="tab" role="tab" aria-selected="false" data-p="p10">IK / NDF non remboursées <span class="cnt"><?= count($nonRemboursees) ?></span></button>
      <button class="tab" role="tab" aria-selected="false" data-p="p8">Payées sans preuve <span class="cnt"><?= count($nonProuves) ?></span></button><?php endif; ?>
      <?php if ($cli): ?><button class="tab" role="tab" aria-selected="false" data-p="p6">À relancer <span class="cnt"><?= count($res['echues_non_reglees']) ?></span></button><?php endif; ?>
      <button class="tab" role="tab" aria-selected="false" data-p="p9">Classés sans suite <span class="cnt"><?= count($res['classes_sans_suite'] ?? []) ?></span></button>
      <button class="tab" role="tab" aria-selected="false" data-p="p4">Non échues <span class="cnt"><?= count($res['non_echues'] ?? []) ?></span></button>
    </div>

    <div class="panel on" id="p1"><div class="scroll"><table style="min-width:980px">
      <thead><tr><th>Facture Zeendoc</th><th class="r">TTC</th><th><?= h($L['ops']) ?></th><th>Méthode</th><th>Statut Zeendoc</th></tr></thead>
      <tbody>
      <?php foreach ($res['rapprochees'] as $f):
        $maj = $aMaj[$f['resId']] ?? null; $cd = $cleDoc($f['resId']); ?>
        <tr data-doc="<?= h($cd) ?>">
          <td><b><?= h($f['fournisseur']) ?></b> <?php if (($f['source'] ?? '') === 'Note de frais'): ?><span class="badge" style="background:#F1ECF8;color:#5B3A8C">Note de frais</span><?php endif; ?><div class="small mono"><?= h($f['ref']) ?> · <?= dfr($f['date']) ?> · doc <?= h(ltrim((string) $f['resId'], 'N')) ?></div><?= comm($f['commentaire'] ?? '') ?></td>
          <td class="r mono"><?= h($f['ttc']) ?> €<?php if (!empty($f['avoirs'])): ?><div class="small">net après avoir <?= h(implode(', ', array_column($f['avoirs'], 'ref'))) ?> · brut <?= h($f['ttcBrut']) ?> €</div><?php endif; ?></td>
          <td><?php foreach ($f['paiements'] ?? [] as $p): ?><div><?= h($p['libelle']) ?></div><div class="small mono"><?= dfr($p['date']) ?> · <?= mt($p) ?></div><?php endforeach; ?></td>
          <td><?php $m = $f['paiements'][0]['methode'] ?? '';
            $cl = preg_match('/valider|confirmer|inférieur/i', $m) ? 'warn' : (preg_match('/référence/i', $m) ? 'ok' : 'info'); ?>
            <span class="badge <?= $cl ?>"><?= h($m) ?></span><?php if (!empty($f['ecart'])): ?><div class="small" style="color:var(--warn)">écart <?= h($f['ecart']) ?> €</div><?php endif; ?></td>
          <td class="statut-z"><?php if ($maj && isset($dansPlan[$cd])): ?><span class="todo">À passer en « <?= h($maj['propose']) ?> »</span><div class="small">actuel : <?= h($maj['actuel']) ?> · <a href="#" class="vers-maj">à cocher dans « Mise à jour Zeendoc »</a></div>
              <?php elseif ($maj && $estExclu((string) $f['fournisseur'])): ?><span class="small" style="font-weight:600">Non mis à jour automatiquement</span><div class="small">frais bancaires exclus · à passer à la main dans Zeendoc</div>
              <?php elseif ($maj): ?><span class="todo">À valider d'abord</span><div class="small">actuel : <?= h($maj['actuel']) ?> · voir l'onglet « À traiter »</div>
              <?php elseif (($f['source'] ?? '') === 'Note de frais'): ?><span class="done">Justifié</span><div class="small">ticket CB pro</div>
              <?php elseif (($f['source'] ?? '') === 'Note de frais avancée' || ($f['source'] ?? '') === 'Indemnités km'): ?><span class="done">Remboursement</span><div class="small"><?= h($f['statut']) ?></div><?php else: ?><span class="done"><?= h($f['statut']) ?></span><div class="small">déjà à jour</div><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div></div>

    <div class="panel" id="p2">
      <div class="grid">
      <?php foreach ($aTraiter as $i): ?>
        <article class="issue"<?= !empty($i['pair']) ? ' id="prop-' . h($i['doc']['resId']) . '"' : '' ?>><div class="h"><span class="badge <?= $i['c'] ?>"><?= h($i['k']) ?></span><span class="mono"><b><?= h($i['m']) ?> €</b></span></div>
          <?php if (!empty($i['pair'])): $op = $i['op']; $doc = $i['doc']; ?>
            <div class="pair">
              <div class="side"><div class="small"><?= count($i['ops']) > 1 ? count($i['ops']) . ' lignes du relevé' : 'Ligne du relevé' ?></div>
                <?php foreach ($i['ops'] as $o2): ?><b><?= h($o2['libelle']) ?></b><div class="small mono"><?= dfr($o2['date']) ?> · <?= mt($o2) ?></div><?php endforeach; ?></div>
              <div class="side"><div class="small"><?= h($doc['source'] ?? 'Document') ?> proposée</div><b><?= h($doc['fournisseur']) ?></b><div class="small mono"><?= h($doc['ref'] ?: '—') ?> · <?= dfr($doc['date']) ?> · <?= h($doc['ttc']) ?> €</div><?= comm($doc['commentaire'] ?? '') ?>
                <?php if ($i['lien']): ?><a href="<?= h($i['lien']) ?>" target="_blank" rel="noopener">Ouvrir le document dans Zeendoc ↗</a>
                <?php else: ?><span class="small">Doc n° <?= h(ltrim((string) $doc['resId'], 'N')) ?> (lien à configurer)</span><?php endif; ?></div>
            </div>
            <div class="small"><?= h($op['methode']) ?></div>
            <div class="decide" data-cle="<?= h($i['cle']) ?>">
              <label class="chk"><input type="checkbox" class="valider"> <?= count($i['ops']) > 1 ? 'Ces paiements correspondent bien à ce document' : "C'est bien ce document" ?></label>
              <button type="button" class="btn2 rejeter">Ce n'est pas lui</button>
              <span class="etat small" aria-live="polite"></span>
            </div>
          <?php else: ?>
          <b><?= h($i['t']) ?></b><div class="small" style="font-size:13px"><?= h($i['d']) ?></div><?= comm($i['comm'] ?? '') ?>
          <?php if (!empty($i['lien'])): ?><div class="small" style="margin-top:6px"><a href="<?= h($i['lien']) ?>" target="_blank" rel="noopener">Ouvrir dans Zeendoc ↗</a></div><?php endif; ?>
          <?= !empty($i['resoudre']) ? $boutonsDoc($i['resoudre']) : '' ?>
          <?php endif; ?></article>
      <?php endforeach; ?>
      <?php if (!$aTraiter): ?><div class="empty">Rien à traiter.</div><?php endif; ?>
      </div>
      <form method="post" class="barre-valid" style="position:sticky;bottom:0;z-index:5;padding:12px 16px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;background:#FFFFFF;border-top:1px solid var(--line);box-shadow:0 -4px 12px rgba(21,32,43,.08);border-radius:0 0 10px 10px">
        <span class="small" style="font-size:13px"><b class="nbvalid">0</b> validation(s) cochée(s) · elles passeront dans « Mise à jour Zeendoc »</span>
        <input type="hidden" name="du" value="<?= h($du) ?>"><input type="hidden" name="au" value="<?= h($au) ?>">
        <input type="hidden" name="reprendre" value="1"><?php if (!empty($_POST['ndf'])): ?><input type="hidden" name="ndf" value="1"><?php endif; ?>
        <input type="hidden" name="onglet" value="p5"><input type="hidden" name="vue" value="<?= h($cote) ?>">
        <button class="btn" type="submit">Appliquer mes validations</button>
      </form>
    </div>

    <div class="panel" id="p3">
      <?php if (!$cli): ?><div class="jm-barre">
        <button type="button" class="btn" id="jm-go">📧 Chercher les justificatifs dans Outlook</button>
        <span class="small" id="jm-etat" aria-live="polite">Fouille tes mails (Outlook sur ce PC) pour retrouver les factures PDF de ces débits. Rien n'est déposé sans ta validation.</span>
      </div><?php endif; ?>
      <div class="jm-barre" id="barre-sel" hidden><b id="nb-sel">0</b> <span class="small">débit(s) coché(s) ·</span>
        <button type="button" class="btn2" id="classer-sel">Classer sans suite la sélection…</button>
        <span class="small">même motif et même explication pour tous</span></div>
      <div class="scroll"><table style="min-width:980px">
      <thead><tr><th style="width:40px"><input type="checkbox" id="tout-debits" aria-label="Tout cocher / tout décocher" title="Tout cocher / tout décocher" style="width:18px;height:18px"></th><th>Date</th><th>Libellé bancaire</th><th>Type</th><th class="r">Montant</th><th>Documents possibles</th></tr></thead><tbody>
      <?php foreach ($res['debits_sans_facture'] as $iDeb => $o): ?>
        <tr class="jm-debit" data-debit="<?= h(json_encode(['id' => (string) $iDeb, 'date' => $o['date'], 'montant' => $o['montant'], 'libelle' => $o['libelle']], JSON_UNESCAPED_UNICODE)) ?>"><td><input type="checkbox" class="coche-debit" aria-label="Sélectionner <?= h($o['libelle']) ?> du <?= dfr($o['date']) ?>" style="width:18px;height:18px"></td><td class="mono small"><?= dfr($o['date']) ?></td><td><b><?= h($o['libelle']) ?></b></td><td class="small"><?= h($o['type']) ?></td><td class="r mono"><?= mt($o) ?></td>
          <td><?php if (empty($o['suggestions'])): ?><span class="small">Aucun document proche</span><?php endif; ?>
          <?php foreach ($o['suggestions'] ?? [] as $sg): $lien = lien_doc((string) $sg['resId'], $modCfg); ?>
            <div class="sugg">
              <div><b><?= h($sg['fournisseur']) ?></b> <span class="small">· <?= h($sg['source']) ?> · <?= dfr($sg['date']) ?> · <span class="mono"><?= h($sg['ttc']) ?> €</span> · <?= h($sg['raison']) ?></span><?= comm($sg['commentaire'] ?? '') ?>
                <?php if ($lien): ?> · <a href="<?= h($lien) ?>" target="_blank" rel="noopener">Ouvrir ↗</a><?php endif; ?></div>
              <div class="decide" data-cle="<?= h($sg['cle']) ?>">
                <label class="chk"><input type="checkbox" class="valider"> C'est bien ce document</label>
                <button type="button" class="btn2 rejeter">Ce n'est pas lui</button>
                <span class="etat small" aria-live="polite"></span>
              </div>
            </div>
          <?php endforeach; ?><div class="jm-mails"></div>
          <div class="actions-doc"><button type="button" class="btn2 classer-debit" data-debit="<?= h(json_encode(['cle' => $o['cleClasse'] ?? '', 'libelle' => $o['libelle'], 'montant' => $o['montant'], 'date' => $o['date']], JSON_UNESCAPED_UNICODE)) ?>">Classer sans suite…</button></div></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <form method="post" class="barre-valid" style="position:sticky;bottom:0;z-index:5;padding:12px 16px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;background:#FFFFFF;border-top:1px solid var(--line);box-shadow:0 -4px 12px rgba(21,32,43,.08);border-radius:0 0 10px 10px">
        <span class="small" style="font-size:13px"><b class="nbvalid">0</b> validation(s) cochée(s) · elles passeront dans « Mise à jour Zeendoc »</span>
        <input type="hidden" name="du" value="<?= h($du) ?>"><input type="hidden" name="au" value="<?= h($au) ?>">
        <input type="hidden" name="reprendre" value="1"><?php if (!empty($_POST['ndf'])): ?><input type="hidden" name="ndf" value="1"><?php endif; ?>
        <input type="hidden" name="onglet" value="p5"><input type="hidden" name="vue" value="<?= h($cote) ?>">
        <button class="btn" type="submit">Appliquer mes validations</button>
      </form>
    </div>

    <div class="panel" id="p5">
      <div class="small" style="padding:12px 16px;font-size:13px;line-height:1.5">Seuls les rapprochements <b>sûrs</b> (référence, montant + fournisseur) ou <b>validés par vous</b> sont proposés. <?php if ($listeExclus): ?>Les frais <?= h(implode(', ', $listeExclus)) ?> sont exclus.<?php endif; ?>
        Chaque écriture est journalisée (ecritures-journal.jsonl) et relue dans Zeendoc.</div>
      <div class="scroll"><table style="min-width:1080px">
      <thead><tr><th style="width:44px"><input type="checkbox" id="toutmaj" aria-label="Tout cocher / tout décocher" title="Tout cocher / tout décocher" style="width:18px;height:18px"></th><th>Document</th><th>Statut du règlement</th><th>Date de paiement</th><th>Référence bancaire</th><th>Résultat</th></tr></thead><tbody>
      <?php foreach ($plan as $k => $m): ?>
        <tr class="maj" data-maj="<?= h(json_encode($m, JSON_UNESCAPED_UNICODE)) ?>">
          <td><input type="checkbox" class="choix" id="maj<?= $k ?>" aria-label="Sélectionner <?= h($m['libelle']) ?>" style="width:18px;height:18px"></td>
          <td><label for="maj<?= $k ?>"><b><?= h($m['libelle']) ?></b></label><div class="small"><?= h($m['source']) ?> · doc <?= h($m['resId']) ?> · <?= h($m['methode']) ?>
            <?php $l = lien_doc(($m['collId'] === $collNdf ? 'N' : '') . $m['resId'], $modCfg); if ($l): ?> · <a href="<?= h($l) ?>" target="_blank" rel="noopener">Ouvrir ↗</a><?php endif; ?></div></td>
          <td><span class="small"><?= h($m['avant']['statut'] ?: '(vide)') ?> →</span> <b><?= h($m['statut']) ?></b></td>
          <td class="mono"><span class="small"><?= h($m['avant']['datePaiement'] ? dfr($m['avant']['datePaiement']) : '—') ?> →</span> <?= dfr($m['datePaiement']) ?></td>
          <td class="mono"><span class="small"><?= h($m['avant']['refBancaire'] ?: '—') ?> →</span> <?= h($m['refBancaire'] ?: '—') ?></td>
          <td class="resultat small" aria-live="polite"></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$plan): ?><tr><td colspan="6" class="empty">Rien à mettre à jour : tout est déjà à jour, ou il reste des rapprochements à valider.</td></tr><?php endif; ?>
      </tbody></table></div>
      <div style="padding:12px 16px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:flex-end">
        <span class="small" id="selmaj">0 ligne sélectionnée</span>
        <button class="btn" type="button" id="ecrire" disabled>Mettre à jour Zeendoc</button>
      </div>
    </div>

    <?php if (!$cli): ?>
    <div class="panel" id="p7">
      <div class="small" style="padding:12px 16px;font-size:13px;line-height:1.5">
        Un débit bancaire = plusieurs factures, d'après le détail fourni par <?= h(implode(', ', $pgInfo['sources'] ?? []) ?: 'les sources configurées') ?>.
        Chaque facture est <b>relue dans Zeendoc</b> au moment d'écrire : champ vide → écrit · identique → rien · différent → <b>conflit</b>, rien n'est écrit sur la facture tant que vous n'avez pas choisi ·
        référence bancaire d'un autre paiement → <b>double paiement</b>, facture bloquée.
        Les <b>remboursements collaborateurs</b> (virement au collaborateur ↔ IK ou notes de frais non payées, sans limite d'ancienneté) sont toujours des propositions à valider.
        <?php if (!$droits['ok']): ?><div class="err" style="margin-top:8px">✋ Écriture indisponible : <?= h($droits['message']) ?></div><?php endif; ?>
        <?php foreach ($pgInfo['anomalies'] ?? [] as $an): ?><div class="badge warn" style="display:block;margin-top:6px;border-radius:6px;font-weight:500">⚠ <?= h($an['source']) ?> : <?= h($an['message']) ?></div><?php endforeach; ?>
        <?php foreach ($pgInfo['ambigus'] ?? [] as $am): ?><div class="badge warn" style="display:block;margin-top:6px;border-radius:6px;font-weight:500">⚠ Débit <?= h($am['debit']) ?> : plusieurs paiements possibles (<?= h(implode(', ', $am['transactions'])) ?>), non rapproché automatiquement.</div><?php endforeach; ?>
      </div>
      <div class="grid" style="grid-template-columns:1fr">
      <?php foreach ($lotsATraiter as $lot): [$clEtat, $libEtat] = $etatsLot[$lot['etat']] ?? ['info', $lot['etat']];
        $nbConflits = 0; $nbBloques = 0; $nbAEcrire = 0;
        foreach ($lot['factures'] as $f) { $dz = rg_decider($f['comparaison']); $nbConflits += $dz['etat'] === 'conflit'; $nbBloques += $dz['etat'] === 'bloque'; $nbAEcrire += $dz['etat'] === 'a_ecrire'; } ?>
        <article class="issue lot" data-cle="<?= h($lot['cle']) ?>">
          <div class="h"><span><?php if (($lot['type'] ?? '') === 'remboursement'): ?><span class="badge warn">À valider</span> <span class="badge info">Remboursement · <?= count($lot['factures']) ?> pièce(s)</span>
            <?php else: ?><span class="badge <?= $clEtat ?>"><?= h($libEtat) ?></span> <span class="badge info"><?= count($lot['factures']) ?> facture(s)</span><?php endif; ?>
            <?php if (($lot['type'] ?? '') === 'remboursement' && $lot['etat'] === 'ecart'): ?><span class="badge <?= $clEtat ?>"><?= h($libEtat) ?></span><?php endif; ?>
            <?php if ($nbBloques): ?><span class="badge bad"><?= $nbBloques ?> double(s) paiement(s)</span><?php endif; ?>
            <?php if ($nbConflits): ?><span class="badge warn"><?= $nbConflits ?> conflit(s)</span><?php endif; ?></span>
            <span class="mono"><b><?= h($lot['debit']['montant']) ?> €</b></span></div>
          <div><b><?= h($lot['debit']['libelle']) ?></b> <span class="small mono">· débit du <?= dfr($lot['debit']['date']) ?> · réf. <?= h($lot['debit']['ref']) ?></span></div>
          <div class="small"><?= h($lot['source']) ?> du <?= dfr($lot['paiement']['date']) ?> · <?= h($lot['paiement']['beneficiaire']) ?> · transaction <span class="mono"><?= h($lot['transaction']) ?></span> · trouvé par <?= h($lot['methode']) ?></div>
          <?php if ($lot['manquantes']): ?><div class="err" style="padding:8px 12px">Introuvable(s) dans Zeendoc : <b class="mono"><?= h(implode(', ', $lot['manquantes'])) ?></b> — à indexer ou à corriger avant d'écrire le lot.</div><?php endif; ?>
          <?php if ($lot['etat'] === 'ecart'): ?><div class="badge warn" style="display:block;border-radius:6px">Somme des factures <?= h($lot['total']) ?> € ≠ débit <?= h($lot['debit']['montant']) ?> € · écart <?= h($lot['ecart']) ?> €</div><?php endif; ?>
          <?php if (!empty($lot['alternatives'])): ?><div class="small" style="color:var(--warn)">Autre(s) combinaison(s) possible(s), la plus ancienne est proposée : <span class="mono"><?= h(implode(' · ', $lot['alternatives'])) ?></span></div><?php endif; ?>
          <div class="scroll"><table style="min-width:900px">
            <thead><tr><th>Facture</th><th class="r">TTC</th><th>Date de paiement</th><th>Référence bancaire</th><th>Statut</th><th>Décision</th></tr></thead><tbody>
            <?php foreach ($lot['factures'] as $f): $cmp = $f['comparaison']; $dz = rg_decider($cmp); $lien = lien_doc((string) $f['resId'], $modCfg); ?>
              <tr data-res="<?= h(ltrim((string) $f['resId'], 'N')) ?>">
                <td><b><?= h($f['ref'] !== '' ? $f['ref'] : 'doc ' . ltrim((string) $f['resId'], 'N')) ?></b><div class="small"><?= h($f['fournisseur']) ?> · <?= dfr($f['date']) ?><?php if ($lien): ?> · <a href="<?= h($lien) ?>" target="_blank" rel="noopener">Ouvrir ↗</a><?php endif; ?></div>
                  <?= comm($f['commentaire'] ?? '') ?><?php if ($f['plusieurs']): ?><div class="small" style="color:var(--warn)"><?= (int) $f['plusieurs'] ?> documents portent ce n° : le premier est retenu</div><?php endif; ?></td>
                <td class="r mono"><?= h($f['ttc']) ?> €</td>
                <?php foreach (['datePaiement', 'refBancaire', 'statut'] as $champ): $x = $cmp['champs'][$champ]; [$clC, $libC] = $casChamp[$x['cas']];
                  $aff = fn($v) => $champ === 'datePaiement' && $v !== '' ? dfr($v) : ($v === '' ? '(vide)' : $v); ?>
                  <td class="small"><span class="badge <?= $clC ?>"><?= h($libC) ?></span>
                    <?php if ($x['cas'] === 'conflit'): ?><div>Zeendoc : <b class="mono"><?= h($aff($x['zeendoc'])) ?></b></div><div>Banque : <b class="mono"><?= h($aff($x['banque'])) ?></b></div>
                      <?php if (!$cmp['doublePaiement']): ?><select class="choix-conflit" data-champ="<?= $champ ?>" aria-label="Choix pour <?= h(RG_LIBELLES[$champ]) ?> de <?= h($f['ref']) ?>" style="margin-top:4px;max-width:100%"><option value="">Choisir…</option><option value="zeendoc">Garder Zeendoc</option><option value="banque">Prendre la banque</option></select><?php endif; ?>
                    <?php else: ?><div class="mono"><?= h($aff($x['cas'] === 'vide' ? $x['banque'] : $x['zeendoc'])) ?></div><?php endif; ?></td>
                <?php endforeach; ?>
                <td class="small resultat" aria-live="polite"><?php if ($dz['etat'] === 'bloque'): ?><b style="color:var(--bad)">Bloquée</b><div><?= h($dz['motif']) ?></div>
                  <?php elseif ($dz['etat'] === 'conflit'): ?><b style="color:var(--warn)">À trancher</b>
                  <?php elseif ($dz['etat'] === 'a_jour'): ?><span class="done">Déjà à jour</span>
                  <?php else: ?>Sera écrite<?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$lot['factures']): ?><tr><td colspan="6" class="empty">Aucune facture de ce paiement n'est dans Zeendoc.</td></tr><?php endif; ?>
            </tbody></table></div>
          <div class="lot-actions">
            <?php if ($nbConflits): ?><label class="small">Conflits, pour tout le lot :
              <select class="choix-lot"><option value="">au cas par cas</option><option value="zeendoc">garder Zeendoc partout</option><option value="banque">prendre la banque partout</option></select></label><?php endif; ?>
            <button type="button" class="btn ecrire-lot" <?= ($droits['ok'] && ($nbAEcrire || $nbConflits)) ? '' : 'disabled' ?>>Valider et écrire ce lot</button>
            <?php if (!empty($lot['rejets'])): ?><button type="button" class="btn2 ecarter-remb" data-rejets="<?= h(json_encode($lot['rejets'], JSON_UNESCAPED_UNICODE)) ?>">Écarter cette proposition</button><?php endif; ?>
            <span class="etat small" aria-live="polite"><?= !($nbAEcrire || $nbConflits) ? 'Rien à écrire sur ce lot.' : '' ?></span>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if (!$lots): ?><div class="empty">Aucun paiement groupé sur la période<?= ($pgInfo['sources'] ?? []) ? '' : ' (aucune source de détail configurée)' ?>.</div>
      <?php elseif (!$lotsATraiter): ?><div class="empty">Tous les paiements groupés de la période sont à jour dans Zeendoc. 👌</div><?php endif; ?>
      <?php if ($lotsAJour): ?>
      <details class="card" style="padding:0">
        <summary style="padding:12px 16px;cursor:pointer"><b><?= count($lotsAJour) ?> lot(s) déjà à jour dans Zeendoc</b> <span class="small">· rien à faire, affichés pour mémoire</span></summary>
        <div class="scroll"><table style="min-width:760px"><thead><tr><th>Débit</th><th class="r">Montant</th><th>Factures</th><th>Preuve</th></tr></thead><tbody>
        <?php foreach ($lotsAJour as $lot): ?>
          <tr><td><b><?= h($lot['debit']['libelle']) ?></b><div class="small mono"><?= dfr($lot['debit']['date']) ?> · réf. <?= h($lot['debit']['ref']) ?></div></td>
            <td class="r mono"><?= h($lot['debit']['montant']) ?> €</td>
            <td class="small mono"><?= h(implode(', ', array_column($lot['factures'], 'ref'))) ?></td>
            <td class="small mono"><?= h($lot['source'] . ' ' . $lot['transaction']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
      </details>
      <?php endif; ?>
      </div>
    </div>

    <div class="panel" id="p10">
      <div class="small" style="padding:12px 16px;font-size:13px;line-height:1.5">Indemnités kilométriques et notes de frais avancées <b>sans statut « Payé » ni référence bancaire</b>, toutes périodes confondues, de la plus ancienne à la plus récente.
        Rapport également disponible dans Claude : outil <span class="mono">zeendoc_remboursements_en_attente</span>.</div>
      <div class="scroll"><table style="min-width:820px">
        <thead><tr><th>Pièce</th><th>Date</th><th class="r">TTC</th><th>Collaborateur</th><th>Constat</th></tr></thead><tbody>
        <?php foreach ($nonRemboursees as $f): $lien = lien_doc((string) $f['resId'], $modCfg); ?>
          <tr><td><b><?= h($f['ref'] !== '' ? $f['ref'] : $f['fournisseur']) ?></b><div class="small"><?= h($f['source']) ?> · doc <?= h(ltrim((string) $f['resId'], 'N')) ?><?php if ($lien): ?> · <a href="<?= h($lien) ?>" target="_blank" rel="noopener">Ouvrir ↗</a><?php endif; ?></div><?= comm($f['commentaire'] ?? '') ?></td>
            <td class="mono small"><?= dfr($f['date']) ?></td><td class="r mono"><?= h($f['ttc']) ?> €</td><td class="small"><?= h($f['collaborateur'] ?? '') ?></td>
            <td class="small"><?php if (!empty($f['propose'])): ?><span class="badge warn">Proposée</span> débit du <?= dfr($f['propose']['date']) ?> · <?= h($f['propose']['montant']) ?> €, à valider dans « Paiements groupés et remboursements »
              <?php else: ?>Aucun virement correspondant sur ce relevé. Remboursement à faire, ou versé hors de la période analysée.<?php endif; ?><?= $boutonsDoc($f) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$nonRemboursees): ?><tr><td colspan="5" class="empty">Toutes les IK et notes de frais avancées sont remboursées. 👌</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>

    <div class="panel" id="p8">
      <div class="small" style="padding:12px 16px;font-size:13px;line-height:1.5">Factures marquées payées (statut « Payé » ou date de paiement) <b>sans référence bancaire</b> : paiement déclaré, pas prouvé.
        Elles disparaissent d'ici dès qu'un rapprochement écrit leur référence bancaire. Rapport également disponible dans Claude : outil <span class="mono">zeendoc_paiements_non_prouves</span>.</div>
      <div class="scroll"><table style="min-width:820px">
        <thead><tr><th>Facture</th><th>Date</th><th class="r">TTC</th><th>Statut</th><th>Date de paiement</th><th>Constat</th></tr></thead><tbody>
        <?php foreach ($nonProuves as $f): $lien = lien_doc((string) $f['resId'], $modCfg); ?>
          <tr><td><b><?= h($f['fournisseur']) ?></b><div class="small mono"><?= h($f['ref']) ?><?php if ($lien): ?> · <a href="<?= h($lien) ?>" target="_blank" rel="noopener">Ouvrir ↗</a><?php endif; ?></div><?= comm($f['commentaire'] ?? '') ?></td>
            <td class="mono small"><?= dfr($f['date']) ?></td><td class="r mono"><?= h($f['ttc']) ?> €</td><td class="small"><?= h($f['statut']) ?></td>
            <td class="mono small"><?= $f['datePaiement'] ? dfr($f['datePaiement']) : '—' ?></td><td class="small"><?= h($f['raison']) ?><?= $boutonsDoc($f) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$nonProuves): ?><tr><td colspan="6" class="empty">Toutes les factures payées de la période ont une référence bancaire. 👌</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
    <?php endif; ?>

    <div class="panel" id="p9">
      <div class="small" style="padding:12px 16px;font-size:13px">Éléments retirés des tableaux par vous (porte de sortie exceptionnelle). Rien n'a été écrit dans Zeendoc. « Rétablir » les fait réapparaître au prochain rapprochement.</div>
      <div class="scroll"><table style="min-width:820px">
        <thead><tr><th>Élément</th><th class="r">Montant</th><th>Motif</th><th>Classé le</th><th></th></tr></thead><tbody>
        <?php foreach ($res['classes_sans_suite'] ?? [] as $x): $estDebit = $x['type'] === 'debit'; ?>
          <tr><td><span class="badge <?= $estDebit ? 'info' : 'warn' ?>"><?= $estDebit ? 'Débit' : h($x['source'] ?? 'Document') ?></span>
              <b><?= h($estDebit ? $x['libelle'] : trim(($x['fournisseur'] ?? '') . ' ' . ($x['ref'] ?? ''))) ?></b><div class="small mono"><?= dfr($x['date']) ?></div></td>
            <td class="r mono"><?= h($estDebit ? $x['montant'] : $x['ttc']) ?> €</td>
            <td class="small"><b><?= h($x['motif'] ?? '') ?></b><div><?= h($x['texte'] ?? '') ?></div></td>
            <td class="small mono"><?= !empty($x['quand']) ? h(date('d/m/Y', strtotime((string) $x['quand']))) : '' ?></td>
            <td class="r"><button type="button" class="btn2 retablir" data-cle="<?= h($x['cle']) ?>">Rétablir</button></td></tr>
        <?php endforeach; ?>
        <?php if (empty($res['classes_sans_suite'])): ?><tr><td colspan="5" class="empty">Rien n'a été classé sans suite.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>

    <div class="panel" id="p4"><div class="scroll"><table style="min-width:700px">
      <thead><tr><th>Facture</th><th>Échéance</th><th class="r">TTC</th><th>Statut</th></tr></thead><tbody>
      <?php foreach ($res['non_echues'] ?? [] as $f): ?>
        <tr><td><b><?= h($f['fournisseur']) ?></b><div class="small mono"><?= h($f['ref']) ?></div></td><td class="mono"><?= dfr($f['echeance']) ?></td><td class="r mono"><?= h($f['ttc']) ?> €</td><td class="small"><?= h($f['statut']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div></div>

    <?php if ($cli):
      $rel = $res['echues_non_reglees'];
      usort($rel, fn($a, $b) => strcmp(ech_eff($a), ech_eff($b)));
      $totRel = array_sum(array_map(fn($x) => rb_cents($x['ttc']), $rel)); ?>
    <div class="panel" id="p6"><div class="scroll"><table style="min-width:820px">
      <thead><tr><th>Client</th><th>Facture</th><th>Échéance</th><th class="r">Retard</th><th class="r">TTC</th><th>Statut Zeendoc</th></tr></thead><tbody>
      <?php foreach ($rel as $f): $j = retard($f); $lien = lien_doc((string) $f['resId'], $modCfg); ?>
        <tr><td><b><?= h($f['fournisseur']) ?></b></td>
          <td class="mono small"><?= h($f['ref']) ?> · <?= dfr($f['date']) ?><?php if ($lien): ?> · <a href="<?= h($lien) ?>" target="_blank" rel="noopener">Ouvrir ↗</a><?php endif; ?></td>
          <td class="mono"><?= dfr(ech_eff($f)) ?><?php if (empty($f['echeance'])): ?><div class="small">estimée</div><?php endif; ?></td>
          <td class="r"><span class="badge <?= $j > 60 ? 'bad' : ($j > 30 ? 'warn' : 'info') ?>"><?= $j ?> j</span></td>
          <td class="r mono"><?= h($f['ttc']) ?> €<?php if (!empty($f['avoirs'])): ?><div class="small">net · brut <?= h($f['ttcBrut']) ?> € − avoir <?= h(implode(', ', array_column($f['avoirs'], 'ref'))) ?></div><?php endif; ?></td><td class="small"><?= h($f['statut']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$rel): ?><tr><td colspan="6" class="empty">Aucune facture échue impayée sur la période. 👌</td></tr><?php else: ?>
        <tr><td colspan="4" class="r"><b>Total à encaisser</b></td><td class="r mono"><b><?= h(rb_eur($totRel)) ?> €</b></td><td></td></tr><?php endif; ?>
      </tbody></table></div>
      <?php $sold = $res['soldees_par_avoir'] ?? []; $libres = $res['avoirs_non_affectes'] ?? []; if ($sold || $libres): ?>
      <div style="padding:12px 16px;border-top:1px solid var(--line)">
        <?php if ($sold): ?><b>Soldées par avoir (<?= count($sold) ?>)</b> <span class="small">· aucun encaissement attendu</span>
          <?php foreach ($sold as $x): ?><div class="small" style="margin-top:4px"><b><?= h($x['fournisseur']) ?></b> · <span class="mono"><?= h($x['ref']) ?> · <?= h($x['ttc']) ?> €</span> ← <?php foreach ($x['avoirs'] as $av): ?><span class="mono"><?= h($av['ref']) ?> (<?= h($av['montant']) ?> €)</span> <i><?= h($av['methode']) ?></i> <?php endforeach; ?></div><?php endforeach; ?>
        <?php endif; ?>
        <?php if ($libres): ?><div style="margin-top:<?= $sold ? '12px' : '0' ?>"><b>Avoirs non affectés (<?= count($libres) ?>)</b> <span class="small">· à imputer à la main ou à rembourser</span></div>
          <?php foreach ($libres as $x): ?><div class="small" style="margin-top:4px"><b><?= h($x['fournisseur']) ?></b> · <span class="mono"><?= h($x['ref']) ?> · <?= dfr($x['date']) ?> · <?= h($x['montant']) ?> €</span> · <?= h($x['raison']) ?></div><?php endforeach; ?>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="small" style="padding:12px 16px">Factures dont l'échéance est passée (échéance absente = date de facture + 30 j) et sans encaissement trouvé sur le relevé. Vérifie avant de relancer : un client a pu payer par chèque ou sur un autre compte.</div>
    </div>
    <?php endif; ?>

    <div class="foot">
      <span class="small" style="font-size:13px"><?= count($plan) ?> mise(s) à jour proposée(s) · rien n'est écrit sans votre validation dans l'onglet « Mise à jour Zeendoc »</span>
    </div>
  </section>
<?php endif; ?>
<?php if ($histo): $icones = ['run' => '🔄', 'ecriture' => '✏️', 'depot' => '📎', 'decision' => '☑️']; ?>
  <details class="card" id="historique" style="padding:0">
    <summary style="padding:14px 16px;cursor:pointer;font-weight:600">Historique du module <span class="small">(<?= count($histo) ?> évènements · sur ce PC)</span></summary>
    <div style="padding:0 16px 8px;display:flex;flex-wrap:wrap;gap:8px" role="group" aria-label="Filtrer l'historique">
      <?php foreach (['tout' => 'Tout', 'run' => 'Rapprochements', 'ecriture' => 'Écritures Zeendoc', 'depot' => 'Justificatifs déposés', 'decision' => 'Décisions'] as $k => $lib): ?>
        <button type="button" class="btn2 h-filtre" data-t="<?= $k ?>" aria-pressed="<?= $k === 'tout' ? 'true' : 'false' ?>"><?= h($lib) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="scroll" style="max-height:480px;overflow:auto"><table style="min-width:760px"><tbody>
      <?php foreach (array_slice($histo, 0, 500) as $ev): ?>
        <tr class="h-ligne" data-t="<?= h($ev['type']) ?>">
          <td class="mono small" style="white-space:nowrap"><?= h(date('d/m/Y H:i', strtotime((string) $ev['quand']))) ?></td>
          <td><?= $icones[$ev['type']] ?? '' ?> <b><?= h($ev['titre']) ?></b><?= $ev['ok'] ? '' : ' <span class="badge bad">échec</span>' ?><div class="small"><?= h($ev['detail']) ?><?php if (!empty($ev['lien'])): ?> · <a href="<?= h($ev['lien']) ?>" target="_blank" rel="noopener">Ouvrir ↗</a><?php endif; ?></div></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </details>
<?php endif; ?>
<dialog id="resoudre" aria-labelledby="rs-titre">
  <form method="dialog" class="rs" onsubmit="return false">
    <div class="rs-h"><div><b id="rs-titre">Résoudre</b><div class="small" id="rs-doc"></div><div class="small" style="color:var(--mut);opacity:.8">✥ glisser le titre pour déplacer la fenêtre</div></div><button type="button" class="btn2" id="rs-fermer" aria-label="Fermer">✕</button></div>
    <div id="rs-resolution" style="display:flex;flex-direction:column;gap:10px">
    <label class="small" for="rs-cherche">Lignes du relevé qui règlent ce document (cocher une ou plusieurs)</label>
    <input type="text" id="rs-cherche" placeholder="Chercher : montant, date, libellé, référence…" autocomplete="off">
    <div id="rs-lignes" class="rs-lignes" role="group" aria-label="Lignes du relevé"></div>
    <div id="rs-somme" class="rs-somme" aria-live="polite"></div>
    <div id="rs-alerte" class="small rs-alerte" role="alert" hidden></div>
    <div class="rs-grille">
      <label class="small">Motif de l'écart
        <select id="rs-motif">
          <option value="">Pas d'écart / sans motif</option>
          <option value="compensation">Compensation avec une autre facture</option>
          <option value="avoir">Avoir déduit</option>
          <option value="escompte">Escompte / remise</option>
          <option value="hors_releve">Payé hors de ce compte (chèque, autre banque…)</option>
          <option value="autre">Autre</option>
        </select></label>
      <label class="small" id="rs-date-l" hidden>Date du paiement <input type="date" id="rs-date"></label>
      <label class="small" style="grid-column:1/-1">Précision (écrite dans la référence bancaire et gardée dans le journal)
        <input type="text" id="rs-texte" maxlength="80" placeholder="ex. FA152502 payée deux fois"></label>
    </div>
    <label class="small rs-chk"><input type="checkbox" id="rs-remplacer"> Remplacer les valeurs déjà présentes dans Zeendoc (conflit ou autre référence bancaire)</label>
    <div class="rs-apercu small" id="rs-apercu"></div>
    <div class="rs-pied"><span class="small" id="rs-etat" aria-live="polite"></span><button type="button" class="btn" id="rs-ecrire">Écrire dans Zeendoc</button></div>
    </div>
    <details id="rs-sortie" class="rs-sortie">
      <summary>Porte de sortie : classer sans suite <span class="small">(exceptionnel — rien n'est écrit dans Zeendoc)</span></summary>
      <div class="small" style="margin:6px 0">L'élément disparaît des tableaux et des compteurs, mais reste listé dans l'onglet « Classés sans suite », d'où il peut être rétabli.</div>
      <div class="rs-grille">
        <label class="small">Motif
          <select id="cl-motif"><option value="">Choisir…</option><option value="anterieur">Paiement antérieur au relevé (N-1)</option><option value="perdu">Justificatif perdu</option>
            <option value="hors_perimetre">Hors périmètre (payé par un tiers, autre société…)</option><option value="autre">Autre</option></select></label>
        <label class="small">Explication (obligatoire)<input type="text" id="cl-texte" maxlength="300" placeholder="ex. ticket de péage perdu, payé en décembre 2024"></label>
      </div>
      <div class="rs-pied"><span class="small" id="cl-etat" aria-live="polite"></span><button type="button" class="btn" id="cl-classer" disabled>Classer sans suite</button></div>
    </details>
  </form>
</dialog>
</main>
<script>
(function () {
  var b = document.querySelectorAll('.h-filtre'); if (!b.length) return;
  b.forEach(function (x) { x.addEventListener('click', function () {
    b.forEach(function (y) { y.setAttribute('aria-pressed', y === x ? 'true' : 'false'); });
    document.querySelectorAll('.h-ligne').forEach(function (l) { l.style.display = (x.dataset.t === 'tout' || l.dataset.t === x.dataset.t) ? '' : 'none'; });
  }); });
})();
function decider(bloc, action) {
  var envois = bloc.dataset.cle.split('\n').map(function (cle) {
    return fetch(location.pathname, { method: 'POST', body: new URLSearchParams({ action: action, cle: cle }) }).then(function (r) { return r.json(); });
  });
  return Promise.all(envois).then(function () {
    var carte = bloc.closest('.issue, .sugg'), etat = bloc.querySelector('.etat');
    carte.classList.toggle('valide', action === 'valider');
    carte.classList.toggle('rejete', action === 'rejeter');
    etat.textContent = action === 'valider' ? 'Validé : sera rapproché automatiquement la prochaine fois'
      : action === 'rejeter' ? 'Rejeté : ne sera plus proposé (relancer pour chercher un autre débit)' : '';
  }).catch(function () { bloc.querySelector('.etat').textContent = 'Erreur d\'enregistrement'; });
}
document.querySelectorAll('.decide').forEach(function (bloc) {
  bloc.querySelector('.valider').addEventListener('change', function (e) { decider(bloc, e.target.checked ? 'valider' : 'annuler'); });
  bloc.querySelector('.rejeter').addEventListener('click', function () { bloc.querySelector('.valider').checked = false; decider(bloc, 'rejeter'); });
});
(function () {
  var btn = document.getElementById('ecrire'); if (!btn) return;
  var compte = function () {
    var n = document.querySelectorAll('.maj .choix:checked').length;
    document.getElementById('selmaj').textContent = n + ' ligne' + (n > 1 ? 's' : '') + ' sélectionnée' + (n > 1 ? 's' : '');
    btn.disabled = n === 0;
  };
  var tout = document.getElementById('toutmaj');
  // Après écriture : on retire les lignes écrites et on met à jour les compteurs, sans recharger la page
  var nettoyer = function () {
    document.querySelectorAll('.maj').forEach(function (tr) {
      if (tr.querySelector('.choix').disabled && tr.querySelector('.resultat .done')) tr.parentNode.removeChild(tr);
    });
    var reste = document.querySelectorAll('.maj').length;
    var cnt = document.querySelector('.tab[data-p="p5"] .cnt'); if (cnt) cnt.textContent = reste;
    var pied = document.querySelector('.foot .small'); if (pied) pied.textContent = reste + ' mise(s) à jour proposée(s) · rien n\'est écrit sans votre validation dans l\'onglet « Mise à jour Zeendoc »';
    if (!reste) {
      var tb = document.querySelector('#p5 tbody');
      if (tb && !tb.querySelector('.tout-ok')) { var tr = document.createElement('tr'); tr.className = 'tout-ok'; var td = document.createElement('td'); td.colSpan = 6; td.className = 'empty'; td.textContent = '✓ Tout est à jour dans Zeendoc.'; tr.appendChild(td); tb.appendChild(tr); }
    }
    compte(); majTout();
  };
  var majTout = function () {
    var dispo = Array.prototype.slice.call(document.querySelectorAll('.maj .choix:not(:disabled)'));
    var coches = dispo.filter(function (c) { return c.checked; }).length;
    tout.checked = dispo.length > 0 && coches === dispo.length;
    tout.indeterminate = coches > 0 && coches < dispo.length;
  };
  tout.addEventListener('change', function () {
    document.querySelectorAll('.maj .choix:not(:disabled)').forEach(function (c) { c.checked = tout.checked; });
    compte();
  });
  document.querySelectorAll('.maj .choix').forEach(function (c) { c.addEventListener('change', function () { compte(); majTout(); }); });
  btn.addEventListener('click', function () {
    var lignes = Array.prototype.slice.call(document.querySelectorAll('.maj')).filter(function (tr) { return tr.querySelector('.choix').checked; });
    if (!lignes.length) return;
    var serie = 'maj-' + Date.now().toString(36); // ce clic = une série, annulable d'un bloc
    btn.disabled = true;
    var suivant = function (i) {
      if (i >= lignes.length) { compte(); majTout(); setTimeout(nettoyer, 2500); return; }
      var tr = lignes[i], cell = tr.querySelector('.resultat');
      cell.textContent = 'Écriture…';
      fetch(location.pathname, { method: 'POST', body: new URLSearchParams({ action: 'ecrire', maj: tr.dataset.maj, serie: serie }) })
        .then(function (r) { return r.json(); })
        .then(function (r) {
          if (r.ok) {
            tr.querySelector('.choix').checked = false; tr.querySelector('.choix').disabled = true;
            cell.innerHTML = '';
            var ok = document.createElement('span'); ok.className = 'done'; ok.textContent = '✓ Écrit';
            cell.appendChild(ok);
            // Répercute l'écriture dans l'onglet « Rapprochées »
            try {
              var m = JSON.parse(tr.dataset.maj);
              var r1 = document.querySelector('#p1 tr[data-doc="' + m.collId + '-' + m.resId + '"] .statut-z');
              if (r1) { r1.innerHTML = '<span class="done"></span><div class="small">mis à jour à l\'instant</div>'; r1.querySelector('.done').textContent = m.statut; }
            } catch (e) {}
            if (r.relu) { var d = document.createElement('div'); d.textContent = 'Relu : ' + r.relu.statut + ' · ' + r.relu.datePaiement + ' · ' + r.relu.refBancaire; cell.appendChild(d); }
            else { var w = document.createElement('div'); w.textContent = 'Zeendoc réindexe encore : relancez le rapprochement pour contrôler (le document ne doit plus être proposé)'; cell.appendChild(w); }
          } else {
            cell.innerHTML = ''; var e = document.createElement('span'); e.style.color = 'var(--bad)'; e.textContent = '✗ ' + r.erreur; cell.appendChild(e);
          }
        })
        .catch(function () { cell.textContent = 'Erreur réseau'; })
        .then(function () { suivant(i + 1); });
    };
    suivant(0);
  });
})();
// Paiements groupés : un clic écrit tout le lot ; le serveur relit chaque facture et refuse les conflits non tranchés
document.querySelectorAll('.lot').forEach(function (lot) {
  var bouton = lot.querySelector('.ecrire-lot');
  if (!bouton) return;
  var etat = lot.querySelector('.etat');
  // Remboursement écarté : les paires sont mémorisées en rejet, la proposition suivante apparaîtra au prochain rapprochement
  var ecarter = lot.querySelector('.ecarter-remb');
  if (ecarter) ecarter.addEventListener('click', function () {
    ecarter.disabled = true;
    JSON.parse(ecarter.dataset.rejets).reduce(function (suite, cle) {
      return suite.then(function () { return fetch(location.pathname, { method: 'POST', body: new URLSearchParams({ action: 'rejeter', cle: cle }) }); });
    }, Promise.resolve())
      .then(function () { etat.textContent = 'Proposition écartée : relancez le rapprochement pour voir la suivante.'; bouton.disabled = true; })
      .catch(function () { etat.textContent = '⚠ Le module ne répond pas.'; ecarter.disabled = false; });
  });
  var choixLot = lot.querySelector('.choix-lot');
  if (choixLot) choixLot.addEventListener('change', function () {
    lot.querySelectorAll('.choix-conflit').forEach(function (s) { s.value = choixLot.value; });
  });
  bouton.addEventListener('click', function () {
    var choix = {};
    lot.querySelectorAll('tr[data-res]').forEach(function (tr) {
      tr.querySelectorAll('.choix-conflit').forEach(function (s) {
        if (!s.value) return;
        choix[tr.dataset.res] = choix[tr.dataset.res] || {};
        choix[tr.dataset.res][s.dataset.champ] = s.value;
      });
    });
    bouton.disabled = true;
    etat.textContent = 'Écriture en cours…';
    fetch(location.pathname, { method: 'POST', body: new URLSearchParams({ action: 'ecrire_lot', cle: lot.dataset.cle, choix: JSON.stringify(choix) }) })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (!r.ok) { etat.textContent = '⚠ ' + r.erreur; bouton.disabled = false; return; }
        var ecrits = 0, reste = 0;
        r.documents.forEach(function (d) {
          var cell = lot.querySelector('tr[data-res="' + d.resId + '"] .resultat');
          if (!cell) return;
          var dec = d.decision || {};
          if (d.erreur) { cell.innerHTML = ''; cell.appendChild(document.createTextNode('⚠ ' + d.erreur)); reste++; }
          else if (d.ecrit) { cell.innerHTML = '<span class="done">✓ Écrite</span>'; ecrits++; }
          else if (dec.etat === 'a_jour') { cell.innerHTML = '<span class="done">Déjà à jour</span>'; }
          else { cell.textContent = (dec.etat === 'bloque' ? 'Bloquée : ' : 'Non écrite : ') + (dec.motif || ''); reste++; }
        });
        etat.textContent = ecrits + ' facture(s) écrite(s)' + (reste ? ' · ' + reste + ' non écrite(s), voir le détail' : '') + ' · annulable avec « Annuler cette série » (après rechargement).';
        bouton.disabled = reste === 0;
        if (reste === 0) {
          // Lot traité : la carte disparaît (il rejoindra « lots déjà à jour » au prochain affichage)
          lot.style.transition = 'opacity .6s'; lot.style.opacity = '0';
          setTimeout(function () { lot.remove(); var c = document.getElementById('cnt-lots'); if (c) c.textContent = Math.max(0, parseInt(c.textContent, 10) - 1); }, 2500);
        }
      })
      .catch(function () { etat.textContent = '⚠ Le module ne répond pas.'; bouton.disabled = false; });
  });
});
(function () {
  var b = document.getElementById('annuler-serie');
  if (!b) return;
  b.addEventListener('click', function () {
    if (!confirm('Remettre ces documents dans leur état d\'avant la dernière série d\'écritures ?')) return;
    var etat = document.getElementById('annuler-etat');
    b.disabled = true;
    etat.textContent = 'Annulation en cours…';
    fetch(location.pathname, { method: 'POST', body: new URLSearchParams({ action: 'annuler_serie', serie: b.dataset.serie }) })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (!r.ok) { etat.textContent = '⚠ ' + r.erreur; b.disabled = false; return; }
        var ko = r.documents.filter(function (d) { return !d.ok || (d.ignore && d.ignore.length); });
        etat.textContent = (r.documents.length - ko.length) + ' document(s) remis en l\'état' + (ko.length ? ' · ' + ko.length + ' à vérifier : ' + ko.map(function (d) { return d.libelle + ' (' + (d.erreur || d.ignore.join(', ')) + ')'; }).join(' ; ') : '');
      })
      .catch(function () { etat.textContent = '⚠ Le module ne répond pas.'; b.disabled = false; });
  });
})();
document.querySelectorAll('.retablir').forEach(function (b) {
  b.addEventListener('click', function () {
    b.disabled = true;
    fetch(location.pathname, { method: 'POST', body: new URLSearchParams({ action: 'retablir', cote: <?= json_encode($cote) ?>, cle: b.dataset.cle }) })
      .then(function (r) { return r.json(); })
      .then(function (r) { if (r.ok) b.outerHTML = '<span class="small">Rétabli : réapparaîtra au prochain rapprochement</span>'; else { b.disabled = false; alert(r.erreur); } })
      .catch(function () { b.disabled = false; });
  });
});
// « Voir la proposition » : ouvre l'onglet À traiter sur la carte du document
document.querySelectorAll('.voir-prop').forEach(function (b) {
  b.addEventListener('click', function () {
    var t = document.querySelector('.tab[data-p="p2"]'); if (t) t.click();
    var c = document.getElementById('prop-' + b.dataset.res);
    if (!c) return;
    c.scrollIntoView({ block: 'center', behavior: 'smooth' });
    c.classList.add('flash'); setTimeout(function () { c.classList.remove('flash'); }, 2500);
  });
});
// « Résoudre… » : choisir les lignes du relevé et justifier l'écart, puis écrire (mêmes contrôles que les lots)
(function () {
  var dlg = document.getElementById('resoudre');
  if (!dlg || !document.querySelector('.resoudre, .classer-debit')) return;
  var ecritureOk = <?= json_encode((bool) $droits['ok']) ?>;
  var sources = []; // éléments à classer sans suite : [{el, cle, libelle, montant}]
  // Ouverture : toujours au centre, même si la fenêtre avait été déplacée la fois précédente
  var ouvrirFenetre = function () { dlg.classList.remove('deplace'); dlg.style.left = dlg.style.top = ''; dlg.showModal(); };
  var $ = function (id) { return document.getElementById(id); };
  var cote = <?= json_encode($cote) ?>;
  var lignes = null, doc = null, bouton = null, choisies = {};
  var eur = function (c) { return (c / 100).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €'; };
  var fr = function (d) { var p = d.split('-'); return p[2] + '/' + p[1] + '/' + p[0]; };
  var norm = function (t) { return (t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase(); };
  var mots = function (t) { return norm(t).split(/[^A-Z0-9]+/).filter(function (m) { return m.length >= 4 && ['SARL', 'SOCIETE', 'FRANCE', 'GROUPE'].indexOf(m) < 0; }); };
  var autres = function (l) {
    var u = l.utilisee || {}, cur = doc ? String(doc.resId) : '';
    return Object.keys(u).filter(function (id) { return id !== cur; }).map(function (id) { return u[id]; });
  };
  var score = function (l) {
    var s = Math.abs(l.cents - doc.ttcCents) / 100, txt = norm(l.libelle + ' ' + l.info + ' ' + l.ref);
    mots(doc.fournisseur).forEach(function (m) { if (txt.indexOf(m) >= 0) s -= 5000; });
    if (doc.ref && txt.replace(/\s/g, '').indexOf(norm(doc.ref).replace(/\s/g, '')) >= 0) s -= 20000;
    if (l.date < doc.date) s += 3000;
    if (autres(l).length) s += 10000; // déjà utilisée ailleurs : proposée plus bas
    return s;
  };
  var afficher = function () {
    var q = norm($('rs-cherche').value).trim(), liste = lignes.slice();
    if (q) liste = liste.filter(function (l) { return norm(l.date + ' ' + fr(l.date) + ' ' + l.libelle + ' ' + l.info + ' ' + l.ref + ' ' + l.montant).indexOf(q) >= 0; });
    else liste.sort(function (a, b) { return score(a) - score(b); });
    liste = liste.filter(function (l) { return !choisies[l.cle]; }).slice(0, 40);
    var tout = lignes.filter(function (l) { return choisies[l.cle]; }).concat(liste);
    var box = $('rs-lignes'); box.innerHTML = '';
    tout.forEach(function (l) {
      var lab = document.createElement('label'); lab.className = 'rs-ligne' + (choisies[l.cle] ? ' on' : '');
      var cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = !!choisies[l.cle];
      cb.addEventListener('change', function () { if (cb.checked) choisies[l.cle] = l; else delete choisies[l.cle]; afficher(); });
      var d = document.createElement('span'); d.className = 'mono small d'; d.textContent = fr(l.date);
      var t = document.createElement('span'); t.innerHTML = '<b></b><div class="small"></div>';
      t.querySelector('b').textContent = l.libelle; t.querySelector('div').textContent = fr(l.date) + ' · réf. ' + (l.ref || '—') + ' · ' + l.info;
      if (autres(l).length) { var w = document.createElement('div'); w.className = 'small deja'; w.textContent = '⚠ déjà associée à ' + autres(l).join(' ; '); t.appendChild(w); }
      var m = document.createElement('span'); m.className = 'mono'; m.textContent = l.montant + ' €';
      lab.append(cb, d, t, m); box.appendChild(lab);
    });
    if (!tout.length) box.innerHTML = '<div class="empty small">Aucune ligne ne correspond.</div>';
    recalculer();
  };
  var recalculer = function () {
    var sel = Object.keys(choisies).map(function (k) { return choisies[k]; });
    var tot = sel.reduce(function (a, l) { return a + l.cents; }, 0), ecart = doc.ttcCents - tot, motif = $('rs-motif').value;
    $('rs-somme').textContent = sel.length ? sel.length + ' ligne(s) : ' + eur(tot) + ' · TTC ' + eur(doc.ttcCents) + (ecart ? ' · écart ' + eur(ecart) + (motif ? '' : ' → précisez le motif') : ' · montant exact ✓') : 'Aucune ligne choisie.';
    $('rs-somme').style.color = sel.length && ecart && !motif ? 'var(--warn)' : '';
    var dejaPrises = sel.filter(function (l) { return autres(l).length; });
    var al = $('rs-alerte');
    al.hidden = !dejaPrises.length;
    al.textContent = dejaPrises.length ? '⚠ ' + dejaPrises.length + ' ligne(s) choisie(s) justifient déjà un autre document : ' + dejaPrises.map(function (l) { return fr(l.date) + ' ' + l.montant + ' € → ' + autres(l).join(', '); }).join(' ; ') + '. La même dépense serait comptée deux fois : vérifiez qu\u2019il ne s\u2019agit pas d\u2019un doublon.' : '';
    $('rs-date-l').hidden = motif !== 'hors_releve' || sel.length > 0;
    var lib = { compensation: 'compensation', avoir: 'avoir', escompte: 'escompte', hors_releve: 'hors releve', autre: '' }[motif] || '';
    var ref = sel.map(function (l) { return l.ref || l.libelle; }).concat([(lib + ' ' + $('rs-texte').value).trim()]).filter(Boolean).join(' + ');
    var dates = sel.map(function (l) { return l.date; }).sort(), dp = dates.length ? dates[dates.length - 1] : $('rs-date').value;
    $('rs-apercu').textContent = 'Sera écrit : statut « Payé » · date de paiement ' + (dp ? fr(dp) : '—') + ' · référence bancaire « ' + (ref || '—') + ' »';
    $('rs-ecrire').disabled = !ecritureOk || !(sel.length || (motif === 'hors_releve' && $('rs-date').value)) || (sel.length && ecart && !motif);
  };
  var charger = function () {
    if (lignes) return Promise.resolve();
    $('rs-lignes').innerHTML = '<div class="empty small">Chargement du relevé…</div>';
    return fetch(location.pathname, { method: 'POST', body: new URLSearchParams({ action: 'lignes_releve', cote: cote }) })
      .then(function (r) { return r.json(); })
      .then(function (r) { if (!r.ok) throw new Error(r.erreur); lignes = r.lignes; });
  };
  var reinitSortie = function (ouvert) {
    $('cl-motif').value = ''; $('cl-texte').value = ''; $('cl-etat').textContent = ''; $('cl-classer').disabled = true;
    $('rs-sortie').open = ouvert;
  };
  // Sélection de plusieurs débits : classés sans suite d'un coup, avec le même motif
  var coches = function () { return Array.prototype.slice.call(document.querySelectorAll('.coche-debit:checked')); };
  var majSelection = function () {
    var n = coches().length, barre = $('barre-sel'); if (!barre) return;
    barre.hidden = n === 0; $('nb-sel').textContent = n;
    var tout = $('tout-debits'), dispo = document.querySelectorAll('.coche-debit:not(:disabled)');
    if (tout) { tout.checked = n > 0 && n === dispo.length; tout.indeterminate = n > 0 && n < dispo.length; }
  };
  document.querySelectorAll('.coche-debit').forEach(function (c) { c.addEventListener('change', majSelection); });
  if ($('tout-debits')) $('tout-debits').addEventListener('change', function () {
    var on = $('tout-debits').checked;
    document.querySelectorAll('.coche-debit:not(:disabled)').forEach(function (c) { c.checked = on; });
    majSelection();
  });
  if ($('classer-sel')) $('classer-sel').addEventListener('click', function () {
    var tot = 0;
    sources = coches().map(function (c) {
      var b = c.closest('tr').querySelector('.classer-debit'), x = JSON.parse(b.dataset.debit);
      tot += Math.round(parseFloat(x.montant.replace(/\s/g, '').replace(',', '.')) * 100);
      return { el: b, cle: x.cle, libelle: x.libelle + ' du ' + fr(x.date), montant: x.montant };
    });
    if (!sources.length) return;
    $('rs-titre').textContent = 'Classer sans suite : ' + sources.length + ' débit(s) (' + eur(tot) + ')';
    $('rs-doc').textContent = sources.slice(0, 4).map(function (x) { return x.libelle + ' · ' + x.montant + ' €'; }).join(' ; ') + (sources.length > 4 ? ' ; …' : '');
    $('rs-resolution').hidden = true; reinitSortie(true);
    ouvrirFenetre();
  });
  // Débit sans justificatif : seule la porte de sortie est proposée
  document.querySelectorAll('.classer-debit').forEach(function (b) {
    b.addEventListener('click', function () {
      var x = JSON.parse(b.dataset.debit);
      sources = [{ el: b, cle: x.cle, libelle: x.libelle + ' du ' + fr(x.date), montant: x.montant }];
      $('rs-titre').textContent = 'Classer sans suite : ' + x.libelle + ' (' + x.montant + ' €)';
      $('rs-doc').textContent = 'Débit du ' + fr(x.date) + ' sans justificatif.';
      $('rs-resolution').hidden = true; reinitSortie(true);
      ouvrirFenetre();
    });
  });
  document.querySelectorAll('.resoudre').forEach(function (b) {
    b.addEventListener('click', function () {
      bouton = b; doc = JSON.parse(b.dataset.doc); choisies = {};
      sources = [{ el: b, cle: 'doc|' + doc.resId, libelle: doc.libelle, montant: doc.ttc }];
      $('rs-resolution').hidden = false; reinitSortie(false);
      if (!ecritureOk) $('rs-etat').textContent = 'Écriture indisponible (voir l’onglet Paiements groupés) : seule la porte de sortie est utilisable.';
      $('rs-titre').textContent = 'Résoudre : ' + doc.libelle;
      $('rs-doc').textContent = 'Document du ' + fr(doc.date) + ' · actuellement : statut ' + (doc.attendu.statut || '(vide)') + ', payé le ' + (doc.attendu.datePaiement ? fr(doc.attendu.datePaiement) : '—') + ', réf. ' + (doc.attendu.refBancaire || '—');
      ['rs-cherche', 'rs-texte', 'rs-date'].forEach(function (id) { $(id).value = ''; });
      $('rs-motif').value = ''; $('rs-remplacer').checked = false; if (ecritureOk) $('rs-etat').textContent = '';
      ouvrirFenetre();
      charger().then(afficher).catch(function (e) { $('rs-lignes').innerHTML = ''; $('rs-etat').textContent = '⚠ ' + e.message; });
    });
  });
  $('rs-fermer').addEventListener('click', function () { dlg.close(); });
  // Fenêtre déplaçable : on la saisit par son en-tête (titre) ; elle reste dans l'écran
  (function () {
    var tete = dlg.querySelector('.rs-h'), dx = 0, dy = 0, actif = false;
    tete.addEventListener('pointerdown', function (e) {
      if (e.button !== 0 || e.target.closest('button, a, input, select')) return;
      var r = dlg.getBoundingClientRect();
      dlg.classList.add('deplace'); dlg.style.left = r.left + 'px'; dlg.style.top = r.top + 'px';
      dx = e.clientX - r.left; dy = e.clientY - r.top; actif = true;
      try { tete.setPointerCapture(e.pointerId); } catch (x) { /* capture facultative */ } e.preventDefault();
    });
    tete.addEventListener('pointermove', function (e) {
      if (!actif) return;
      var r = dlg.getBoundingClientRect();
      dlg.style.left = Math.min(Math.max(0, e.clientX - dx), window.innerWidth - Math.min(r.width, 120)) + 'px';
      dlg.style.top = Math.min(Math.max(0, e.clientY - dy), window.innerHeight - 60) + 'px';
    });
    tete.addEventListener('pointerup', function () { actif = false; });
  })();
  ['cl-motif', 'cl-texte'].forEach(function (id) { $(id).addEventListener('input', function () {
    var manque = !$('cl-motif').value ? 'Choisissez un motif.' : ($('cl-texte').value.trim().length < 3 ? 'Explication : 3 caractères au moins.' : '');
    $('cl-classer').disabled = manque !== '';
    $('cl-etat').textContent = manque;
  }); });
  $('cl-classer').addEventListener('click', function () {
    var b = $('cl-classer'); b.disabled = true; $('cl-etat').textContent = 'Classement…';
    fetch(location.pathname, { method: 'POST', body: new URLSearchParams({ action: 'classer', cote: cote, motif: $('cl-motif').value, texte: $('cl-texte').value,
      elements: JSON.stringify(sources.map(function (x) { return { cle: x.cle, libelle: x.libelle, montant: x.montant }; })) }) })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (!r.ok) { $('cl-etat').textContent = '⚠ ' + r.erreur; b.disabled = false; return; }
        sources.forEach(function (x) {
          var bloc = x.el.closest('tr, article'); if (!bloc) return;
          bloc.style.opacity = '.45';
          var cb = bloc.querySelector('.coche-debit'); if (cb) { cb.checked = false; cb.disabled = true; }
          var bt = bloc.querySelector('.classer-debit, .resoudre'); if (bt) bt.outerHTML = '<span class="done">✓ Classé sans suite</span>';
        });
        majSelection();
        dlg.close();
      })
      .catch(function () { $('cl-etat').textContent = '⚠ Le module ne répond pas.'; b.disabled = false; });
  });
  $('rs-cherche').addEventListener('input', afficher);
  ['rs-motif', 'rs-texte', 'rs-date'].forEach(function (id) { $(id).addEventListener('input', recalculer); });
  $('rs-ecrire').addEventListener('click', function () {
    var b = $('rs-ecrire'); b.disabled = true; $('rs-etat').textContent = 'Écriture en cours…';
    fetch(location.pathname, { method: 'POST', body: new URLSearchParams({ action: 'resoudre', cote: cote, resId: doc.resId, libelle: doc.libelle, ttcCents: doc.ttcCents,
      lignes: JSON.stringify(Object.keys(choisies)), motif: $('rs-motif').value, texte: $('rs-texte').value, date: $('rs-date').value,
      remplacer: $('rs-remplacer').checked ? '1' : '', attendu: JSON.stringify(doc.attendu) }) })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (!r.ok) { $('rs-etat').textContent = '⚠ ' + r.erreur; b.disabled = false; return; }
        var d = r.document, dec = d.decision || {};
        if (d.erreur) { $('rs-etat').textContent = '⚠ ' + d.erreur; b.disabled = false; return; }
        if (d.ecrit || dec.etat === 'a_jour') {
          $('rs-etat').textContent = d.ecrit ? '✓ Écrit dans Zeendoc (annulable avec « Annuler cette série » après rechargement).' : '✓ Zeendoc était déjà à jour.';
          // Succès : la fenêtre se ferme, le résultat reste affiché à la place du bouton
          bouton.outerHTML = '<span class="done">✓ ' + (d.ecrit ? 'Écrit dans Zeendoc' : 'Déjà à jour') + ' : ' + r.cible.refBancaire.replace(/[<>&"]/g, '') + '</span>';
          dlg.close();
          return;
        }
        $('rs-etat').textContent = (dec.etat === 'bloque' || dec.etat === 'conflit' ? dec.motif + ' Cochez « Remplacer les valeurs déjà présentes » si c\'est voulu.' : (dec.motif || 'Non écrit.'));
        b.disabled = false;
      })
      .catch(function () { $('rs-etat').textContent = '⚠ Le module ne répond pas.'; b.disabled = false; });
  });
})();
document.querySelectorAll('.vers-maj').forEach(function (a) {
  a.addEventListener('click', function (e) { e.preventDefault(); var t = document.querySelector('.tab[data-p="p5"]'); if (t) t.click(); });
});
document.querySelectorAll('.tab').forEach(function (b) {
  b.addEventListener('click', function () {
    document.querySelectorAll('.tab').forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
    document.querySelectorAll('.panel').forEach(function (p) { p.classList.toggle('on', p.id === b.dataset.p); });
  });
});
(function () {
  var o = <?= json_encode(preg_match('/^p\d$/', (string) ($_POST['onglet'] ?? '')) ? $_POST['onglet'] : '') ?>;
  if (o) { var t = document.querySelector('.tab[data-p="' + o + '"]'); if (t) t.click(); }
})();
(function () {
  var ns = document.querySelectorAll('.nbvalid'); if (!ns.length) return;
  var maj = function () { var k = document.querySelectorAll('#p2 .decide .valider:checked, #p3 .decide .valider:checked').length; ns.forEach(function (n) { n.textContent = k; }); };
  document.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('.decide .valider')) maj(); });
  maj();
})();
(function () {
  var go = document.getElementById('jm-go'); if (!go) return;
  var etat = document.getElementById('jm-etat');
  var lignes = Array.prototype.slice.call(document.querySelectorAll('#p3 tr.jm-debit'));
  var fr = function (d) { var p = d.split('-'); return p[2] + '/' + p[1] + '/' + p[0]; };
  var el = function (tag, cls, txt) { var e = document.createElement(tag); if (cls) e.className = cls; if (txt !== undefined) e.textContent = txt; return e; };
  go.addEventListener('click', function () {
    var debits = lignes.map(function (tr) { return JSON.parse(tr.dataset.debit); });
    go.disabled = true; etat.textContent = 'Lecture d\'Outlook en cours… (ça peut prendre une ou deux minutes)';
    fetch(location.pathname, { method: 'POST', body: new URLSearchParams({ action: 'chercher_mails', debits: JSON.stringify(debits) }) })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        go.disabled = false;
        if (!r.ok) { etat.textContent = '⚠ ' + r.erreur; return; }
        var trouves = 0;
        lignes.forEach(function (tr) {
          var deb = JSON.parse(tr.dataset.debit), zone = tr.querySelector('.jm-mails'); zone.innerHTML = '';
          var cands = r.resultats[deb.id] || [];
          if (cands.length) trouves++;
          cands.forEach(function (c) {
            var b = el('div', 'sugg mail ' + c.confiance);
            var l1 = el('div'); l1.appendChild(el('b', '', '📧 ' + (c.expediteur || c.email)));
            l1.appendChild(el('span', 'small', ' · reçu le ' + fr(c.recu) + ' · ' + c.objet));
            b.appendChild(l1);
            b.appendChild(el('div', 'small', '📎 ' + c.nom + ' — confiance ' + c.confiance + ' : ' + c.raisons));
            var act = el('div', 'act');
            var voir = el('a', '', 'Voir le PDF ↗'); voir.href = '?pdf=' + c.token; voir.target = '_blank'; voir.rel = 'noopener';
            act.appendChild(voir);
            var msg = el('span', 'small'); msg.setAttribute('aria-live', 'polite');
            if (c.deja) { b.classList.add('depose'); msg.textContent = '✓ Déjà déposé dans Zeendoc'; }
            else {
              var dep = el('button', 'btn2', 'Déposer dans Zeendoc'); dep.type = 'button';
              dep.addEventListener('click', function () {
                if (dep.dataset.ok !== '1') { dep.dataset.ok = '1'; dep.textContent = 'Confirmer le dépôt'; msg.textContent = 'Vérifie le PDF, puis confirme.'; return; }
                dep.disabled = true; msg.textContent = 'Envoi vers Zeendoc…';
                fetch(location.pathname, { method: 'POST', body: new URLSearchParams({ action: 'deposer', token: c.token, debit: JSON.stringify(deb) }) })
                  .then(function (x) { return x.json(); })
                  .then(function (x) {
                    if (x.ok) { b.classList.add('depose'); dep.remove(); msg.textContent = '✓ Déposé (' + x.fichier + '). Zeendoc va l\'indexer ; relance le rapprochement dans quelques minutes.'; }
                    else { dep.disabled = false; dep.dataset.ok = ''; dep.textContent = 'Déposer dans Zeendoc'; msg.textContent = '⚠ ' + x.erreur; }
                  })
                  .catch(function () { dep.disabled = false; msg.textContent = '⚠ Le module ne répond pas.'; });
              });
              act.appendChild(dep);
            }
            act.appendChild(msg); b.appendChild(act); zone.appendChild(b);
          });
        });
        var st = r.stats || {};
        etat.textContent = trouves + ' débit(s) sur ' + lignes.length + ' avec une piste · ' + (st.mails || 0) + ' mails parcourus du ' + fr(st.du) + ' au ' + fr(st.au) + ', ' + (r.nbPdf || 0) + ' PDF analysés' + (st.tronque ? ' (limite de 1 500 mails atteinte)' : '') + '.';
      })
      .catch(function () { go.disabled = false; etat.textContent = '⚠ Le module ne répond pas.'; });
  });
})();
</script>
</body>
</html>
