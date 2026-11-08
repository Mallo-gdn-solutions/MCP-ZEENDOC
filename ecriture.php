<?php
/**
 * Écriture dans Zeendoc — module de rapprochement GDN Solutions
 * -------------------------------------------------------------
 * Compte DÉDIÉ « RAPPROCHEMENT » (jamais le compte du connecteur Claude).
 * Identifiants dans rapprochement-ecriture.json (à côté de ce fichier) : {"login": "...", "password": "..."}
 *
 * Garde-fous :
 *  - routes : PATCH /binders/{coll}/documents/{id}/indexes (mise à jour PARTIELLE, jamais PUT)
 *  - classeurs et index en liste blanche (statut, date de paiement, référence bancaire)
 *  - valeurs contrôlées (liste de statuts connue, date JJ/MM/AAAA, référence courte)
 *  - chaque écriture est journalisée (avant / après) dans ecritures-journal.jsonl pour pouvoir annuler
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** Classeurs et index autorisés en écriture : lus dans module-config.json (statut, date de paiement, référence bancaire) */
function ecr_champs(): array { static $c = null; return $c ??= mc_champs_ecriture(); }
const ECR_STATUTS = ['Payé', 'Paiement partiel'];
const ECR_JOURNAL = NOYAU_JOURNAL; // journal du noyau : noyau/journal.php

$GLOBALS['ecr_session'] = null;

function ecr_identifiants(): array
{
    // Version hébergée : variables d'environnement, pas de mot de passe dans un fichier
    if (getenv('ZEENDOC_ECRITURE_LOGIN') && getenv('ZEENDOC_ECRITURE_PASSWORD')) {
        return ['login' => (string) getenv('ZEENDOC_ECRITURE_LOGIN'), 'password' => (string) getenv('ZEENDOC_ECRITURE_PASSWORD')];
    }
    $f = __DIR__ . '/rapprochement-ecriture.json';
    $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    if (!is_array($j) || empty($j['login']) || empty($j['password'])) {
        throw new RuntimeException("Compte d'écriture non configuré : créer rapprochement-ecriture.json avec le login et le mot de passe du compte RAPPROCHEMENT.");
    }
    return $j;
}

function ecr_base(): string
{
    return mc_host() . '/' . mc_url_client() . '/api/v4';
}

function ecr_http(string $methode, string $chemin, ?array $corps = null, ?array $query = null): array
{
    // Liste blanche du noyau (profil « ecriture ») : login, lecture du contexte / recherche (pour contrôler), PATCH partiel des index
    // sur les classeurs du module, et dépôt d'une facture retrouvée dans Outlook (justificatifs.php) dans le classeur Fournisseurs uniquement
    $classeurs = ['ecriture' => array_keys(ecr_champs()), 'depot' => array_filter([mc_coll('fournisseurs')])];
    if (!noyau_route_autorisee('ecriture', $methode, $chemin, $classeurs)) {
        throw new RuntimeException("Route refusée par le module d'écriture : $methode $chemin");
    }
    $h = ['Content-Type: application/json', 'Accept: application/json'];
    if ($chemin !== '/login') {
        $h[] = 'Cookie: ' . ecr_cookie();
    }
    $r = noyau_http(ecr_base() . $chemin . ($query ? '?' . http_build_query($query) : ''), $methode, $h, $corps !== null ? json_encode($corps) : null, 120);
    if ($r['corps'] === null) throw new RuntimeException("Réseau : {$r['erreur']}");
    $body = $r['corps'];
    $json = json_decode($body, true);
    return ['status' => $r['status'], 'headers' => $r['entetes'], 'json' => is_array($json) ? $json : null, 'brut' => substr($body, 0, 600)];
}

function ecr_cookie(): string
{
    if ($GLOBALS['ecr_session']) return $GLOBALS['ecr_session'];
    $id = ecr_identifiants();
    $r = ecr_http('POST', '/login', ['login' => $id['login'], 'password' => $id['password']]);
    if ($r['status'] !== 200) throw new RuntimeException("Connexion du compte RAPPROCHEMENT refusée (HTTP {$r['status']})");
    $c = $r['json']['cookie'] ?? null;
    if (!$c && preg_match('/Set-Cookie:\s*([^;]+)/i', $r['headers'], $m)) $c = trim($m[1]);
    if (!$c) throw new RuntimeException('Connexion RAPPROCHEMENT : pas de cookie');
    return $GLOBALS['ecr_session'] = $c;
}

