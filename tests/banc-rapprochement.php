<?php
/**
 * Banc de régression du rapprochement — étape 1 (noyau générique).
 *
 * Usage :
 *   php tests/banc-rapprochement.php run <releve.csv> <du JJ/MM/AAAA> <au JJ/MM/AAAA> <sortie.json> [--cote fournisseurs|clients] [--factures fixture.json]
 *   php tests/banc-rapprochement.php comparer <avant.json> <apres.json>
 *
 * - run : exécute le moteur réel (rb_rapprocher) sur le relevé CSV et la période,
 *   avec les factures chargées depuis l'armoire (mode live, variables ZEENDOC_*),
 *   ou depuis une fixture JSON (--factures, mode hors ligne). Le résultat est
 *   normalisé (clés triées, horodatage figé) puis écrit en JSON canonique.
 * - comparer : compare deux sorties canoniques et sort 0 si identiques, 1 sinon.
 *
 * Scénario avant/après : lancer "run" sur la branche/commit d'avant, sauver dans
 * banc-resultats/avant.json, relancer sur le commit d'après, sauver apres.json,
 * puis "comparer avant.json apres.json".
 */
declare(strict_types=1);

define('ZD_SANS_BOUCLE', true);

require_once __DIR__ . '/../zeendoc-mcp.php';
require_once __DIR__ . '/../lots.php';
require_once __DIR__ . '/../remboursements.php';

function banc_usage(): void
{
    fwrite(STDERR, "Usage :
  php tests/banc-rapprochement.php run <releve.csv> <du> <au> <sortie.json> [--cote fournisseurs|clients] [--factures fixture.json]
  php tests/banc-rapprochement.php comparer <avant.json> <apres.json>
");
    exit(2);
}

function banc_canonifier($v)
{
    if (is_array($v)) {
        $assoc = array_keys($v) !== range(0, count($v) - 1);
        $out = [];
        foreach ($v as $k => $x) {
            $out[$k] = banc_canonifier($x);
        }
        if ($assoc) {
            ksort($out);
        }
        return $out;
    }
    return $v;
}

function banc_comparer_rec($a, $b, string $chemin, array &$diffs): void
{
    if (is_array($a) && is_array($b)) {
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
            if (!array_key_exists($k, $a)) {
                $diffs[] = "$chemin.$k : présent seulement dans APRES";
            } elseif (!array_key_exists($k, $b)) {
                $diffs[] = "$chemin.$k : présent seulement dans AVANT";
            } else {
                banc_comparer_rec($a[$k], $b[$k], "$chemin.$k", $diffs);
            }
        }
        return;
    }
    if ($a !== $b) {
        $diffs[] = "$chemin : AVANT=" . json_encode($a, JSON_UNESCAPED_UNICODE) . " APRES=" . json_encode($b, JSON_UNESCAPED_UNICODE);
    }
}

$args = $argv;
array_shift($args);
$commande = array_shift($args) ?: '';

if ($commande === 'comparer') {
    if (count($args) !== 2) {
        banc_usage();
    }
    $avant = json_decode((string) file_get_contents($args[0]), true);
    $apres = json_decode((string) file_get_contents($args[1]), true);
    if (!is_array($avant) || !is_array($apres)) {
        fwrite(STDERR, "Fichiers de banc illisibles.\n");
        exit(2);
    }
    $diffs = [];
    banc_comparer_rec($avant, $apres, '$', $diffs);
    if (!$diffs) {
        echo "IDENTIQUE : aucune différence entre avant et après.\n";
        exit(0);
    }
    echo count($diffs) . " différence(s) :\n";
    foreach ($diffs as $d) {
        echo "  $d\n";
    }
    exit(1);
}

if ($commande !== 'run') {
    banc_usage();
}

$options = ['cote' => 'fournisseurs', 'factures' => null];
$positionnels = [];
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--cote' && isset($args[$i + 1])) {
        $options['cote'] = $args[++$i];
    } elseif ($args[$i] === '--factures' && isset($args[$i + 1])) {
        $options['factures'] = $args[++$i];
    } else {
        $positionnels[] = $args[$i];
    }
}
if (count($positionnels) !== 4) {
    banc_usage();
}
[$csv, $duS, $auS, $sortie] = $positionnels;

$du = rb_date($duS);
$au = rb_date($auS);
if ($du === null || $au === null) {
    fwrite(STDERR, "Dates invalides (attendu JJ/MM/AAAA) : $duS / $auS\n");
    exit(2);
}
if (!is_file($csv)) {
    fwrite(STDERR, "Relevé introuvable : $csv\n");
    exit(2);
}

