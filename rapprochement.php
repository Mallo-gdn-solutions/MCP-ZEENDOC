<?php
/**
 * Moteur de rapprochement factures fournisseurs <-> relevé bancaire (CSV)
 * GDN Solutions — utilisé par zeendoc-mcp.php (outil zeendoc_rapprocher)
 *
 * Fonctions pures : aucune écriture, aucun appel réseau.
 * Montants manipulés en centimes (entiers) pour éviter les erreurs d'arrondi.
 */

declare(strict_types=1);

require_once __DIR__ . '/noyau/outils.php'; // rb_utf8, rb_norm, rb_date, rb_cents, rb_eur… (déplacées dans le noyau)

/* ------------------------------------------------------------
 * Lecture du CSV bancaire (export Cyberplus Banque Populaire, ou proche)
 * ------------------------------------------------------------ */

function rb_lire_csv(string $contenu): array
{
    $contenu = rb_utf8($contenu);
    $contenu = preg_replace('/^\xEF\xBB\xBF/', '', $contenu);
    $lignes = preg_split('/\r\n|\r|\n/', $contenu);

    // Séparateur : celui qui apparaît le plus dans la première ligne non vide
    $premiere = '';
    foreach ($lignes as $l) { if (trim($l) !== '') { $premiere = $l; break; } }
    $sep = ';';
    $max = -1;
    foreach ([';', ',', "\t"] as $s) {
        $n = substr_count($premiere, $s);
        if ($n > $max) { $max = $n; $sep = $s; }
    }

    // Repérer l'en-tête (ligne contenant débit et crédit, ou montant)
    $entete = null;
    $col = [];
    foreach ($lignes as $i => $l) {
        $cells = str_getcsv($l, $sep, '"', '\\');
        $noms = array_map(fn($x) => rb_norm((string) $x), $cells);
        $joint = ' ' . implode(' | ', $noms) . ' ';
        if ((strpos($joint, 'DEBIT') !== false && strpos($joint, 'CREDIT') !== false) || strpos($joint, 'MONTANT') !== false) {
            $entete = $i;
            foreach ($noms as $k => $nm) {
                if ($nm === 'DATE OPERATION' || $nm === 'DATE D OPERATION') $col['date_op'] = $k;
                elseif ($nm === 'DATE COMPTABLE' || $nm === 'DATE') $col['date_cpt'] = $col['date_cpt'] ?? $k;
                elseif (strpos($nm, 'LIBELLE') === 0) $col['libelle'] = $col['libelle'] ?? $k;
                elseif ($nm === 'REFERENCE') $col['ref'] = $k;
                elseif (strpos($nm, 'INFORMATION') === 0) $col['info'] = $k;
                elseif (strpos($nm, 'TYPE') === 0) $col['type'] = $k;
                elseif ($nm === 'DEBIT') $col['debit'] = $k;
                elseif ($nm === 'CREDIT') $col['credit'] = $k;
                elseif ($nm === 'MONTANT') $col['montant'] = $k;
            }
            break;
        }
    }
    if ($entete === null || !isset($col['libelle'])) {
        throw new RuntimeException("CSV bancaire non reconnu : en-tête avec Libellé + Débit/Crédit (ou Montant) introuvable");
    }

    $ops = [];
    foreach (array_slice($lignes, $entete + 1) as $l) {
        if (trim($l) === '') continue;
        $c = str_getcsv($l, $sep, '"', '\\');
        $date = rb_date($c[$col['date_op'] ?? -1] ?? null) ?? rb_date($c[$col['date_cpt'] ?? -1] ?? null);
        if (!$date) continue;
        if (isset($col['montant'])) {
            $brut = trim((string) ($c[$col['montant']] ?? ''));
            $sens = str_starts_with($brut, '-') ? 'debit' : 'credit';
            $cents = rb_cents($brut);
        } else {
            $d = rb_cents($c[$col['debit'] ?? -1] ?? '');
            $cr = rb_cents($c[$col['credit'] ?? -1] ?? '');
            $sens = $d > 0 ? 'debit' : 'credit';
            $cents = $d > 0 ? $d : $cr;
        }
        if ($cents === 0) continue;
        $libelle = trim((string) ($c[$col['libelle']] ?? ''));
        $info    = trim((string) ($c[$col['info'] ?? -1] ?? ''));
        $ops[] = [
            'n'       => count($ops),
            'date'    => $date,
            'libelle' => $libelle,
            'ref'     => trim((string) ($c[$col['ref'] ?? -1] ?? '')),
            'info'    => $info,
            'type'    => trim((string) ($c[$col['type'] ?? -1] ?? '')),
            'sens'    => $sens,
            'cents'   => $cents,
            'texte'   => rb_norm("$libelle $info"),
        ];
    }
    return $ops;
}

/* ------------------------------------------------------------
 * Correspondance fournisseur <-> libellé bancaire
 * ------------------------------------------------------------ */

const RB_MOTS_VIDES = ['SAS', 'SARL', 'SA', 'SASP', 'SASU', 'EURL', 'SCI', 'SNC', 'FR', 'ET', 'DE', 'DU', 'LA', 'LE', 'LES', 'DES', 'STE', 'SOCIETE', 'GROUPE', 'FRANCE'];

/** Mots significatifs d'un nom de fournisseur */
function rb_mots(string $nom): array
{
    $mots = [];
    foreach (explode(' ', rb_norm($nom)) as $m) {
        if (strlen($m) >= 3 && !in_array($m, RB_MOTS_VIDES, true) && !ctype_digit($m)) $mots[] = $m;
    }
    return $mots;
}

