<?php

require __DIR__ . '/../noyau/Noyau.php';

$tests = 0;
$echoues = 0;

function ok(bool $condition, string $message): void
{
    global $tests, $echoues;
    $tests++;
    if (!$condition) {
        $echoues++;
        fwrite(STDERR, "ÉCHEC : $message\n");
    } else {
        echo "ok : $message\n";
    }
}

function dossierTemporaire(): string
{
    $dossier = sys_get_temp_dir() . '/noyau-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($dossier, 0700, true);
    return $dossier;
}

$fixture = __DIR__ . '/fixtures/module-config.test.json';

$config = ConfigArmoire::charger($fixture);
ok($config->armoire()['nom'] === 'gdn_solutions', 'config : nom de l\'armoire lu');
ok($config->get('classeurs.27.index.tiers') === 'Tiers', 'config : accès par clé pointée');
ok(in_array('PRELEVEMENT SEPA INTERNE', $config->exclusions(), true), 'config : exclusions bancaires lues');

$mauvais = dossierTemporaire();
file_put_contents("$mauvais/bad.json", json_encode([
    'armoire' => ['nom' => 'x', 'url_client' => 'https://zeendoc.test', 'n_client' => 'N', 'plateforme' => 'Mars'],
    'connexion' => ['mode' => 'cookie', 'cookie' => 'sid=1'],
]));
try {
    ConfigArmoire::charger("$mauvais/bad.json");
    ok(false, 'config : plateforme inconnue rejetée');
} catch (RuntimeException $e) {
    ok(true, 'config : plateforme inconnue rejetée');
}

$donnees = dossierTemporaire();
$appelLog = [];
$transport = function (string $methode, string $url, ?string $corps, array $entetes) use (&$appelLog) {
    $appelLog[] = [$methode, $url, $corps, $entetes];
    if (str_contains($url, '/oauth/token')) {
        return [200, json_encode(['access_token' => 'jeton-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600])];
    }
    if (str_contains($url, '/context')) {
        return [200, json_encode(['binders' => []])];
    }
    if (str_contains($url, '/documents/search')) {
        return [200, json_encode(['total' => 0, 'documents' => []])];
    }
    return [200, json_encode(['ok' => true])];
};

$noyau = Noyau::depuisConfig($fixture, $donnees, $transport);
$client = $noyau->zeendoc();

$resultat = $client->contexte();
ok($resultat === ['binders' => []], 'client : GET /context autorisé en lecture');
ok(str_contains($appelLog[1][3][1] ?? '', 'Bearer jeton-1'), 'client : jeton OAuth obtenu puis transmis');

$jetons = json_decode((string) file_get_contents("$donnees/oauth-jetons.json"), true);
ok($jetons['access_token'] === 'jeton-1' && $jetons['refresh_token'] === 'refresh-1', 'client : jetons stockés dans oauth-jetons.json');

$client->rechercheDocuments('27', ['filtres' => []]);
ok(str_contains($appelLog[2][1], '/binders/27/documents/search'), 'client : recherche de documents routée');

try {
    $client->requete('DELETE', '/binders/27/documents/1');
    ok(false, 'client : route hors liste blanche bloquée');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'liste blanche'), 'client : route hors liste blanche bloquée');
}

try {
    $client->patchIndexes('27', '123', ['Reglement' => 'Payé'], 'usage-inconnu');
    ok(false, 'client : écriture sans autorisation bloquée');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'Écriture refusée'), 'client : écriture sans autorisation bloquée');
}

$avant = $client->patchIndexes('27', '123', ['Reglement' => 'Payé'], 'rapprochement', ['Reglement' => 'En attente']);
ok(($avant['ok'] ?? false) === true, 'client : écriture autorisée pour l\'usage déclaré');

$journal = array_map('json_decode', file("$donnees/ecritures-journal.jsonl", FILE_IGNORE_NEW_LINES));
ok(count($journal) === 1, 'journal : une écriture journalisée');
ok($journal[0]->usage === 'rapprochement' && $journal[0]->resId === '123', 'journal : usage et document enregistrés');
ok($journal[0]->avant->Reglement === 'En attente' && $journal[0]->indexes->Reglement === 'Payé', 'journal : valeurs avant/après enregistrées');

$noyau2 = Noyau::depuisConfig($fixture, $donnees, $transport);
ok($noyau2->registre()->autoriseEcriture('rapprochement', 'PATCH /binders/{collId}/documents/{resId}/indexes'), 'registre : écriture ouverte usage par usage depuis la config');
ok(!$noyau2->registre()->autoriseEcriture('rapprochement', 'POST /binders/{collId}/fields/{champ}/item-list'), 'registre : écriture liste non ouverte par défaut');

echo "\n$tests tests, $echoues échec(s)\n";
exit($echoues > 0 ? 1 : 0);
