<?php
/**
 * Armoire Zeendoc : lecture et contrôle génériques de module-config.json.
 * Aucune donnée propre à un client dans le code : tout vient du fichier de configuration.
 */
declare(strict_types=1);

const ARMOIRE_HOST_DEFAUT = 'https://armoires.zeendoc.com';

function armoire_lire(string $fichier): array
{
    if (!is_file($fichier)) {
        throw new RuntimeException("Configuration introuvable : $fichier");
    }
    $c = json_decode((string) file_get_contents($fichier), true);
    if (!is_array($c)) {
        throw new RuntimeException("Configuration illisible (JSON invalide) : $fichier");
    }
    return $c;
}

/** Clés indispensables manquantes (retourne la liste des erreurs, vide si OK) */
function armoire_a_remplir(array $c): array
{
    $err = [];
    if (!is_array($c['zeendoc'] ?? null)) {
        $err[] = 'zeendoc (urlClient, host)';
    }
    if (!is_array($c['classeurs'] ?? null)) {
        $err[] = 'classeurs';
    }
    return $err;
}

/** Contrôle d'un classeur du module : collId et rôles d'index requis (absent = non utilisé chez ce client) */
function armoire_verifier_classeur(array $c, string $nom, array $indexRequis): array
{
    $err = [];
    $cl = $c['classeurs'][$nom] ?? null;
    if (!is_array($cl)) {
        return $err;
    }
    if (empty($cl['collId']) || !is_string($cl['collId'])) {
        $err[] = "classeurs.$nom.collId";
    }
    foreach ($indexRequis as $role) {
        if (empty($cl['index'][$role])) {
            $err[] = "classeurs.$nom.index.$role";
        }
    }
    return $err;
}

function armoire_url_client(array $c): string
{
    $env = getenv('ZEENDOC_URL_CLIENT');
    if ($env) {
        return (string) $env;
    }
    return (string) ($c['zeendoc']['urlClient'] ?? '');
}

function armoire_host(array $c): string
{
    $env = getenv('ZEENDOC_HOST');
    if ($env) {
        return rtrim((string) $env, '/');
    }
    return rtrim((string) ($c['zeendoc']['host'] ?? ARMOIRE_HOST_DEFAUT), '/');
}

/** Réglages d'un classeur nommé ('fournisseurs', 'clients', 'notesDeFrais') ; null si non utilisé */
function armoire_classeur(array $c, string $nom): ?array
{
    $cl = $c['classeurs'][$nom] ?? null;
    return is_array($cl) ? $cl : null;
}