/** true si le libellé bancaire évoque le fournisseur (nom ou alias) */
function rb_meme_fournisseur(array $f, array $op, array $alias): bool
{
    $t = ' ' . $op['texte'] . ' ';
    foreach (rb_mots($f['fournisseur']) as $m) {
        if (strpos($t, " $m ") !== false) return true;
    }
    $cle = rb_norm($f['fournisseur']);
    foreach ($alias[$cle] ?? [] as $a) {
        $a = rb_norm($a);
        if ($a !== '' && strpos($t, " $a ") !== false) return true;
    }
    return false;
}

/** 'exacte' si la référence facture figure dans le libellé, 'prefixe' si le libellé en contient le début (≥ 6 car.) */
function rb_ref(array $f, array $op): ?string
{
    $ref = rb_compact($f['ref']);
    if (strlen($ref) < 5) return null;
    $compact = str_replace(' ', '', $op['texte']);
    if (strpos($compact, $ref) !== false) return 'exacte';
    // N° de facture abrégé par le payeur : FAC20250037 cité « F20250037 » ou « 20250037 »
    if (preg_match('/(\d{6,})$/', $ref, $m) && preg_match('/(?<!\d)' . $m[1] . '(?!\d)/', $op['texte'])) return 'numero';
    foreach (explode(' ', $op['texte']) as $tok) {
        if (strlen($tok) >= 6 && preg_match('/^[A-Z]{1,4}\d{3,}$/', $tok) && str_starts_with($ref, $tok)) return 'prefixe';
    }
    return null;
}

/* ------------------------------------------------------------
 * Rapprochement
 * ------------------------------------------------------------ */

/**
 * @param array $factures  [{id, fournisseur, date, ref, cents, echeance, mode, paye(bool), statut}]
 * @param array $ops       résultat de rb_lire_csv
 * @param array $o         du, au (période des factures à analyser), aujourdhui, tolerance (jours),
 *                         alias [FOURNISSEUR NORMALISÉ => [mots banque]], exclusions [regex sur libellé],
 *                         remboursements (propositions de rc_proposer), nonRemboursees (rapport rc_non_remboursees)
 */