/** Identifiants des valeurs de la liste « Statut du règlement » de chaque classeur (lus dans /context) */
function ecr_ids_statuts(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $r = ecr_http('GET', '/context', null, ['getConfigSets' => 'false']);
    $cache = [];
    foreach (($r['json']['collections'] ?? []) as $col) {
        $coll = $col['collId'] ?? '';
        if (!isset(ecr_champs()[$coll])) continue;
        foreach ($col['index'] ?? [] as $i) {
            if (($i['indexId'] ?? '') !== ecr_champs()[$coll]['statut']) continue;
            foreach ($i['possibleValue'] ?? [] as $v) {
                if (isset($v['id'], $v['label'])) $cache[$coll][$v['label']] = (string) $v['id'];
            }
        }
    }
    return $cache;
}

/**
 * Applique une mise à jour sur un document.
 * $m = ['collId','resId','statut','datePaiement' (AAAA-MM-JJ),'refBancaire','avant' => [...], 'libelle' => '...']
 */
function ecr_appliquer(array $m): array
{
    $coll = (string) ($m['collId'] ?? '');
    $res  = (int) ($m['resId'] ?? 0);
    if (!isset(ecr_champs()[$coll]) || $res <= 0) throw new InvalidArgumentException('Document invalide');
    $champs = ecr_champs()[$coll];

    $liste = [];
    $n = 0;
    if (($m['statut'] ?? '') !== '') {
        if (!in_array($m['statut'], ECR_STATUTS, true)) throw new InvalidArgumentException('Statut non autorisé');
        $ids = ecr_ids_statuts();
        $id = $ids[$coll][$m['statut']] ?? null;
        if ($id === null) throw new RuntimeException("Valeur « {$m['statut']} » absente de la liste du classeur $coll");
        $liste[] = ['id' => ++$n, 'label' => $champs['statut'], 'value' => $id];
    }
    if (($m['datePaiement'] ?? '') !== '') {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $m['datePaiement'], $d)) throw new InvalidArgumentException('Date invalide');
        $liste[] = ['id' => ++$n, 'label' => $champs['datePaiement'], 'value' => "$d[3]/$d[2]/$d[1]"];
    }
    if (($m['refBancaire'] ?? '') !== '') {
        $ref = (string) $m['refBancaire'];
        if (strlen($ref) > 120 || !preg_match('/^[\w\- +\/.]+$/u', $ref)) throw new InvalidArgumentException('Référence bancaire invalide');
        $liste[] = ['id' => ++$n, 'label' => $champs['refBancaire'], 'value' => $ref];
    }
    if (!$liste) throw new InvalidArgumentException('Rien à écrire');

    $r = ecr_http('PATCH', "/binders/$coll/documents/$res/indexes", ['indexList' => $liste]);
    $ok = $r['status'] >= 200 && $r['status'] < 300;

    $ligne = [
        'quand'   => date('c'),
        'collId'  => $coll,
        'resId'   => $res,
        'document'=> (string) ($m['libelle'] ?? ''),
        'serie'   => $m['serie'] ?? null,   // série = un lot validé en un clic (annulable d'un bloc)
        'preuve'  => $m['preuve'] ?? null,
        'justification' => $m['justification'] ?? null, // résolution manuelle : motif de l'écart et texte saisi
        'avant'   => $m['avant'] ?? null,
        'apres'   => ['statut' => $m['statut'] ?? null, 'datePaiement' => $m['datePaiement'] ?? null, 'refBancaire' => $m['refBancaire'] ?? null],
        'envoye'  => $liste,
        'http'    => $r['status'],
        'reponse' => $r['json'] ?? $r['brut'],
        'ok'      => $ok,
    ];
    noyau_journal_ajouter($ligne);

    if (!$ok) {
        throw new RuntimeException("Zeendoc a refusé la mise à jour (HTTP {$r['status']}) : " . $r['brut']);
    }
    return $ligne;
}

