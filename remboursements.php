<?php
/**
 * Remboursements collaborateurs : 1 virement au collaborateur ↔ 1 ou N pièces (IK, notes de frais avancées) — module de rapprochement GDN
 * Fonctions pures (aucun appel réseau). Le résultat est toujours une PROPOSITION : rien n'est écrit sans validation.
 *
 * Une pièce = ['id' => 'N191', 'source' => ik|remb, 'collaborateur', 'fournisseur', 'ref', 'date', 'cents', 'paye' (bool), 'statut', 'dpActuelle', 'refActuelle', 'commentaire']
 */
declare(strict_types=1);

require_once __DIR__ . '/rapprochement.php';
require_once __DIR__ . '/reglement.php';

const RC_MAX_PIECES = 6;       // pièces au plus dans un lot
const RC_MAX_CANDIDATS = 40;   // les plus anciennes d'abord
const RC_MAX_ESSAIS = 500000;  // borne de la recherche de combinaisons

/** Collaborateur dont un alias figure dans le libellé d'un virement (null : carte, prélèvement, ou nom inconnu) */
function rc_collaborateur(array $op, array $collaborateurs): ?string
{
    $nature = "$op[type] $op[libelle]";
    if (!preg_match('/\bVIR\b|virement/i', $nature) || preg_match('/\bCB\b|carte/i', "$nature $op[info]")) return null;
    $t = ' ' . rb_norm("$op[libelle] $op[info]") . ' ';
    foreach ($collaborateurs as $nom => $alias) {
        foreach ((array) $alias as $a) {
            $a = rb_norm((string) $a);
            if ($a !== '' && strpos($t, " $a ") !== false) return (string) $nom;
        }
    }
    return null;
}

/**
 * Toutes les combinaisons de pièces dont la somme vaut exactement $cible, la préférée d'abord :
 * une pièce seule au montant exact, puis la combinaison la plus ancienne (dates comparées de la plus ancienne à la plus récente).
 */