function rb_rapprocher(array $factures, array $ops, array $o): array
{
    $tol    = (int) ($o['tolerance'] ?? 25);
    $alias  = [];
    foreach (($o['alias'] ?? []) as $k => $v) $alias[rb_norm((string) $k)] = (array) $v;
    $auj    = $o['aujourdhui'] ?? date('Y-m-d');
    $du     = $o['du'];
    $au     = $o['au'];

    // Débits candidats : hors flux exclus (paie, impôts, cotisations…), qui ne correspondent jamais à une facture
    $excl = $o['exclusions'] ?? [];
    $exclus = 0;
    $debits = [];
    foreach ($ops as $x) {
        // Côté fournisseurs : on rapproche les débits ; côté clients : les crédits (encaissements)
        if ($x['sens'] !== ($o['sensCible'] ?? 'debit')) continue;
        $t = "$x[libelle] $x[info]";
        $skip = false;
        foreach ($excl as $re) { if (@preg_match($re, $t)) { $skip = true; break; } }
        if ($skip) { if ($x['date'] >= $o['du'] && $x['date'] <= $o['au']) $exclus++; continue; }
        $x['carte'] = (bool) preg_match('/\bCB\b|carte/i', "$x[type] $x[info] $x[libelle]");
        $debits[] = $x;
    }
    $opsN = [];
    foreach ($debits as $x) $opsN[$x['n']] = $x;
    // Un ticket de note de frais (CB professionnelle) ne se rapproche que d'un paiement par carte
    // Décisions de l'utilisateur : paires facture/opération validées ou rejetées lors d'un passage précédent
    $valides = array_fill_keys((array) ($o['valides'] ?? []), true);
    $rejets  = array_fill_keys((array) ($o['rejets'] ?? []), true);
    // Éléments classés sans suite : clé (doc|<resId> ou rb_cle_classe_debit) => [motif, texte, quand]
    $classes = (array) ($o['classes'] ?? []);
    // ticket CB pro ↔ paiement carte uniquement ;
    // IK et notes de frais avancées : jamais par les passes génériques (pièces chargées sans limite d'ancienneté),
    // seulement par une paire validée ou la règle « remboursement collaborateur » (remboursements.php)
    $ok = function (array $f, array $op) use ($rejets): bool {
        $src = $f['source'] ?? 'facture';
        if ($src === 'ndf' && !$op['carte']) return false;
        if ($src === 'ik' || $src === 'remb') return false;
        return !isset($rejets[rb_cle($f['id'], $op)]);
    };
    // ordre chronologique : la plus ancienne opération prend la plus ancienne facture
    usort($debits, fn($a, $b) => [$a['date'], $a['n']] <=> [$b['date'], $b['n']]);
    $opLibre = [];
    foreach ($debits as $d) $opLibre[$d['n']] = true;

    $F = [];
    foreach ($factures as $f) {
        $f['regle'] = 0;           // centimes rapprochés
        $f['ops']   = [];          // [ [n, cents, methode] ]
        $f['fin']   = $f['echeance'] ?: rb_ajout($f['date'], 30);
        $f['id'] = (string) $f['id'];
        $F['f' . $f['id']] = $f;
    }

    $dansFenetre = function (array $f, array $op) use ($tol): bool {
        return $op['date'] >= rb_ajout($f['date'], -5) && $op['date'] <= rb_ajout($f['fin'], $tol);
    };
    $lier = function (string $id, array $op, int $cents, string $methode) use (&$F, &$opLibre) {
        $id = 'f' . $id;
        $F[$id]['regle'] += $cents;
        $F[$id]['ops'][] = [$op['n'], $cents, $methode];
        $opLibre[$op['n']] = false;
    };
    $libre = function (string $id) use (&$F): bool { return $F['f' . $id]['regle'] === 0; };

    // Passe 0 : paires confirmées par l'utilisateur (même si les montants diffèrent)
    // Un même débit peut avoir été validé pour PLUSIEURS documents (ex. IK mars + IK avril réglées par un seul virement) :
    // chaque document reçoit alors son propre montant ; un seul document reçoit le montant du débit.
    if ($valides) {
        foreach ($debits as $op) {
            if (!$opLibre[$op['n']]) continue;
            $docsValides = array_values(array_filter($F, fn($f) => isset($valides[rb_cle($f['id'], $op)])));
            foreach ($docsValides as $f) {
                $lier($f['id'], $op, count($docsValides) > 1 ? $f['cents'] : $op['cents'], 'validé par vous');
            }
        }
    }

    // Passe 0r : remboursements collaborateurs (remboursements.php). Le débit a sa proposition : il sort des orphelins.
    // La pièce n'est rapprochée que si sa référence bancaire est déjà écrite ; sinon elle attend la validation.
    foreach ((array) ($o['remboursements'] ?? []) as $lot) {
        $op = $opsN[$lot['debit']['n']] ?? null;
        if (!$op || !$opLibre[$op['n']]) continue;
        $opLibre[$op['n']] = false;
        if (empty($lot['dejaEcrit'])) continue;
        foreach ($lot['factures'] as $x) {
            if (isset($F['f' . $x['resId']])) $lier((string) $x['resId'], $op, $x['cents'], $lot['methode']);
        }
    }

    // Passe 0b : paiements groupés (lots préparés par lots.php à partir des sources de détail : Fintecture, fichier…)
    // 1 débit = la liste exacte des factures du paiement, cherchées dans Zeendoc sans filtre de statut ni de période
    foreach ((array) ($o['lots'] ?? []) as $lot) {
        $op = $opsN[$lot['debit']['n']] ?? null;
        if (!$op || !$opLibre[$op['n']]) continue;
        $libres = array_values(array_filter($lot['factures'], fn($x) => isset($F['f' . $x['resId']]) && $libre((string) $x['resId'])));
        if (!$libres) continue;
        $methode = $lot['etat'] === 'complet'
            ? "paiement groupé {$lot['source']} (liste exacte)"
            : "paiement groupé {$lot['source']} — " . ($lot['manquantes'] ? 'facture(s) absente(s) de Zeendoc : ' . implode(', ', $lot['manquantes']) : "écart {$lot['ecart']} €") . ' (à valider)';
        foreach ($libres as $x) $lier((string) $x['resId'], $op, $F['f' . $x['resId']]['cents'], $methode);
    }

    // Passe 0b : référence du relevé retrouvée dans le texte OCR de la facture (frais bancaires facturés après prélèvement)
    foreach ($debits as $op) {
        if (!$opLibre[$op['n']] || ($op['ref'] ?? '') === '') continue;
        foreach ($F as $f) {
            if (!$libre($f['id']) || $f['cents'] !== $op['cents'] || !$ok($f, $op)) continue;
            if (in_array($op['ref'], $f['refsOcr'] ?? [], true)) { $lier($f['id'], $op, $op['cents'], 'référence du prélèvement dans la facture + montant'); break; }
        }
    }

    // Passe 0c : la facture cite les références de PLUSIEURS prélèvements du relevé (texte OCR) et leur somme = montant de la facture
    // ex. facture BP du 29/05 (46,39 €) qui reprend 0031639 (cotisation 41,04 €) et 0052495 (frais paiement étranger 5,35 €)
    foreach ($F as $f) {
        if ($f['regle'] > 0 || count($f['refsOcr'] ?? []) < 2) continue;
        $cites = array_values(array_filter($debits, fn($x) => $opLibre[$x['n']] && $ok($f, $x) && in_array($x['ref'], $f['refsOcr'], true)));
        if (count($cites) < 2) continue;
        if (array_sum(array_column($cites, 'cents')) === $f['cents']) {
            $sol = $cites;
        } else {
            $sol = rb_somme(array_slice($cites, 0, 15), $f['cents'], 8);
        }
        if ($sol) {
            foreach ($sol as $x) $lier($f['id'], $x, $x['cents'], 'référence du prélèvement dans la facture + somme de ' . count($sol) . ' débits');
        }
    }

    // Passe 1 : référence (exacte ou préfixe) + montant identique
    foreach ($debits as $op) {
        if (!$opLibre[$op['n']]) continue;
        foreach ($F as $f) {
            if (!$libre($f['id']) || $f['cents'] !== $op['cents'] || !$ok($f, $op)) continue;
            $r = rb_ref($f, $op);
            if ($r) { $lier($f['id'], $op, $op['cents'], "référence $r + montant"); break; }
        }
    }

    // Passe 1b : référence exacte mais montant inférieur (escompte, retenue, déduction d'un avoir) : écart ≤ 15 %
    foreach ($debits as $op) {
        if (!$opLibre[$op['n']]) continue;
        foreach ($F as $f) {
            if (!$libre($f['id']) || !$ok($f, $op) || $op['cents'] >= $f['cents'] || $op['cents'] < $f['cents'] * 0.85) continue;
            if (rb_ref($f, $op) === 'exacte') {
                $lier($f['id'], $op, $op['cents'], 'référence exacte, montant inférieur (écart ' . rb_eur($f['cents'] - $op['cents']) . ' €)');
                break;
            }
        }
    }

    // Passe 2 : montant identique + fenêtre de dates + même fournisseur
    foreach ($debits as $op) {
        if (!$opLibre[$op['n']]) continue;
        $cands = array_filter($F, fn($f) => $libre($f['id']) && $ok($f, $op) && $f['cents'] === $op['cents'] && $dansFenetre($f, $op) && rb_meme_fournisseur($f, $op, $alias));
        if ($cands) {
            usort($cands, fn($a, $b) => [abs(rb_jours($a['fin'], $op['date'])), $a['date']] <=> [abs(rb_jours($b['fin'], $op['date'])), $b['date']]);
            $lier($cands[0]['id'], $op, $op['cents'], 'montant + fournisseur + date');
        }
    }

    // Passe 2b : même fournisseur + montant identique, payé en retard (jusqu'à 120 jours après l'échéance), candidat unique
    foreach ($debits as $op) {
        if (!$opLibre[$op['n']]) continue;
        $cands = array_values(array_filter($F, fn($f) => $libre($f['id']) && $ok($f, $op) && $f['cents'] === $op['cents']
            && $op['date'] >= rb_ajout($f['date'], -5) && $op['date'] <= rb_ajout($f['fin'], 120)
            && rb_meme_fournisseur($f, $op, $alias)));
        if (count($cands) === 1) {
            $lier($cands[0]['id'], $op, $op['cents'], 'montant + fournisseur, payé ' . max(0, rb_jours($cands[0]['fin'], $op['date'])) . ' j après échéance');
        }
    }

    // Passe 2c (côté clients) : encaissé AVANT la date de la facture (ex. Grenke verse, puis GDN émet la facture), jusqu'à 30 j avant
    if (($o['sensCible'] ?? 'debit') === 'credit') {
        foreach ($debits as $op) {
            if (!$opLibre[$op['n']]) continue;
            $cands = array_values(array_filter($F, fn($f) => $libre($f['id']) && $ok($f, $op) && $f['cents'] === $op['cents']
                && $op['date'] < $f['date'] && $op['date'] >= rb_ajout($f['date'], -30) && rb_meme_fournisseur($f, $op, $alias)));
            if (count($cands) === 1) {
                $lier($cands[0]['id'], $op, $op['cents'], 'montant + client, encaissé ' . rb_jours($op['date'], $cands[0]['date']) . ' j avant la facture');
            }
        }
    }

    // Passe 3 : montant identique + fenêtre de dates, fournisseur non reconnu : seulement si unique dans les deux sens
    foreach ($debits as $op) {
        if (!$opLibre[$op['n']]) continue;
        $cands = array_values(array_filter($F, fn($f) => $libre($f['id']) && $ok($f, $op) && $f['cents'] === $op['cents'] && $dansFenetre($f, $op)));
        if (count($cands) !== 1) continue;
        $f = $cands[0];
        $autres = array_filter($debits, fn($x) => $opLibre[$x['n']] && $ok($f, $x) && $x['n'] !== $op['n'] && $x['cents'] === $f['cents'] && $dansFenetre($f, $x));
        if (!$autres) $lier($f['id'], $op, $op['cents'], 'montant + date (fournisseur à confirmer)');
    }

    // Passe 4 : virement groupé = somme exacte de plusieurs factures du même fournisseur
    $groupes = [];
    foreach ($debits as $op) {
        if (!$opLibre[$op['n']]) continue;
        $cands = array_values(array_filter($F, fn($f) => $libre($f['id']) && $ok($f, $op)
            && $f['cents'] < $op['cents']
            && $f['date'] <= rb_ajout($op['date'], 3) && $f['date'] >= rb_ajout($op['date'], -($f['groupeJours'] ?? 120))
            && (rb_meme_fournisseur($f, $op, $alias) || rb_ref($f, $op))));
        if (count($cands) < 2) continue;
        usort($cands, fn($a, $b) => strcmp($b['date'], $a['date']));
        $cands = array_slice($cands, 0, 18);
        $sol = rb_somme($cands, $op['cents'], 8);
        if ($sol) {
            foreach ($sol as $f) $lier($f['id'], $op, $f['cents'], 'virement groupé (à valider)');
            $groupes[] = ['op' => $op['n'], 'factures' => array_column($sol, 'id')];
        }
    }

    // Passe 4a : facture récapitulative de frais bancaires (Banque Populaire…) = somme de plusieurs débits de frais du mois
    // ex. facture du 29/05 à 46,39 € = cotisation Rythmeo 41,04 € (17/05) + frais paiement étranger 5,35 € (06/05)
    $banques = (array) ($o['fournisseursFraisBancaires'] ?? []); // module-config.json : banque.fournisseursFraisBancaires
    $estBanque = function (array $f) use ($banques): bool {
        foreach ($banques as $b) { if ($b !== '' && stripos((string) $f['fournisseur'], $b) !== false) return true; }
        return false;
    };
    foreach ($F as $f) {
        if ($f['regle'] > 0 || !$estBanque($f)) continue;
        $cands = array_values(array_filter($debits, fn($x) => $opLibre[$x['n']] && $ok($f, $x) && $x['cents'] < $f['cents']
            && $x['date'] >= rb_ajout($f['date'], -40) && $x['date'] <= rb_ajout($f['date'], 5)
            && (preg_match('/frais|extourne|commission|cotisation|agios/i', "$x[type] $x[libelle]") || rb_meme_fournisseur($f, $x, $alias))));
        if (count($cands) < 2) continue;
        $sol = rb_somme(array_slice($cands, 0, 15), $f['cents'], 6);
        if ($sol) {
            foreach ($sol as $x) $lier($f['id'], $x, $x['cents'], 'frais bancaires du mois regroupés (à valider)');
        }
    }

    // Passe 4b : facture réglée en plusieurs virements de montants différents (acompte + solde…)
    foreach ($F as $f) {
        if ($f['regle'] > 0) continue;
        $cands = array_values(array_filter($debits, fn($x) => $opLibre[$x['n']] && $ok($f, $x) && $x['cents'] < $f['cents']
            && $x['date'] >= rb_ajout($f['date'], -10) && $x['date'] <= rb_ajout($f['fin'], 120)
            && (rb_meme_fournisseur($f, $x, $alias) || rb_ref($f, $x))));
        if (count($cands) < 2) continue;
        $sol = rb_somme(array_slice($cands, 0, 15), $f['cents'], 4);
        if ($sol) {
            foreach ($sol as $x) $lier($f['id'], $x, $x['cents'], 'paiement en plusieurs fois (à valider)');
        }
    }

    // Passe 4c : acompte(s) + solde. Ancrage = débit au nom du fournisseur (ou déjà validé) ;
    // les autres débits doivent partager la ville du commerçant, le fournisseur ou la référence ;
    // fenêtre : jusqu'à 90 j AVANT le document (acomptes) et jusqu'à l'échéance + tolérance (solde).
    foreach ($F as $f0) {
        $f = $F['f' . $f0['id']];
        if ($f['regle'] >= $f['cents']) continue;
        $dans = fn($x) => $x['date'] >= rb_ajout($f['date'], -90) && $x['date'] <= rb_ajout($f['fin'], $tol);
        $libres = array_values(array_filter($debits, fn($x) => $opLibre[$x['n']] && $ok($f, $x) && $dans($x) && $x['cents'] <= $f['cents'] - $f['regle']));
        if (!$libres) continue;
        $lieux = [];
        foreach ($f['ops'] as $p) $lieux = array_merge($lieux, rb_lieux($opsN[$p[0]]));
        $ancres = array_values(array_filter($libres, fn($x) => rb_meme_fournisseur($f, $x, $alias) || rb_ref($f, $x)));
        foreach ($ancres as $x) $lieux = array_merge($lieux, rb_lieux($x));
        $parents = array_values(array_filter($libres, fn($x) => rb_meme_fournisseur($f, $x, $alias) || rb_ref($f, $x) || array_intersect(rb_lieux($x), $lieux)));
        $sol = null;
        $reste = $f['cents'] - $f['regle'];
        if ($f['regle'] > 0) {
            // déjà un ou des paiements : on cherche le complément parmi les débits parents
            $sol = rb_somme(array_slice($parents, 0, 15), $reste, 3, 1);
        } else {
            foreach ($ancres as $a) {
                $autres = array_values(array_filter($parents, fn($x) => $x['n'] !== $a['n']));
                $s2 = rb_somme(array_slice($autres, 0, 15), $reste - $a['cents'], 3, 1);
                if ($s2) { $sol = array_merge([$a], $s2); break; }
            }
        }
        if ($sol) {
            foreach ($sol as $x) $lier($f['id'], $x, $x['cents'], 'acompte + solde (à valider)');
        }
    }

    // Passe 5 : paiement échelonné (montant = TTC / k)
    foreach ($debits as $op) {
        if (!$opLibre[$op['n']]) continue;
        foreach ($F as $f) {
            if ($f['regle'] >= $f['cents'] || !$ok($f, $op) || $op['date'] < rb_ajout($f['date'], -5)) continue;
            if (!rb_meme_fournisseur($f, $op, $alias) && !rb_ref($f, $op)) continue;
            for ($k = 2; $k <= 12; $k++) {
                if (abs(intdiv($f['cents'] + intdiv($k, 2), $k) - $op['cents']) <= 1) {
                    $lier($f['id'], $op, $op['cents'], "échéance 1/$k");
                    break 2;
                }
            }
        }
    }

    // Documents que l'utilisateur a déclarés « payés » malgré un écart (ex. acompte payé hors compte, sans justificatif)
    foreach ((array) ($o['payes'] ?? []) as $idPaye) {
        $k = 'f' . $idPaye;
        if (isset($F[$k]) && $F[$k]['regle'] > 0 && $F[$k]['regle'] < $F[$k]['cents']) {
            $F[$k]['ecart'] = rb_eur($F[$k]['cents'] - $F[$k]['regle']) . ' (payé déclaré par vous)';
            $F[$k]['regle'] = $F[$k]['cents'];
        }
    }

    // Paires validées par l'utilisateur dont le total ne tombe pas juste : on les accepte en signalant l'écart
    foreach ($F as $k => $f) {
        if ($f['ops'] && $f['regle'] !== $f['cents'] && !array_filter($f['ops'], fn($p) => $p[2] !== 'validé par vous')) {
            $ecart = abs($f['cents'] - $f['regle']);
            $F[$k]['ecart'] = rb_eur($ecart);
            // petit écart (≤ 1 € ou ≤ 2 %) : arrondi, pourboire, frais… on considère le document réglé ;
            // au-delà, il reste un paiement à trouver : le document reste en « paiement partiel »
            if ($f['regle'] > $f['cents'] || $ecart <= max(100, (int) round($f['cents'] * 0.02))) {
                $F[$k]['regle'] = $f['cents'];
            }
        }
    }

    /* ---------- Classement ---------- */
    $opsParN = [];
    foreach ($ops as $x) $opsParN[$x['n']] = $x;
    $desc = fn(array $op) => ['date' => $op['date'], 'libelle' => $op['libelle'], 'montant' => rb_eur($op['cents']), 'ref' => $op['ref'], 'sens' => $op['sens'] ?? 'debit'];

    $res = [
        'rapprochees'           => [],
        'partielles'            => [],
        'payees_sans_debit'     => [],
        'echues_non_reglees'    => [],
        'non_echues'            => [],
        'statuts_a_corriger'    => [],
        'debits_sans_facture'   => [],
        'ndf_sans_debit'        => [],
        'ik_non_remboursees'    => [],
        'classes_sans_suite'    => [],
    ];

    foreach ($F as $f) {
        if ($f['date'] < $du || $f['date'] > $au) continue; // facture hors période : sert seulement de candidate
        $ligne = [
            'resId'       => $f['id'],
            'fournisseur' => $f['fournisseur'],
            'ref'         => $f['ref'],
            'date'        => $f['date'],
            'echeance'    => $f['echeance'],
            'ttc'         => rb_eur($f['cents']),
            'statut'      => $f['statut'] ?: '(vide)',
            'source'      => ['ndf' => 'Note de frais', 'ik' => 'Indemnités km', 'remb' => 'Note de frais avancée'][$f['source'] ?? ''] ?? 'Facture',
            'datePaiementActuelle' => $f['dpActuelle'] ?? '',
            'refBancaireActuelle'  => $f['refActuelle'] ?? '',
        ];
        if (!empty($f['avoirs'])) { $ligne['avoirs'] = $f['avoirs']; $ligne['ttcBrut'] = rb_eur($f['centsBrut']); }
        if (($f['commentaire'] ?? '') !== '') $ligne['commentaire'] = $f['commentaire']; // index « Commentaire » saisi dans Zeendoc
        if ($f['ops']) {
            $ligne['paiements'] = array_map(function ($p) use ($desc, $opsParN, $f, $valides) {
                $op = $opsParN[$p[0]];
                $cle = rb_cle($f['id'], $op);
                $methode = isset($valides[$cle]) ? 'validé par vous' : $p[2];
                return $desc($op) + ['methode' => $methode, 'impute' => rb_eur($p[1]), 'cle' => $cle];
            }, $f['ops']);
        }
        if (!empty($f['ecart'])) $ligne['ecart'] = $f['ecart'];
        if (in_array($f['source'] ?? 'facture', ['ndf', 'ik', 'remb'], true)) {
            // Ticket de note de frais payé par CB pro : on cherche seulement son débit, pas de statut à proposer
            // (IK et notes de frais avancées sans débit : rapport « nonRemboursees », indépendant de la période)
            if ($f['regle'] >= $f['cents']) $res['rapprochees'][] = $ligne;
            elseif ($f['regle'] > 0) { $ligne['reste'] = rb_eur($f['cents'] - $f['regle']); $res['partielles'][] = $ligne; }
            elseif (($f['source'] ?? '') === 'ndf') $res['ndf_sans_debit'][] = $ligne;
            continue;
        }
        if ($f['regle'] >= $f['cents']) {
            $res['rapprochees'][] = $ligne;
            if (!$f['paye']) $res['statuts_a_corriger'][] = ['resId' => $f['id'], 'facture' => "$f[fournisseur] $f[ref]", 'actuel' => $ligne['statut'], 'propose' => 'Payé', 'datePaiement' => $opsParN[end($f['ops'])[0]]['date']];
        } elseif ($f['regle'] > 0) {
            $ligne['reste'] = rb_eur($f['cents'] - $f['regle']);
            $res['partielles'][] = $ligne;
            $res['statuts_a_corriger'][] = ['resId' => $f['id'], 'facture' => "$f[fournisseur] $f[ref]", 'actuel' => $ligne['statut'], 'propose' => 'Paiement partiel'];
        } elseif ($f['paye']) {
            $res['payees_sans_debit'][] = $ligne;
        } elseif ($f['fin'] < $auj) {
            $res['echues_non_reglees'][] = $ligne;
        } else {
            $res['non_echues'][] = $ligne;
        }
    }

    foreach ($debits as $op) {
        if (!$opLibre[$op['n']] || $op['date'] < $du || $op['date'] > $au) continue;
        // Débit classé sans suite par l'utilisateur (justificatif perdu…) : sort du tableau, reste listé à part
        if (isset($classes[rb_cle_classe_debit($op)])) {
            $res['classes_sans_suite'][] = $desc($op) + ['type' => 'debit', 'cle' => rb_cle_classe_debit($op)] + $classes[rb_cle_classe_debit($op)];
            continue;
        }
        // Documents possibles : non rapprochés, même fournisseur, ou montant proche (±10 %) à quelques jours près
        $sugg = [];
        foreach ($F as $f) {
            if ($f['regle'] > 0 || !$ok($f, $op)) continue;
            $memeF = rb_meme_fournisseur($f, $op, $alias) || rb_ref($f, $op);
            $jours = rb_jours($f['date'], $op['date']);
            $proche = abs($f['cents'] - $op['cents']) <= max(100, (int) ($op['cents'] * 0.10)) && $jours >= -5 && $jours <= 15;
            if (!(($memeF && $jours >= -15 && $jours <= 90) || $proche)) continue;
            $sugg[] = [
                'score'       => ($memeF ? 0 : 1000) + abs($f['cents'] - $op['cents']) / 100 + abs($jours),
                'resId'       => $f['id'],
                'source'      => ($f['source'] ?? 'facture') === 'ndf' ? 'Note de frais' : 'Facture',
                'fournisseur' => $f['fournisseur'],
                'ref'         => $f['ref'],
                'date'        => $f['date'],
                'ttc'         => rb_eur($f['cents']),
                'raison'      => $memeF ? 'même fournisseur' : 'montant proche',
                'commentaire' => $f['commentaire'] ?? '',
                'cle'         => rb_cle($f['id'], $op),
            ];
        }
        usort($sugg, fn($a, $b) => $a['score'] <=> $b['score']);
        $sugg = array_map(function ($x) { unset($x['score']); return $x; }, array_slice($sugg, 0, 3));
        $res['debits_sans_facture'][] = $desc($op) + ['type' => $op['type'], 'suggestions' => $sugg, 'cleClasse' => rb_cle_classe_debit($op)];
    }

    $res['ik_non_remboursees'] = array_values((array) ($o['nonRemboursees'] ?? []));

    // Documents classés sans suite par l'utilisateur (porte de sortie exceptionnelle : ticket perdu, paiement N-1…) :
    // retirés des anomalies et des compteurs, gardés dans « classes_sans_suite » pour pouvoir les rétablir
    if ($classes) {
        foreach (['partielles', 'payees_sans_debit', 'echues_non_reglees', 'ndf_sans_debit', 'ik_non_remboursees'] as $k) {
            $garde = [];
            foreach ($res[$k] as $l) {
                if (isset($classes['doc|' . $l['resId']])) $res['classes_sans_suite'][] = $l + ['type' => 'document', 'cle' => 'doc|' . $l['resId']] + $classes['doc|' . $l['resId']];
                else $garde[] = $l;
            }
            $res[$k] = $garde;
        }
        $res['statuts_a_corriger'] = array_values(array_filter($res['statuts_a_corriger'], fn($x) => $x['propose'] === 'Payé' || !isset($classes['doc|' . $x['resId']])));
    }

    $somme = fn(array $l, string $k) => rb_eur(array_sum(array_map(fn($x) => rb_cents($x[$k]), $l)));
    $res['synthese'] = [
        'periode'               => "$du → $au",
        'factures_analysees'    => count(array_filter($F, fn($f) => $f['date'] >= $du && $f['date'] <= $au)),
        'rapprochees'           => count($res['rapprochees']) . ' (' . $somme($res['rapprochees'], 'ttc') . ' €)',
        'partielles'            => count($res['partielles']),
        'payees_sans_debit'     => count($res['payees_sans_debit']) . ' (' . $somme($res['payees_sans_debit'], 'ttc') . ' €)',
        'echues_non_reglees'    => count($res['echues_non_reglees']) . ' (' . $somme($res['echues_non_reglees'], 'ttc') . ' €)',
        'non_echues'            => count($res['non_echues']),
        'debits_sans_facture'   => count($res['debits_sans_facture']) . ' (' . $somme($res['debits_sans_facture'], 'montant') . ' €)',
        'debits_exclus'         => "$exclus (paie, impôts, cotisations sociales… selon exclusions)",
        'statuts_a_corriger'    => count($res['statuts_a_corriger']),
        'ndf_rapprochees'       => count(array_filter($res['rapprochees'], fn($x) => $x['source'] === 'Note de frais')),
        'ndf_sans_debit'        => count($res['ndf_sans_debit']) . ' (' . $somme($res['ndf_sans_debit'], 'ttc') . ' €)',
        'ik_non_remboursees'    => count($res['ik_non_remboursees']) . ' (' . $somme($res['ik_non_remboursees'], 'ttc') . ' €)',
    ];
    return $res;
}

