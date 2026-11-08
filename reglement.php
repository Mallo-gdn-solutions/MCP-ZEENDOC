<?php
/**
 * Règles d'écriture d'un règlement dans Zeendoc — module de rapprochement GDN
 * Fonctions pures : comparaison Zeendoc / banque champ par champ, décision d'écrire ou non.
 *
 * Trois cas par champ (statut, date de paiement, référence bancaire) :
 *   vide      → on écrit la valeur de la banque
 *   identique → rien à faire
 *   conflit   → « Zeendoc : X / Banque : Y », rien n'est écrit sur la facture tant que l'utilisateur n'a pas choisi
 * Double paiement : la référence bancaire contient déjà un AUTRE paiement → facture bloquée, aucune écriture.
 */
declare(strict_types=1);

require_once __DIR__ . '/rapprochement.php';

const RG_CHAMPS = ['datePaiement', 'statut', 'refBancaire'];
const RG_LIBELLES = ['datePaiement' => 'Date de paiement', 'statut' => 'Statut du règlement', 'refBancaire' => 'Référence bancaire'];

/** Valeurs à écrire pour un lot : statut « Payé », date du débit, preuve (« Fintecture <réf.> » ou libellé + date de la ligne) */
function rg_cible(array $lot): array
{
    return ['statut' => 'Payé', 'datePaiement' => $lot['debit']['date'], 'refBancaire' => (string) $lot['preuve']];
}

/** Preuve d'un débit sans source de détail : libellé + date de la ligne bancaire (120 caractères au plus) */
function rg_preuve_ligne(array $op): string
{
    $lib = trim((string) preg_replace('/[^\w\- +\/.]+/u', ' ', (string) $op['libelle']));
    return substr(trim($lib . ' ' . date('d/m/Y', strtotime($op['date']))), 0, 120);
}

function rg_vide(?string $v): bool { $v = trim((string) $v); return $v === '' || $v === '(vide)'; }

/**
 * @param array $actuel ['statut' => libellé, 'datePaiement' => AAAA-MM-JJ, 'refBancaire' => texte] lus dans Zeendoc
 * @param array $cible  mêmes clés, valeurs issues de la banque
 * @param array $jetons ce qui identifie ce paiement dans une référence existante (n° de transaction, réf. de la ligne bancaire)
 * @return array ['champs' => [champ => ['zeendoc','banque','cas']], 'doublePaiement' => bool]
 */
function rg_comparer(array $actuel, array $cible, array $jetons): array
{
    $champs = [];
    $double = false;
    foreach (RG_CHAMPS as $c) {
        $z = trim((string) ($actuel[$c] ?? ''));
        $b = trim((string) ($cible[$c] ?? ''));
        if (rg_vide($z)) $cas = 'vide';
        elseif ($c === 'datePaiement') $cas = (rb_date($z) ?? $z) === $b ? 'identique' : 'conflit';
        elseif ($c === 'statut') $cas = strcasecmp($z, $b) === 0 ? 'identique' : 'conflit';
        else {
            // Même paiement déjà noté autrement (ex. réf. de la ligne bancaire au lieu du n° Fintecture) : rien à faire
            $meme = strcasecmp($z, $b) === 0;
            foreach ($jetons as $j) { if ($j !== '' && stripos($z, (string) $j) !== false) $meme = true; }
            $cas = $meme ? 'identique' : 'conflit';
            if (!$meme) $double = true;
        }
        $champs[$c] = ['zeendoc' => rg_vide($z) ? '' : $z, 'banque' => $b, 'cas' => $cas];
    }
    return ['champs' => $champs, 'doublePaiement' => $double];
}

/**
 * Décision pour une facture.
 * @param array $choix champ => 'banque' | 'zeendoc' (réponse de l'utilisateur à un conflit)
 * @return array ['etat' => a_ecrire|a_jour|conflit|bloque, 'ecrire' => [champ => valeur], 'enAttente' => [champs], 'motif' => ?string]
 */