$clients = $options['cote'] === 'clients';
$ops = rb_lire_csv((string) file_get_contents($csv));

$kc = mc_classeur($clients ? 'clients' : 'fournisseurs');
if (!$kc) {
    fwrite(STDERR, "Classeur non configuré dans module-config.json\n");
    exit(2);
}
$ix = $kc['index'];
$ch = [
    'fournisseur' => $ix['tiers'], 'date' => $ix['date'], 'ref' => $ix['ref'], 'ttc' => $ix['ttc'],
    'echeance' => $ix['echeance'], 'mode' => $ix['mode'], 'statut' => $ix['statut'], 'type' => $ix['type'],
    'datePaiement' => $ix['datePaiement'], 'refBancaire' => $ix['refBancaire'],
];
$coll = $kc['collId'];
$payeId = $kc['statutPayeId'] ?? null;

if ($options['factures'] !== null) {
    $factures = json_decode((string) file_get_contents($options['factures']), true);
    if (!is_array($factures)) {
        fwrite(STDERR, "Fixture factures illisible : {$options['factures']}\n");
        exit(2);
    }
} else {
    $c = [
        'zeendoc_host' => mc_host(),
        'zeendoc_url_client' => mc_url_client(),
        'zeendoc_login' => (string) getenv('ZEENDOC_LOGIN'),
        'zeendoc_password' => (string) getenv('ZEENDOC_PASSWORD'),
    ];
    $docs = charger_factures($c, $coll, $ch, rb_ajout($du, -120), $au);
    $factures = [];
    foreach ($docs as $d) {
        $factures[] = [
            'id' => (string) $d['resId'],
            'fournisseur' => (string) idx($d, $ch['fournisseur']),
            'date' => (string) rb_date((string) idx($d, $ch['date'])),
            'ref' => (string) idx($d, $ch['ref']),
            'cents' => rb_cents(idx($d, $ch['ttc']) ?? 0),
            'echeance' => rb_date((string) idx($d, $ch['echeance'])),
            'mode' => (string) idx($d, $ch['mode'], 'displayableIndexes'),
            'paye' => $payeId === null
                ? (bool) preg_match('/^pay/i', trim(html_entity_decode((string) idx($d, $ch['statut'], 'displayableIndexes'), ENT_QUOTES, 'UTF-8')))
                : (int) idx($d, $ch['statut']) === (int) $payeId,
            'statut' => html_entity_decode((string) idx($d, $ch['statut'], 'displayableIndexes'), ENT_QUOTES, 'UTF-8'),
            'dpActuelle' => (string) idx($d, $ch['datePaiement']),
            'refActuelle' => (string) idx($d, $ch['refBancaire']),
        ];
    }
}

$alias = [];
$fAlias = __DIR__ . '/../alias_fournisseurs.json';
if (is_file($fAlias)) {
    $alias = json_decode((string) file_get_contents($fAlias), true) ?: [];
}
$exclusions = array_merge($clients ? rb_exclusions_clients() : rb_exclusions_defaut(), mc_exclusions($clients ? 'credits' : 'debits'));

$r = rb_rapprocher($factures, $ops, [
    'du' => $du,
    'au' => $au,
    'aujourdhui' => $au,
    'tolerance' => 25,
    'alias' => $alias,
    'exclusions' => $exclusions,
    'fournisseursFraisBancaires' => mc_banques(),
    'sensCible' => $clients ? 'credit' : 'debit',
    'valides' => [],
    'rejets' => [],
    'payes' => [],
    'lots' => [],
    'remboursements' => [],
    'nonRemboursees' => [],
    'classes' => [],
]);

$rapport = [
    'banc' => 'rapprochement',
    'cote' => $options['cote'],
    'periode' => ['du' => $du, 'au' => $au],
    'nb_operations' => count($ops),
    'nb_factures' => count($factures),
    'resultat' => banc_canonifier($r),
];

$dossier = dirname($sortie);
if (!is_dir($dossier)) {
    mkdir($dossier, 0700, true);
}
file_put_contents($sortie, json_encode($rapport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX);

$synthese = $r['synthese'] ?? [];
echo "Banc écrit : $sortie\n";
echo 'Période ' . $du . ' → ' . $au . ' · ' . count($ops) . ' opérations · ' . count($factures) . " factures\n";
foreach ($synthese as $k => $v) {
    echo "  $k : " . (is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE)) . "\n";
}