/** Ville du commerçant d'un paiement carte (fin du libellé après « FR »), en mots de 4 lettres et plus */
function rb_lieux(array $op): array
{
    if (empty($op['carte'])) return [];
    $l = rb_norm($op['libelle']);
    if (!preg_match('/FR ([A-Z ]+)$/', $l, $m)) return [];
    return array_values(array_filter(explode(' ', $m[1]), fn($w) => strlen($w) >= 4));
}

/** Identifiant stable d'une paire document / opération bancaire (pour mémoriser les validations) */
function rb_cle(string $docId, array $op): string
{
    return $docId . '|' . $op['date'] . '|' . $op['cents'] . '|' . rb_norm($op['libelle']);
}

/** Sous-ensemble de factures dont la somme vaut exactement $cible (taille 2..$max), ou null */
function rb_somme(array $cands, int $cible, int $max, int $min = 2): ?array
{
    $n = count($cands);
    $best = null;
    $pile = [];
    $explore = function (int $i, int $reste) use (&$explore, &$best, &$pile, $cands, $n, $max, $min) {
        if ($best !== null) return;
        if ($reste === 0 && count($pile) >= $min) { $best = $pile; return; }
        if ($reste <= 0 || $i >= $n || count($pile) >= $max) return;
        for ($j = $i; $j < $n; $j++) {
            if ($cands[$j]['cents'] > $reste) continue;
            $pile[] = $cands[$j];
            $explore($j + 1, $reste - $cands[$j]['cents']);
            array_pop($pile);
            if ($best !== null) return;
        }
    };
    $explore(0, $cible);
    return $best;
}

