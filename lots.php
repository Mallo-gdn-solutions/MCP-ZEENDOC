<?php
/**
 * Paiements groupés : 1 débit bancaire ↔ N factures — module de rapprochement GDN
 * Fonctions pures (aucun appel réseau) : association débit ↔ paiement, puis constitution du lot.
 */
declare(strict_types=1);

require_once __DIR__ . '/rapprochement.php';
require_once __DIR__ . '/reglement.php';

/** Date + $n jours ouvrés (samedi et dimanche exclus ; jours fériés non gérés) */
function lot_ajout_ouvres(string $d, int $n): string
{
    $t = strtotime($d);
    while ($n > 0) {
        $t = strtotime('+1 day', $t);
        if ((int) date('N', $t) <= 5) $n--;
    }
    return date('Y-m-d', $t);
}

/**
 * Associe chaque débit à un paiement groupé :
 *   1. identifiant de transaction recopié sur le relevé (ex. « b398aca8…-ZEENDOC / GDN SOLUTIONS - FA1504 ») ;
 *   2. sinon même montant au centime, date du paiement ≤ date du débit ≤ date du paiement + $jours jours ouvrés,
 *      et bénéficiaire reconnu dans le libellé bancaire (alias_fournisseurs.json).
 * Un paiement ne sert qu'à un seul débit.
 * @return array{associes: array<int, array{paiement: array, methode: string}>, ambigus: array}
 */
function lot_associer(array $debits, array $paiements, array $alias, int $jours = 5): array
{
    $aliasN = [];
    foreach ($alias as $k => $v) $aliasN[rb_norm((string) $k)] = (array) $v;
    $parTx = [];
    foreach ($paiements as $p) $parTx[strtolower((string) $p['transaction'])] = $p;
    $pris = [];
    $associes = [];
    $ambigus = [];

    foreach ($debits as $op) {
        if (!preg_match_all('/\b[0-9a-f]{32}\b/i', "$op[info] $op[libelle]", $m)) continue;
        foreach ($m[0] as $tx) {
            $tx = strtolower($tx);
            if (isset($parTx[$tx]) && !isset($pris[$tx])) {
                $associes[$op['n']] = ['paiement' => $parTx[$tx], 'methode' => 'identifiant de transaction sur le relevé'];
                $pris[$tx] = true;
                break;
            }
        }
    }
    foreach ($debits as $op) {
        if (isset($associes[$op['n']])) continue;
        $cands = array_values(array_filter($paiements, fn($p) => !isset($pris[strtolower((string) $p['transaction'])])
            && $p['cents'] === $op['cents'] && $p['date'] <= $op['date'] && $op['date'] <= lot_ajout_ouvres($p['date'], $jours)
            && rb_meme_fournisseur(['fournisseur' => $p['beneficiaire']], $op, $aliasN)));
        if (!$cands) continue;
        usort($cands, fn($a, $b) => strcmp($b['date'], $a['date'])); // le plus proche du débit d'abord
        if (count($cands) > 1 && $cands[0]['date'] === $cands[1]['date']) {
            $ambigus[] = ['debit' => $op['date'] . ' ' . $op['libelle'] . ' ' . rb_eur($op['cents']) . ' €', 'transactions' => array_column($cands, 'transaction')];
            continue;
        }
        $associes[$op['n']] = ['paiement' => $cands[0], 'methode' => 'montant + date + bénéficiaire'];
        $pris[strtolower((string) $cands[0]['transaction'])] = true;
    }
    return ['associes' => $associes, 'ambigus' => $ambigus];
}

/**
 * Constitue le lot « 1 débit ↔ N factures » et le contrôle.
 * $parRef : n° de facture compacté (rb_compact) → [factures] ; une facture = ['id','fournisseur','ref','cents','date','statut','dpActuelle','refActuelle']
 * etat : complet (somme = débit) · incomplet (référence introuvable dans Zeendoc) · ecart (somme ≠ débit)
 */
function lot_construire(array $op, array $paiement, string $methode, array $parRef): array
{
    $factures = [];
    $manquantes = [];
    $total = 0;
    foreach ($paiement['factures'] as $ref) {
        $docs = $parRef[rb_compact((string) $ref)] ?? [];
        if (!$docs) { $manquantes[] = $ref; continue; }
        $f = $docs[0];
        $total += $f['cents'];
        $factures[] = [
            'resId' => (string) $f['id'], 'ref' => $f['ref'], 'refPaiement' => $ref, 'fournisseur' => $f['fournisseur'],
            'date' => $f['date'], 'ttc' => rb_eur($f['cents']), 'cents' => $f['cents'],
            'statut' => $f['statut'] ?? '', 'datePaiementActuelle' => rb_date((string) ($f['dpActuelle'] ?? '')) ?? '', 'refBancaireActuelle' => (string) ($f['refActuelle'] ?? ''),
            'plusieurs' => count($docs) > 1 ? count($docs) : 0, 'commentaire' => $f['commentaire'] ?? '',
        ];
    }
    $ecart = $op['cents'] - $total;
    $lot = [
        'cle'         => 'lot|' . $paiement['transaction'],
        'transaction' => $paiement['transaction'],
        'source'      => $paiement['source'],
        'preuve'      => $paiement['preuve'],
        'methode'     => $methode,
        'debit'       => ['n' => $op['n'], 'date' => $op['date'], 'libelle' => $op['libelle'], 'ref' => $op['ref'], 'montant' => rb_eur($op['cents']), 'cents' => $op['cents']],
        'paiement'    => ['date' => $paiement['date'], 'beneficiaire' => $paiement['beneficiaire'], 'reference' => $paiement['reference'], 'montant' => rb_eur($paiement['cents'])],
        'factures'    => $factures,
        'manquantes'  => $manquantes,
        'total'       => rb_eur($total),
        'ecart'       => ($ecart < 0 ? '-' : '') . rb_eur(abs($ecart)),
        'etat'        => $manquantes ? 'incomplet' : ($ecart !== 0 ? 'ecart' : 'complet'),
        // Ce qui identifie CE paiement dans une référence bancaire déjà saisie (pas un double paiement)
        'jetons'      => array_values(array_filter([$paiement['transaction'], $op['ref']], 'strlen')),
    ];
    // Proposition d'écriture, facture par facture, à partir des valeurs lues dans Zeendoc
    $cible = rg_cible($lot);
    foreach ($lot['factures'] as $k => $f) {
        $lot['factures'][$k]['comparaison'] = rg_comparer(
            ['statut' => $f['statut'], 'datePaiement' => $f['datePaiementActuelle'], 'refBancaire' => $f['refBancaireActuelle']], $cible, $lot['jetons']);
    }
    return $lot;
}