function rc_combinaisons(array $pieces, int $cible, int $max = RC_MAX_PIECES): array
{
    usort($pieces, fn($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
    $pieces = array_slice($pieces, 0, RC_MAX_CANDIDATS);
    $n = count($pieces);
    $sols = [];
    $pile = [];
    $essais = 0;
    $explore = function (int $i, int $reste) use (&$explore, &$sols, &$pile, &$essais, $pieces, $n, $max) {
        if ($reste === 0) { if ($pile) $sols[] = $pile; return; }
        if ($reste < 0 || count($pile) >= $max) return;
        for ($j = $i; $j < $n && $essais < RC_MAX_ESSAIS; $j++) {
            $essais++;
            if ($pieces[$j]['cents'] <= 0 || $pieces[$j]['cents'] > $reste) continue;
            $pile[] = $pieces[$j];
            $explore($j + 1, $reste - $pieces[$j]['cents']);
            array_pop($pile);
        }
    };
    if ($cible > 0) $explore(0, $cible);
    usort($sols, fn($a, $b) => [count($a) === 1 ? 0 : 1, array_column($a, 'date')] <=> [count($b) === 1 ? 0 : 1, array_column($b, 'date')]);
    return $sols;
}

/** Lot au format des paiements groupés (lots.php) : même carte de validation et mêmes garde-fous à l'écriture */
function rc_lot(array $op, string $collaborateur, array $pieces, array $dejaEcrites, array $alternatives, string $collId): array
{
    usort($pieces, fn($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
    $total = array_sum(array_column($pieces, 'cents'));
    $ecart = $op['cents'] - $total;
    $reference = trim((string) $op['ref']);
    $complet = $ecart === 0;
    $lot = [
        'cle'         => 'remb|' . $op['date'] . '|' . $op['cents'] . '|' . $reference,
        'type'        => 'remboursement',
        'collId'      => $collId,
        'transaction' => $reference,
        'source'      => 'Remboursement collaborateur',
        'preuve'      => $reference !== '' ? $reference : rg_preuve_ligne($op),
        'methode'     => count($dejaEcrites) === count($pieces) ? 'référence bancaire déjà écrite sur la pièce'
            : ($complet ? 'collaborateur + montant exact (à valider)' : 'pièces déjà écrites à ce paiement, somme incomplète (à vérifier)'),
        'dejaEcrit'   => $complet && count($dejaEcrites) === count($pieces),
        'debit'       => ['n' => $op['n'], 'date' => $op['date'], 'libelle' => $op['libelle'], 'ref' => $op['ref'], 'montant' => rb_eur($op['cents']), 'cents' => $op['cents']],
        'paiement'    => ['date' => $op['date'], 'beneficiaire' => $collaborateur, 'reference' => $reference, 'montant' => rb_eur($op['cents'])],
        'factures'    => [],
        'manquantes'  => [],
        'total'       => rb_eur($total),
        'ecart'       => ($ecart < 0 ? '-' : '') . rb_eur(abs($ecart)),
        'etat'        => $complet ? 'complet' : 'ecart',
        'jetons'      => array_values(array_filter([$reference], 'strlen')),
        'alternatives' => array_map(fn($sol) => implode(' + ', array_map(fn($p) => ltrim($p['id'], 'N') . ' (' . ($p['ref'] !== '' ? $p['ref'] : $p['date']) . ', ' . rb_eur($p['cents']) . ' €)', $sol)), $alternatives),
        // Paires à mémoriser si l'utilisateur écarte la proposition (validations.json, rejets)
        'rejets'      => array_values(array_map(fn($p) => rb_cle($p['id'], $op), array_filter($pieces, fn($p) => !in_array($p['id'], array_column($dejaEcrites, 'id'), true)))),
    ];
    $cible = rg_cible($lot);
    foreach ($pieces as $p) {
        $dp = rb_date((string) ($p['dpActuelle'] ?? '')) ?? '';
        $lot['factures'][] = [
            'resId' => $p['id'], 'ref' => $p['ref'], 'refPaiement' => $p['ref'], 'fournisseur' => $p['fournisseur'], 'date' => $p['date'], 'ttc' => rb_eur($p['cents']), 'cents' => $p['cents'],
            'statut' => (string) ($p['statut'] ?? ''), 'datePaiementActuelle' => $dp, 'refBancaireActuelle' => (string) ($p['refActuelle'] ?? ''), 'plusieurs' => 0, 'commentaire' => $p['commentaire'] ?? '',
            'comparaison' => rg_comparer(['statut' => (string) ($p['statut'] ?? ''), 'datePaiement' => $dp, 'refBancaire' => (string) ($p['refActuelle'] ?? '')], $cible, $lot['jetons']),
        ];
    }
    return $lot;
}

/**
 * Propose, pour chaque virement à un collaborateur de la période, la ou les pièces qu'il rembourse.
 * Candidates : pièces de ce collaborateur, non payées, sans limite d'ancienneté, antérieures ou égales au débit.
 * Un débit dont la référence figure déjà sur des pièces est reconnu « déjà écrit » : il sort des débits orphelins sans rien proposer.
 * @param array $o du, au, collId, exclusions [regex], valides, rejets [clés rb_cle], classes (voir rb_rapprocher)
 */
function rc_proposer(array $ops, array $pieces, array $collaborateurs, array $o): array
{
    if (!$collaborateurs) return [];
    $valides = array_fill_keys((array) ($o['valides'] ?? []), true);
    $rejets  = array_fill_keys((array) ($o['rejets'] ?? []), true);
    $classes = (array) ($o['classes'] ?? []);
    $nomsN = [];
    foreach ($collaborateurs as $nom => $a) $nomsN[(string) $nom] = rb_norm((string) $nom);

    $debits = array_values(array_filter($ops, fn($x) => $x['sens'] === 'debit' && $x['date'] >= $o['du'] && $x['date'] <= $o['au']));
    usort($debits, fn($a, $b) => [$a['date'], $a['n']] <=> [$b['date'], $b['n']]); // le plus ancien débit prend les plus anciennes pièces
    $prises = [];
    $lots = [];
    foreach ($debits as $op) {
        $t = "$op[libelle] $op[info]";
        foreach ((array) ($o['exclusions'] ?? []) as $re) { if (@preg_match($re, $t)) continue 2; }
        if (isset($classes[rb_cle_classe_debit($op)])) continue;
        $collab = rc_collaborateur($op, $collaborateurs);
        if ($collab === null) continue;
        $siennes = array_values(array_filter($pieces, fn($p) => rb_norm((string) $p['collaborateur']) === $nomsN[$collab] && !isset($prises[$p['id']])));
        $ref = trim((string) $op['ref']);
        $deja = $ref === '' ? [] : array_values(array_filter($siennes, fn($p) => stripos((string) ($p['refActuelle'] ?? ''), $ref) !== false));
        // Décision déjà prise sur ce débit, sans écriture : c'est la passe des paires validées qui s'en charge
        if (!$deja && array_filter($siennes, fn($p) => isset($valides[rb_cle($p['id'], $op)]))) continue;
        $idsDeja = array_column($deja, 'id');
        $cands = array_values(array_filter($siennes, fn($p) => empty($p['paye']) && $p['date'] <= $op['date'] && !in_array($p['id'], $idsDeja, true)
            && !isset($rejets[rb_cle($p['id'], $op)]) && !isset($classes['doc|' . $p['id']])));
        $reste = $op['cents'] - array_sum(array_column($deja, 'cents'));
        $sols = $reste > 0 ? rc_combinaisons($cands, $reste) : [];
        if (!$deja && !$sols) continue;
        $choix = $sols[0] ?? [];
        foreach (array_merge($deja, $choix) as $p) $prises[$p['id']] = true;
        $lots[] = rc_lot($op, $collab, array_merge($deja, $choix), $deja, array_slice($sols, 1, 5), (string) ($o['collId'] ?? ''));
    }
    return $lots;
}

/**
 * Rapport : pièces sans statut « Payé » ni référence bancaire, triées par date, toutes périodes confondues.
 * Les lignes ont la forme de celles de rb_rapprocher ; 'propose' indique le débit qui les rembourse peut-être.
 */
function rc_non_remboursees(array $pieces, array $lots = []): array
{
    $proposees = [];
    foreach ($lots as $lot) {
        foreach ($lot['factures'] as $f) $proposees[$f['resId']] = $lot['debit'];
    }
    $out = [];
    foreach ($pieces as $p) {
        if (!empty($p['paye']) || trim((string) ($p['refActuelle'] ?? '')) !== '') continue;
        $l = [
            'resId' => $p['id'], 'fournisseur' => $p['fournisseur'], 'ref' => $p['ref'], 'date' => $p['date'], 'echeance' => null, 'ttc' => rb_eur($p['cents']),
            'statut' => ($p['statut'] ?? '') !== '' ? $p['statut'] : '(vide)', 'source' => $p['source'] === 'ik' ? 'Indemnités km' : 'Note de frais avancée',
            'collaborateur' => $p['collaborateur'], 'datePaiementActuelle' => (string) ($p['dpActuelle'] ?? ''), 'refBancaireActuelle' => '',
        ];
        if (($p['commentaire'] ?? '') !== '') $l['commentaire'] = $p['commentaire'];
        if (isset($proposees[$p['id']])) $l['propose'] = ['date' => $proposees[$p['id']]['date'], 'libelle' => $proposees[$p['id']]['libelle'], 'montant' => $proposees[$p['id']]['montant']];
        $out[] = $l;
    }
    usort($out, fn($a, $b) => [$a['date'], $a['resId']] <=> [$b['date'], $b['resId']]);
    return $out;
}