/**
 * Imputation des avoirs sur les factures (même tiers) AVANT le rapprochement bancaire.
 * Une facture compensée par un avoir n'attend plus que son « net » (TTC − avoir) ; à 0 elle est soldée sans paiement.
 * Affectation : 1) n° de facture cité dans l'avoir (texte OCR, via $citeDans) ; 2) à défaut, facture ouverte unique du même tiers
 * antérieure à l'avoir et d'un montant ≥ avoir (marquée « à vérifier »).
 * @param callable|null $citeDans fn(string $avoirId, string $refFacture): bool
 * @return array [factures ajustées, factures soldées par avoir, avoirs non affectés]
 */
function rb_imputer_avoirs(array $factures, array $avoirs, ?callable $citeDans = null): array
{
    $memeTiers = fn(string $a, string $b) => (bool) array_intersect(rb_mots($a), rb_mots($b));
    $nonAffectes = [];
    // Doublons : même tiers + même n° d'avoir déposé plusieurs fois → on ne garde que le plus récent (resId le plus haut)
    $doublons = [];
    $parCle = [];
    foreach ($avoirs as $av) {
        $k = rb_norm($av['fournisseur']) . '|' . rb_compact($av['ref']);
        if ($av['ref'] === '' || !isset($parCle[$k])) { $parCle[$k . ($av['ref'] === '' ? '#' . $av['id'] : '')] = $av; continue; }
        $garde = (int) $av['id'] > (int) $parCle[$k]['id'] ? $av : $parCle[$k];
        $ecarte = $garde === $av ? $parCle[$k] : $av;
        $parCle[$k] = $garde;
        $doublons[] = ['resId' => $ecarte['id'], 'fournisseur' => $ecarte['fournisseur'], 'ref' => $ecarte['ref'], 'date' => $ecarte['date'],
            'montant' => rb_eur($ecarte['cents']), 'raison' => 'avoir en double dans Zeendoc (doc ' . $garde['id'] . ' à ' . rb_eur($garde['cents']) . ' € retenu) : supprimer ce doublon'];
    }
    $avoirs = array_values($parCle);
    usort($avoirs, fn($a, $b) => strcmp($a['date'], $b['date']));
    foreach ($avoirs as $av) {
        $cands = [];
        foreach ($factures as $k => $f) {
            if (($f['source'] ?? 'facture') !== 'facture' || !empty($f['paye'])) continue;
            $net = $f['cents'];
            if (!$memeTiers($f['fournisseur'], $av['fournisseur']) || $f['date'] > $av['date'] || $net < $av['cents']) continue;
            $cands[$k] = $f;
        }
        $choix = null; $methode = '';
        if ($citeDans) {
            foreach ($cands as $k => $f) {
                if ($f['ref'] !== '' && $citeDans($av['id'], $f['ref'])) { $choix = $k; $methode = 'n° de facture cité dans l\'avoir'; break; }
            }
        }
        if ($choix === null && count($cands) === 1) { $choix = array_key_first($cands); $methode = 'seule facture ouverte du client (à vérifier)'; }
        if ($choix === null) { $nonAffectes[] = $av + ['candidates' => count($cands)]; continue; }
        $f = &$factures[$choix];
        $f['centsBrut'] = $f['centsBrut'] ?? $f['cents'];
        $f['cents'] -= $av['cents'];
        $f['avoirs'][] = ['ref' => $av['ref'], 'date' => $av['date'], 'montant' => rb_eur($av['cents']), 'methode' => $methode, 'resId' => $av['id']];
        unset($f);
    }
    $soldees = [];
    foreach ($factures as $k => $f) {
        if (!empty($f['avoirs']) && $f['cents'] <= 0) {
            $soldees[] = ['resId' => $f['id'], 'fournisseur' => $f['fournisseur'], 'ref' => $f['ref'], 'date' => $f['date'],
                'ttc' => rb_eur($f['centsBrut']), 'avoirs' => $f['avoirs'], 'statut' => $f['statut'] ?: '(vide)'];
            unset($factures[$k]);
        }
    }
    $nonAffectes = array_map(fn($a) => ['resId' => $a['id'], 'fournisseur' => $a['fournisseur'], 'ref' => $a['ref'], 'date' => $a['date'],
        'montant' => rb_eur($a['cents']), 'raison' => $a['candidates'] > 1 ? $a['candidates'] . ' factures possibles, aucune citée dans l\'avoir' : 'aucune facture ouverte de ce client'], $nonAffectes);
    $nonAffectes = array_merge($doublons, $nonAffectes);
    return [array_values($factures), $soldees, $nonAffectes];
}

