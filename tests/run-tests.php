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
$identifiantsCookie = [
    'host' => 'https://zeendoc.test',
    'url_client' => 'gdn_solutions',
    'login' => 'compte-test',
    'password' => 'motdepasse-test',
    'client_id' => '',
    'client_secret' => '',
    'n_client' => '',
];

$config = ConfigArmoire::charger($fixture);
ok($config->urlClient() === 'gdn_solutions', 'config : urlClient lu');
ok($config->classeur('fournisseurs')['collId'] === 'coll_13', 'config : classeur fournisseurs lu');
ok($config->classeur('fournisseurs')['index']['ttc'] === 'custom_f2', 'config : mapping des index lu');
ok($config->ecritureActive() === true, 'config : ecriture.active lu');
ok(in_array('Banque Populaire', $config->exclureEcriture(), true), 'config : exclureEcriture lu');

$mauvais = dossierTemporaire();
file_put_contents("$mauvais/bad.json", json_encode(['zeendoc' => ['urlClient' => 'x', 'host' => 'pas-une-url']]));
try {
    ConfigArmoire::charger("$mauvais/bad.json");
    ok(false, 'config : host invalide rejeté');
} catch (RuntimeException $e) {
    ok(true, 'config : host invalide rejeté');
}

$donnees = dossierTemporaire();
$appels = [];
$transport = function (string $methode, string $url, array $entetes, ?string $corps) use (&$appels) {
    $appels[] = [$methode, $url, $entetes, $corps];
    if (str_contains($url, '/login')) {
        return [200, "Set-Cookie: ZDSID=cookie-test; path=/\r\n", json_encode(['cookieDuration' => '3600'])];
    }
    if (str_contains($url, '/api/v4/context')) {
        return [200, '', json_encode(['binders' => []])];
    }
    if (str_contains($url, '/documents/search')) {
        return [200, '', json_encode(['total' => 0, 'documents' => []])];
    }
    return [200, '', json_encode(['ok' => true])];
};

$noyau = Noyau::depuisConfig($fixture, $donnees, $transport, $identifiantsCookie);
$client = $noyau->zeendoc();

$resultat = $client->contexte();
ok($resultat === ['binders' => []], 'client : GET /context autorisé en lecture');
ok($appels[0][1] === 'https://zeendoc.test/gdn_solutions/api/v4/login', 'client : login appelé sur la bonne base api/v4');
ok(in_array('Cookie: ZDSID=cookie-test; path=/', $appels[1][2], true), 'client : cookie de session transmis');

$client->contexte();
ok(count(array_filter($appels, fn ($a) => str_contains($a[1], '/login'))) === 1, 'client : session cookie réutilisée sans re-login');

$client->recherche('coll_13', ['criteres' => []]);
ok(str_contains($appels[2][1], '/binders/coll_13/documents/search'), 'client : recherche routée');

$client->document('coll_13', '123');
ok(str_contains($appels[3][1], '/binders/coll_13/documents?resId=123'), 'client : fiche document avec resId en query');

try {
    $client->requete('DELETE', '/binders/coll_13/documents/123');
    ok(false, 'client : route hors liste blanche bloquée');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'non autorisée'), 'client : route hors liste blanche bloquée');
}

try {
    $client->requete('PATCH', '/binders/coll_13/documents/123/indexes', null, ['x' => 1], 'usage-inconnu');
    ok(false, 'client : écriture sans autorisation bloquée');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'Écriture refusée'), 'client : écriture sans autorisation bloquée');
}

$noyau->registre()->accorderEcriture('usage-inconnu', 'PATCH /binders/{collId}/documents/{resId}/indexes');
$avant = $client->patchIndexes('coll_13', '123', ['custom_n7' => 1], 'usage-inconnu', ['custom_n7' => null]);
ok(($avant['ok'] ?? false) === true, 'client : écriture autorisée pour l\'usage déclaré');

$journal = array_map('json_decode', file("$donnees/ecritures-journal.jsonl", FILE_IGNORE_NEW_LINES));
ok(count($journal) === 1, 'journal : une écriture journalisée');
ok($journal[0]->usage === 'usage-inconnu' && $journal[0]->resId === '123', 'journal : usage et document enregistrés');
ok($journal[0]->route === 'PATCH /binders/{collId}/documents/{resId}/indexes', 'journal : route canonique enregistrée');

$noyau2 = Noyau::depuisConfig($fixture, dossierTemporaire(), $transport, $identifiantsCookie);
ok($noyau2->registre()->autoriseEcriture('rapprochement', 'PATCH /binders/{collId}/documents/{resId}/indexes'), 'registre : ecriture.active ouvre la route règlement au rapprochement');
ok(!$noyau2->registre()->autoriseEcriture('rapprochement', 'POST /binders/{collId}/fields/{champ}/item-list'), 'registre : les autres écritures restent fermées par défaut');

$donneesOauth = dossierTemporaire();
$identifiantsOauth = $identifiantsCookie + [
    'client_id' => 'id-test',
    'client_secret' => 'secret-test',
    'n_client' => 'N_TEST',
];
$appelsOauth = [];
$transportOauth = function (string $methode, string $url, array $entetes, ?string $corps) use (&$appelsOauth) {
    $appelsOauth[] = [$methode, $url, $entetes, $corps];
    if (str_contains($url, '/_Login_OAuth/authorize.php')) {
        return [200, "Location: https://zeendoc.test/_Login_OAuth/get_code.php?code=code-abc123\r\n", ''];
    }
    if (str_contains($url, '/_Login_OAuth/token.php')) {
        return [200, '', json_encode(['access_token' => 'jeton-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600])];
    }
    return [200, '', json_encode(['binders' => []])];
};
$noyauOauth = Noyau::depuisConfig($fixture, $donneesOauth, $transportOauth, $identifiantsOauth);
$noyauOauth->zeendoc()->contexte();
$jetons = json_decode((string) file_get_contents("$donneesOauth/oauth-jetons.json"), true);
ok($jetons['access_token'] === 'jeton-1' && $jetons['refresh_token'] === 'refresh-1', 'oauth : jetons stockés dans oauth-jetons.json');
ok(in_array('Authorization: Bearer jeton-1', $appelsOauth[2][2], true), 'oauth : bearer transmis après flux complet PKCE');

echo "\n$tests tests, $echoues échec(s)\n";
exit($echoues > 0 ? 1 : 0);
