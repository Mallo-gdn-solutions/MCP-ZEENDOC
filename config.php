<?php
/**
 * Configuration du module de rapprochement — lecture et contrôle de module-config.json
 * ------------------------------------------------------------------------------------
 * TOUT ce qui est propre à une armoire Zeendoc (classeurs, index, banque, exclusions…)
 * vit dans module-config.json, à côté de ce fichier. Le code n'en contient plus aucun.
 * Modèle commenté : module-config.exemple.json.
 */
declare(strict_types=1);

require_once __DIR__ . '/noyau/noyau.php'; // lecture et contrôle génériques d'une armoire : noyau/armoire.php

const MC_FICHIER = __DIR__ . '/module-config.json';
const MC_INDEX_FACTURES = ['tiers', 'date', 'ref', 'ttc', 'echeance', 'mode', 'statut', 'type', 'datePaiement', 'refBancaire'];
const MC_INDEX_NDF = ['tiers', 'date', 'ref', 'ttc', 'statut', 'typeCB', 'typeFrais', 'collaborateur', 'datePaiement', 'refBancaire'];

/** Configuration complète, contrôlée une fois (exception claire si un réglage indispensable manque) */
function module_cfg(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $c = armoire_lire(MC_FICHIER);

    $err = armoire_a_remplir($c);
    if (empty($c['zeendoc']['urlClient']) && !getenv('ZEENDOC_URL_CLIENT')) $err[] = 'zeendoc.urlClient';
    // Classeurs du module de rapprochement et rôles d'index attendus (classeur absent = non utilisé chez ce client)
    if (empty($c['classeurs']['fournisseurs']) && empty($c['classeurs']['clients'])) $err[] = 'classeurs.fournisseurs ou classeurs.clients';
    $err = array_merge($err,
        armoire_verifier_classeur($c, 'fournisseurs', MC_INDEX_FACTURES),
        armoire_verifier_classeur($c, 'clients', MC_INDEX_FACTURES),
        armoire_verifier_classeur($c, 'notesDeFrais', MC_INDEX_NDF));
    if (isset($c['releveBancaire']['collId']) && !preg_match('/^coll_\d+$/', (string) $c['releveBancaire']['collId'])) $err[] = 'releveBancaire.collId';
    if ($err) {
        throw new RuntimeException('module-config.json incomplet ou invalide : ' . implode(', ', $err) . '. Voir module-config.exemple.json.');
    }
    return $cfg = $c;
}

function mc_url_client(): string { return armoire_url_client(module_cfg()); }
function mc_host(): string { return armoire_host(module_cfg()); }

/** Réglages d'un classeur : 'fournisseurs', 'clients' ou 'notesDeFrais' ; null si non utilisé chez ce client */
function mc_classeur(string $nom): ?array { return armoire_classeur(module_cfg(), $nom); }
function mc_coll(string $nom): ?string { return mc_classeur($nom)['collId'] ?? null; }

/** Classeur où sont déposés les relevés bancaires CSV (module-config.json : releveBancaire.collId) */
function mc_coll_releve(): string
{
    $coll = (string) (module_cfg()['releveBancaire']['collId'] ?? '');
    if ($coll === '') throw new InvalidArgumentException('Classeur du relevé inconnu : fournir csvCollId, ou renseigner releveBancaire.collId dans module-config.json');
    return $coll;
}

/** Index écrits par le module, par classeur : collId => [statut, datePaiement, refBancaire] */
function mc_champs_ecriture(): array
{
    $out = [];
    foreach (['fournisseurs', 'notesDeFrais', 'clients'] as $nom) {
        $k = mc_classeur($nom);
        if (!$k) continue;
        $out[$k['collId']] = ['statut' => $k['index']['statut'], 'datePaiement' => $k['index']['datePaiement'], 'refBancaire' => $k['index']['refBancaire']];
    }
    return $out;
}

/** Libellé lisible d'un classeur à partir de son collId */
function mc_nom_classeur(string $collId): string
{
    foreach (['fournisseurs' => 'Fournisseur', 'clients' => 'Client', 'notesDeFrais' => 'Note de frais'] as $nom => $lib) {
        if (mc_coll($nom) === $collId) return $lib;
    }
    return $collId;
}

/** Tiers dont les factures ne sont jamais écrites automatiquement (ex. la banque, sauf référence retrouvée dans la facture) */
function mc_exclure_ecriture(): array { return (array) (module_cfg()['exclureEcriture'] ?? []); }
function mc_banques(): array { return (array) (module_cfg()['banque']['fournisseursFraisBancaires'] ?? []); }

/** Collaborateurs remboursés par virement : nom tel qu'indexé dans Zeendoc => mots du libellé bancaire */
function mc_collaborateurs(): array
{
    $out = [];
    foreach ((array) (module_cfg()['collaborateurs'] ?? []) as $nom => $alias) {
        if (str_starts_with((string) $nom, '_aide')) continue;
        $out[(string) $nom] = array_values(array_filter(array_map('strval', (array) $alias), 'strlen'));
    }
    return $out;
}

/** Exclusions supplémentaires propres au client (expressions régulières), en plus des exclusions génériques */
function mc_exclusions(string $sens): array
{
    $l = (array) (module_cfg()['exclusionsSupplementaires'][$sens] ?? []);
    return array_values(array_filter($l, fn($re) => is_string($re) && @preg_match($re, '') !== false));
}