/** Exclusions côté clients : crédits qui ne sont pas des règlements de factures de vente */
function rb_exclusions_clients(): array
{
    return ['/AGIPI|PREVOYANCE|MUTUELLE/i', '/REMBOURSEMENT|REMB\b/i', '/\bDGFIP\b|CREDIT DE TVA|URSSAF/i', '/EXTOURNE|ANNULATION/i'];
    // Les exclusions propres au client (virements du dirigeant, etc.) sont dans module-config.json
}

/** Exclusions par défaut : flux qui ne sont pas des factures fournisseurs */
function rb_exclusions_defaut(): array
{
    return [
        '/URSSAF/i', '/DGFIP|DIRECTION GENERALE|SIE /i', '/APICIL|RETRAITE/i',
        '/\bpaie\b|\bpaye\b|salaire|avance sur sal/i', '/\bFP \d{4}\b/i',
    ];
}

/**
 * Contrôle inverse : factures déclarées payées (statut « Payé » ou date de paiement) SANS référence bancaire.
 * Ce sont des paiements déclarés, pas prouvés (ex. statuts importés en masse par CSV).
 */
/** Clé d'un débit classé sans suite (stable d'un relevé à l'autre) */
function rb_cle_classe_debit(array $op): string
{
    return 'debit|' . $op['date'] . '|' . $op['cents'] . '|' . $op['ref'] . '|' . rb_norm($op['libelle']);
}