/** Contrôle : relit le document (par sa référence bancaire) et renvoie ses valeurs réelles */
function ecr_relire(string $coll, int $res, string $refBancaire): ?array
{
    if (!isset(ecr_champs()[$coll]) || $refBancaire === '') return null;
    $c = ecr_champs()[$coll];
    $r = ecr_http('POST', "/binders/$coll/documents/search", [
        'indexList' => [['id' => 1, 'label' => $c['refBancaire'], 'value' => $refBancaire, 'operator' => 'EQUALS']],
        'strictMode' => 1, 'from' => 0, 'nbResults' => 10,
        'wantedColumns' => implode(';', $c),
    ]);
    foreach (($r['json']['document'] ?? []) as $d) {
        if ((int) ($d['resId'] ?? 0) !== $res) continue;
        $v = $d['displayableIndexes'] ?? [];
        $get = function (string $id) use ($v) {
            $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $id))));
            $x = $v[$camel] ?? null;
            return html_entity_decode(is_array($x) ? (string) ($x[0] ?? '') : (string) $x, ENT_QUOTES, 'UTF-8');
        };
        return ['statut' => $get($c['statut']), 'datePaiement' => $get($c['datePaiement']), 'refBancaire' => $get($c['refBancaire'])];
    }
    return null;
}

/* ------------------------------------------------------------
 * Écriture contrôlée d'un règlement (paiement groupé ou débit simple)
 * ------------------------------------------------------------ */

require_once __DIR__ . '/reglement.php';

/** Valeurs actuelles d'un document, relues dans Zeendoc juste avant d'écrire : statut (libellé), date (AAAA-MM-JJ), référence */
function ecr_lire_valeurs(string $coll, int $res): array
{
    if (!isset(ecr_champs()[$coll]) || $res <= 0) throw new InvalidArgumentException('Document invalide');
    $c = ecr_champs()[$coll];
    $r = ecr_http('GET', "/binders/$coll/documents", null, ['resId' => $res, 'wantedColumns' => implode(';', $c)]);
    if ($r['status'] !== 200 || !isset($r['json']['document'])) throw new RuntimeException("Relecture du document $res impossible (HTTP {$r['status']})");
    $v = $r['json']['document']['indexes'] ?? [];
    $get = function (string $id) use ($v): string {
        $x = $v[lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $id))))] ?? null;
        return trim(html_entity_decode(is_array($x) ? (string) ($x[0] ?? '') : (string) $x, ENT_QUOTES, 'UTF-8'));
    };
    $idStatut = $get($c['statut']);
    $libelle = $idStatut === '' ? '' : (string) (array_search($idStatut, ecr_ids_statuts()[$coll] ?? [], true) ?: "valeur $idStatut");
    return ['statut' => $libelle, 'datePaiement' => rb_date($get($c['datePaiement'])) ?? '', 'refBancaire' => $get($c['refBancaire'])];
}

function ecr_active(): bool { return (bool) (module_cfg()['ecriture']['active'] ?? true); }

/**
 * Droits du compte d'écriture, vérifiés au démarrage du module (résultat gardé 10 minutes).
 * L'API n'expose pas de droit « modifier les index ». Constaté (oct. 2026) : la modification d'index
 * fonctionne SANS canUploadDocs (Clients, Notes de frais). On exige donc l'accès au classeur et la recherche ;
 * canUploadDocs n'est demandé que pour le classeur Fournisseurs (dépôt des justificatifs retrouvés dans Outlook).
 */