function rg_decider(array $comparaison, array $choix = [], bool $remplacerReference = false): array
{
    // $remplacerReference : résolution manuelle (bouton « Résoudre ») où l'utilisateur a vu la référence existante
    // et choisi explicitement de la remplacer — le double paiement redevient un simple conflit tranché
    if ($comparaison['doublePaiement'] && !($remplacerReference && ($choix['refBancaire'] ?? '') === 'banque')) {
        $z = $comparaison['champs']['refBancaire']['zeendoc'];
        return ['etat' => 'bloque', 'ecrire' => [], 'enAttente' => [],
                'motif' => "Double paiement possible : la référence bancaire contient déjà « $z ». Facture présente dans deux paiements : à vérifier dans Zeendoc, rien n'est écrit."];
    }
    $ecrire = [];
    $attente = [];
    foreach ($comparaison['champs'] as $c => $x) {
        if ($x['cas'] === 'vide') $ecrire[$c] = $x['banque'];
        elseif ($x['cas'] === 'conflit') {
            $ch = $choix[$c] ?? '';
            if ($ch === 'banque') $ecrire[$c] = $x['banque'];
            elseif ($ch !== 'zeendoc') $attente[] = $c;
        }
    }
    if ($attente) {
        $txt = implode(' ; ', array_map(fn($c) => RG_LIBELLES[$c] . ' — Zeendoc : ' . $comparaison['champs'][$c]['zeendoc'] . ' / Banque : ' . $comparaison['champs'][$c]['banque'], $attente));
        return ['etat' => 'conflit', 'ecrire' => [], 'enAttente' => $attente, 'motif' => "Conflit à trancher : $txt"];
    }
    return ['etat' => $ecrire ? 'a_ecrire' : 'a_jour', 'ecrire' => $ecrire, 'enAttente' => [], 'motif' => null];
}

/* ------------------------------------------------------------
 * Résolution manuelle (bouton « Résoudre » du module)
 * ------------------------------------------------------------ */

/** Motifs d'écart proposés à l'utilisateur => texte ajouté à la référence bancaire */
const RG_MOTIFS = ['compensation' => 'compensation', 'avoir' => 'avoir', 'escompte' => 'escompte', 'hors_releve' => 'hors releve', 'autre' => ''];

/** Référence bancaire acceptée par Zeendoc (ecriture.php) : lettres, chiffres, espace + - / . ; 120 caractères au plus */
function rg_nettoyer_ref(string $t): string
{
    $t = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\w\- +\/.]+/u', ' ', $t)));
    return preg_match('/^.{0,120}/us', $t, $m) ? rtrim($m[0]) : '';
}

/**
 * Ce qu'écrit une résolution manuelle.
 * @param array  $ops   lignes du relevé choisies (rb_lire_csv)
 * @param string $motif clé de RG_MOTIFS ou '' (pas d'écart à justifier)
 * @param string $date  date du paiement hors relevé (AAAA-MM-JJ), utilisée seulement sans ligne
 * @return array ['cible' => [statut, datePaiement, refBancaire], 'justification' => texte lisible] ou ['erreur' => ...]
 */
function rg_resolution(array $ops, string $motif, string $texte, string $date = ''): array
{
    if ($motif !== '' && !isset(RG_MOTIFS[$motif])) return ['erreur' => 'Motif inconnu'];
    if (!$ops && $motif !== 'hors_releve') return ['erreur' => 'Choisissez au moins une ligne du relevé, ou le motif « payé hors de ce compte ».'];
    if (!$ops && !rb_date($date)) return ['erreur' => 'Indiquez la date du paiement hors relevé.'];
    $dates = array_column($ops, 'date');
    sort($dates);
    $dp = $ops ? end($dates) : (string) rb_date($date);
    $refs = array_map(fn($op) => trim((string) $op['ref']) !== '' ? (string) $op['ref'] : rg_preuve_ligne($op), $ops);
    $ref = rg_nettoyer_ref(implode(' + ', array_filter(array_merge($refs, [trim(($motif !== '' ? RG_MOTIFS[$motif] : '') . ' ' . $texte)]), 'strlen')));
    if ($ref === '') return ['erreur' => 'Précisez le motif (texte) : la référence bancaire serait vide.'];
    $justif = trim(($motif !== '' ? 'Motif : ' . $motif . '. ' : '') . $texte);
    return ['cible' => ['statut' => 'Payé', 'datePaiement' => $dp, 'refBancaire' => $ref], 'justification' => $justif];
}