function rb_non_prouves(array $factures, string $du, string $au, array $classes = []): array
{
    $out = [];
    foreach ($factures as $f) {
        if (in_array($f['source'] ?? 'facture', ['ndf', 'ik', 'remb'], true) || $f['date'] < $du || $f['date'] > $au) continue;
        if (isset($classes['doc|' . $f['id']])) continue; // classé sans suite par l'utilisateur
        $dp = trim((string) ($f['dpActuelle'] ?? ''));
        if ((!$f['paye'] && $dp === '') || trim((string) ($f['refActuelle'] ?? '')) !== '') continue;
        $out[] = [
            'resId' => $f['id'], 'fournisseur' => $f['fournisseur'], 'ref' => $f['ref'], 'date' => $f['date'], 'ttc' => rb_eur($f['cents']),
            'statut' => $f['statut'] ?: '(vide)', 'datePaiement' => rb_date($dp) ?? '', 'commentaire' => $f['commentaire'] ?? '',
            'raison' => $f['paye'] && $dp !== '' ? 'statut « Payé » et date de paiement, sans référence bancaire'
                : ($f['paye'] ? 'statut « Payé » sans date ni référence bancaire' : 'date de paiement sans référence bancaire'),
        ];
    }
    usort($out, fn($a, $b) => [$a['date'], $a['ref']] <=> [$b['date'], $b['ref']]);
    return $out;
}
