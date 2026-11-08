<?php
/**
 * Noyau générique — point d'entrée partagé par tous les modules.
 * Fournit : lecture/contrôle de l'armoire, journal des écritures, HTTP, liste blanche des routes.
 */
declare(strict_types=1);

require_once __DIR__ . '/armoire.php';
require_once __DIR__ . '/journal.php';
require_once __DIR__ . '/http.php';

/**
 * Liste blanche des routes Zeendoc, par profil.
 * $classeurs = ['ecriture' => [collId...], 'depot' => [collId...]] : classeurs autorisés selon le profil.
 * Toute route hors liste est refusée.
 */
function noyau_route_autorisee(string $profil, string $methode, string $chemin, array $classeurs = []): bool
{
    $lecture = [
        ['GET', '#^/context$#'],
        ['GET', '#^/binders/[\w-]+/documents$#'],
        ['POST', '#^/binders/[\w-]+/documents/search$#'],
        ['POST', '#^/binders/[\w-]+/documents/count$#'],
        ['GET', '#^/binders/[\w-]+/saved-queries$#'],
        ['GET', '#^/binders/[\w-]+/fields/[\w-]+/autocomplete-search$#'],
        ['GET', '#^/binders/[\w-]+/documents/\d+/linked-docs$#'],
        ['GET', '#^/binders/[\w-]+/documents/\d+/folders$#'],
        ['POST', '#^/history-document/search$#'],
        ['POST', '#^/history-system/search$#'],
    ];
    foreach ($lecture as [$m, $re]) {
        if ($m === $methode && preg_match($re, $chemin)) {
            return true;
        }
    }
    if ($profil === 'ecriture') {
        if ($methode === 'POST' && preg_match('#^/login$#', $chemin)) {
            return true;
        }
        foreach ((array) ($classeurs['ecriture'] ?? []) as $coll) {
            $coll = preg_quote((string) $coll, '#');
            $routes = [
                ['POST', "#^/binders/$coll/documents/search$#"],
                ['GET', "#^/binders/$coll/documents$#"],
                ['PATCH', "#^/binders/$coll/documents/\d+/indexes$#"],
            ];
            foreach ($routes as [$m, $re]) {
                if ($m === $methode && preg_match($re, $chemin)) {
                    return true;
                }
            }
        }
        foreach ((array) ($classeurs['depot'] ?? []) as $coll) {
            $coll = preg_quote((string) $coll, '#');
            if ($methode === 'POST' && preg_match("#^/binders/$coll/documents$#", $chemin)) {
                return true;
            }
        }
    }
    return false;
}