function ecr_droits(bool $forcer = false): array
{
    if (!ecr_active()) return ['ok' => false, 'message' => 'Écriture désactivée dans module-config.json (ecriture.active = false) : le module ne fait que proposer.'];
    $cache = __DIR__ . '/cache-paiements/droits-ecriture.json';
    if (!$forcer && is_file($cache) && filemtime($cache) > time() - 600) {
        $j = json_decode((string) file_get_contents($cache), true);
        if (is_array($j)) return $j;
    }
    try {
        $r = ecr_http('GET', '/context', null, ['getConfigSets' => 'false']);
        $manque = [];
        foreach (array_keys(ecr_champs()) as $coll) {
            $col = null;
            foreach (($r['json']['collections'] ?? []) as $x) { if (($x['collId'] ?? '') === $coll) { $col = $x; break; } }
            $nom = mc_nom_classeur($coll);
            if (!$col) { $manque[] = "$nom ($coll) : classeur non accessible"; continue; }
            $d = (array) ($col['rights'] ?? []);
            if (empty($d['canSearchDocs'])) $manque[] = "$nom : droit de recherche";
            if ($coll === mc_coll('fournisseurs') && empty($d['canUploadDocs'])) $manque[] = "$nom : droit de dépôt (canUploadDocs)";
        }
        $res = $manque
            ? ['ok' => false, 'message' => "Le compte d'écriture n'a pas les droits nécessaires — " . implode(' ; ', $manque) . ". À corriger dans l'administration Zeendoc (profil du compte RAPPROCHEMENT)."]
            : ['ok' => true, 'message' => "Compte d'écriture connecté, classeurs accessibles. Le droit de modifier les index n'est pas exposé par l'API : il est confirmé à la première écriture (Zeendoc répond par un refus sinon)."];
    } catch (Throwable $e) {
        $res = ['ok' => false, 'message' => "Compte d'écriture inutilisable : " . $e->getMessage()];
    }
    if (!is_dir(dirname($cache))) mkdir(dirname($cache), 0700, true);
    file_put_contents($cache, json_encode($res, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $res;
}

/**
 * Écrit (ou prévisualise) un règlement sur une liste de documents.
 * Chaque document est RELU dans Zeendoc juste avant : vide → écrit · identique → rien · différent → conflit
 * (rien n'est écrit sur ce document sans choix) · autre référence bancaire → double paiement, bloqué.
 *
 * @param array  $docs   [['collId','resId','libelle']]
 * @param array  $cible  ['statut' => 'Payé', 'datePaiement' => AAAA-MM-JJ, 'refBancaire' => preuve]
 * @param array  $jetons identifiants de CE paiement (n° de transaction, réf. de la ligne bancaire)
 * @param array  $choix  resId => [champ => 'banque'|'zeendoc'], ou '*' => [...] pour tout le lot
 * @param bool   $ecrire false = simple aperçu, rien n'est envoyé
 * Si $doc['attendu'] est fourni (valeurs AFFICHÉES à l'utilisateur quand il a validé) et que la relecture diffère,
 * le document n'est pas écrit : on n'écrit jamais sur autre chose que ce que l'utilisateur a vu
 * (incident du 30/10/2026 : FA152502 affichée « bloquée », relue vide au moment du clic, puis écrite).
 */
function ecr_regler(array $docs, array $cible, array $jetons, array $choix, bool $ecrire, ?string $serie = null): array
{
    if ($ecrire && !ecr_active()) throw new RuntimeException('Écriture désactivée dans module-config.json (ecriture.active = false).');
    $serie ??= date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $out = [];
    foreach ($docs as $doc) {
        $coll = (string) $doc['collId'];
        $res = (int) $doc['resId'];
        $l = ['resId' => $res, 'libelle' => (string) ($doc['libelle'] ?? "doc $res")];
        try {
            $actuel = ecr_lire_valeurs($coll, $res);
            $cmp = rg_comparer($actuel, $cible, $jetons);
            $dec = rg_decider($cmp, array_merge((array) ($choix['*'] ?? []), (array) ($choix[(string) $res] ?? [])), !empty($doc['remplacer']));
            if (isset($doc['attendu'])) {
                $diff = [];
                foreach (RG_CHAMPS as $ch) {
                    $vu = trim((string) ($doc['attendu'][$ch] ?? ''));
                    $vu = rg_vide($vu) ? '' : ($ch === 'datePaiement' ? (rb_date($vu) ?? $vu) : $vu);
                    if (strcasecmp($vu, $actuel[$ch]) !== 0) $diff[] = RG_LIBELLES[$ch] . ' : affiché « ' . ($vu ?: '(vide)') . ' », lu « ' . ($actuel[$ch] ?: '(vide)') . ' »';
                }
                if ($diff) $dec = ['etat' => 'modifie', 'ecrire' => [], 'enAttente' => [],
                    'motif' => 'Le document a changé dans Zeendoc depuis l\'affichage (' . implode(' ; ', $diff) . ') : rien n\'est écrit, relancez le rapprochement.'];
            }
            $l += ['avant' => $actuel, 'comparaison' => $cmp, 'decision' => $dec];
            if ($ecrire && $dec['etat'] === 'a_ecrire') {
                ecr_appliquer(['collId' => $coll, 'resId' => $res, 'libelle' => $l['libelle'], 'avant' => $actuel, 'serie' => $serie, 'preuve' => $cible['refBancaire'],
                    'justification' => $doc['justification'] ?? null] + $dec['ecrire']);
                $l['ecrit'] = true;
            }
        } catch (Throwable $e) {
            $l['erreur'] = $e->getMessage();
        }
        $out[] = $l;
    }
    return ['serie' => $serie, 'documents' => $out];
}

/** Lignes du journal, la plus récente en dernier */
function ecr_journal(): array
{
    return noyau_journal_lire();
}

/** Dernière série d'écritures non annulée : ['serie', 'quand', 'lignes'] ou null */
function ecr_derniere_serie(): ?array
{
    $j = ecr_journal();
    $annulees = [];
    foreach ($j as $l) { if (($l['type'] ?? '') === 'annulation') $annulees[(string) $l['serie']] = true; }
    for ($i = count($j) - 1; $i >= 0; $i--) {
        $s = (string) ($j[$i]['serie'] ?? '');
        if ($s === '' || ($j[$i]['type'] ?? '') === 'annulation' || isset($annulees[$s])) continue;
        $lignes = array_values(array_filter($j, fn($l) => ($l['serie'] ?? '') === $s && !empty($l['ok']) && ($l['type'] ?? '') !== 'annulation'));
        return ['serie' => $s, 'quand' => $j[$i]['quand'], 'lignes' => $lignes];
    }
    return null;
}

/**
 * Annule la dernière série : chaque champ écrit reprend sa valeur d'avant.
 * Un champ modifié depuis (par quelqu'un d'autre) n'est pas touché et est signalé.
 */
function ecr_annuler_serie(string $serie): array
{
    $d = ecr_derniere_serie();
    if (!$d || $d['serie'] !== $serie) throw new RuntimeException("Seule la dernière série d'écritures peut être annulée.");
    $out = [];
    foreach ($d['lignes'] as $l) {
        $coll = (string) $l['collId'];
        $res = (int) $l['resId'];
        $r = ['resId' => $res, 'libelle' => (string) $l['document'], 'restaure' => [], 'ignore' => []];
        try {
            $champs = ecr_champs()[$coll];
            $actuel = ecr_lire_valeurs($coll, $res);
            $liste = [];
            $n = 0;
            foreach (RG_CHAMPS as $c) {
                $ecrit = (string) ($l['apres'][$c] ?? '');
                if ($ecrit === '' || !in_array($champs[$c], array_column($l['envoye'], 'label'), true)) continue;
                if ($actuel[$c] !== $ecrit) { $r['ignore'][] = RG_LIBELLES[$c] . ' modifié depuis (« ' . $actuel[$c] . ' »)'; continue; }
                $avant = (string) ($l['avant'][$c] ?? '');
                $val = $avant;
                if ($c === 'statut' && $avant !== '') $val = (string) (ecr_ids_statuts()[$coll][$avant] ?? '');
                if ($c === 'datePaiement' && $avant !== '') $val = date('d/m/Y', strtotime($avant));
                $liste[] = ['id' => ++$n, 'label' => $champs[$c], 'value' => $val];
                $r['restaure'][] = RG_LIBELLES[$c] . ' → ' . ($avant === '' ? '(vide)' : $avant);
            }
            if ($liste) {
                $h = ecr_http('PATCH', "/binders/$coll/documents/$res/indexes", ['indexList' => $liste]);
                $r['ok'] = $h['status'] >= 200 && $h['status'] < 300;
                if (!$r['ok']) $r['erreur'] = "Zeendoc a refusé (HTTP {$h['status']}) : " . $h['brut'];
                noyau_journal_ajouter(['quand' => date('c'), 'type' => 'annulation', 'serie' => $serie, 'collId' => $coll, 'resId' => $res,
                    'document' => $l['document'], 'envoye' => $liste, 'http' => $h['status'], 'ok' => $r['ok']]);
            } else {
                $r['ok'] = true;
            }
        } catch (Throwable $e) {
            $r['ok'] = false;
            $r['erreur'] = $e->getMessage();
        }
        $out[] = $r;
    }
    return ['serie' => $serie, 'documents' => $out];
}
