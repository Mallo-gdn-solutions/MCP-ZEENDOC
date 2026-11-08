<?php

final class ZeendocClient
{
    private const ROUTES_LECTURE = [
        ['GET', '#^/context$#', 'GET /context'],
        ['GET', '#^/binders/[\w-]+/documents$#', 'GET /binders/{collId}/documents'],
        ['POST', '#^/binders/[\w-]+/documents/search$#', 'POST /binders/{collId}/documents/search'],
        ['POST', '#^/binders/[\w-]+/documents/count$#', 'POST /binders/{collId}/documents/count'],
        ['GET', '#^/binders/[\w-]+/saved-queries$#', 'GET /binders/{collId}/saved-queries'],
        ['GET', '#^/binders/[\w-]+/fields/[\w-]+/autocomplete-search$#', 'GET /binders/{collId}/fields/{champ}/autocomplete-search'],
        ['GET', '#^/binders/[\w-]+/documents/\d+/linked-docs$#', 'GET /binders/{collId}/documents/{resId}/linked-docs'],
        ['GET', '#^/binders/[\w-]+/documents/\d+/folders$#', 'GET /binders/{collId}/documents/{resId}/folders'],
        ['POST', '#^/history-document/search$#', 'POST /history-document/search'],
        ['POST', '#^/history-system/search$#', 'POST /history-system/search'],
    ];

    private const ROUTES_ECRITURE = [
        ['PATCH', '#^/binders/[\w-]+/documents/\d+/indexes$#', 'PATCH /binders/{collId}/documents/{resId}/indexes'],
        ['POST', '#^/binders/[\w-]+/fields/[\w-]+/item-list$#', 'POST /binders/{collId}/fields/{champ}/item-list'],
        ['PATCH', '#^/binders/[\w-]+/fields/[\w-]+/item-list$#', 'PATCH /binders/{collId}/fields/{champ}/item-list'],
    ];

    private ConfigArmoire $config;
    private StockageLocal $stockage;
    private JournalEcritures $journal;
    private RegistreUsages $registre;
    private $transport;
    private array $identifiants;
    private ?string $cookieSession = null;
    private int $cookieExpire = 0;
    private ?string $jetonAcces = null;

    public function __construct(
        ConfigArmoire $config,
        StockageLocal $stockage,
        JournalEcritures $journal,
        RegistreUsages $registre,
        ?callable $transport = null,
        ?array $identifiants = null
    ) {
        $this->config = $config;
        $this->stockage = $stockage;
        $this->journal = $journal;
        $this->registre = $registre;
        $this->transport = $transport ?? [self::class, 'transportCurl'];
        $env = static function (string $nom): string {
            $v = getenv($nom);
            return $v === false ? '' : (string) $v;
        };
        $this->identifiants = $identifiants ?? [
            'host' => $env('ZEENDOC_HOST') ?: $config->zeendocHost(),
            'url_client' => $env('ZEENDOC_URL_CLIENT') ?: $config->urlClient(),
            'login' => $env('ZEENDOC_LOGIN'),
            'password' => $env('ZEENDOC_PASSWORD'),
            'client_id' => $env('ZEENDOC_CLIENT_ID'),
            'client_secret' => $env('ZEENDOC_CLIENT_SECRET'),
            'n_client' => $env('ZEENDOC_N_CLIENT'),
        ];
    }

    public static function transportCurl(string $methode, string $url, array $entetes, ?string $corps): array
    {
        $options = [
            CURLOPT_CUSTOMREQUEST => $methode,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $entetes,
            CURLOPT_TIMEOUT => 60,
        ];
        if (defined('CURLSSLOPT_NATIVE_CA')) {
            $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }
        if ($corps !== null) {
            $options[CURLOPT_POSTFIELDS] = $corps;
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, $options);
        $reponse = curl_exec($curl);
        if ($reponse === false) {
            $erreur = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException("Appel Zeendoc impossible ($url) : $erreur");
        }
        $statut = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $tailleEntetes = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        curl_close($curl);
        $brut = (string) $reponse;
        return [$statut, substr($brut, 0, $tailleEntetes), substr($brut, $tailleEntetes)];
    }

    private function appeler(string $methode, string $url, array $entetes, ?string $corps): array
    {
        return call_user_func($this->transport, $methode, $url, $entetes, $corps);
    }

    private function base(): string
    {
        return rtrim($this->identifiants['host'], '/') . '/' . $this->identifiants['url_client'] . '/api/v4';
    }

    private function host(): string
    {
        return rtrim($this->identifiants['host'], '/');
    }

    private function oauthActif(): bool
    {
        return $this->identifiants['client_id'] !== ''
            && $this->identifiants['client_secret'] !== ''
            && $this->identifiants['n_client'] !== '';
    }

    private function cookie(bool $force = false): string
    {
        if (!$force && $this->cookieSession !== null && $this->cookieExpire > time()) {
            return $this->cookieSession;
        }
        if ($this->identifiants['login'] === '' || $this->identifiants['password'] === '') {
            throw new RuntimeException('Identifiants Zeendoc manquants (ZEENDOC_LOGIN / ZEENDOC_PASSWORD)');
        }
        $corps = json_encode([
            'login' => $this->identifiants['login'],
            'password' => $this->identifiants['password'],
        ]);
        [$statut, $entetes, $reponse] = $this->appeler('POST', $this->base() . '/login', [
            'Content-Type: application/json',
            'Accept: application/json',
        ], $corps);
        if ($statut !== 200) {
            throw new RuntimeException("Login Zeendoc refusé (HTTP $statut)");
        }
        $json = json_decode($reponse, true);
        $cookie = is_array($json) ? ($json['cookie'] ?? null) : null;
        if (!$cookie && preg_match('/Set-Cookie:\s*([^;]+)/i', $entetes, $m)) {
            $cookie = trim($m[1]);
        }
        if (!$cookie) {
            throw new RuntimeException('Login Zeendoc : aucun cookie de session reçu');
        }
        $duree = (int) preg_replace('/\D/', '', (string) (is_array($json) ? ($json['cookieDuration'] ?? '3600') : '3600'));
        $this->cookieSession = (string) $cookie;
        $this->cookieExpire = time() + max(300, (int) ($duree * 0.9));
        return $this->cookieSession;
    }

    private function oauthPost(string $page, array $form, array $query = []): array
    {
        $url = $this->host() . '/_Login_OAuth/' . $page . ($query ? '?' . http_build_query($query) : '');
        $corps = http_build_query($form);
        [$statut, $entetes, $reponse] = $this->appeler('POST', $url, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], $corps);
        if ($statut >= 400) {
            throw new RuntimeException("OAuth $page refusée (HTTP $statut)");
        }
        return ['status' => $statut, 'entetes' => $entetes, 'corps' => $reponse];
    }

    private function stockerJetons(array $j, ?string $refreshConserve = null): array
    {
        $ancien = $this->stockage->lireJson('oauth-jetons.json') ?? [];
        $jetons = [
            'access_token' => (string) $j['access_token'],
            'expire' => time() + max(300, (int) (((int) ($j['expires_in'] ?? 3600)) * 0.9)),
            'refresh_token' => (string) ($j['refresh_token'] ?? $refreshConserve ?? ($ancien['refresh_token'] ?? '')),
            'refresh_cree' => !empty($j['refresh_token']) ? time() : (int) ($ancien['refresh_cree'] ?? time()),
            'url_client' => $j['url_client'] ?? ($ancien['url_client'] ?? null),
        ];
        $this->stockage->ecrireJson('oauth-jetons.json', $jetons);
        $this->jetonAcces = $jetons['access_token'];
        return $jetons;
    }

    private function oauthComplet(): array
    {
        if ($this->identifiants['login'] === '' || $this->identifiants['password'] === '') {
            throw new RuntimeException("OAuth : identifiants du compte d'application manquants (ZEENDOC_LOGIN / ZEENDOC_PASSWORD)");
        }
        $redirect = $this->host() . '/_Login_OAuth/get_code.php';
        $challenge = bin2hex(random_bytes(16));
        $r = $this->oauthPost('authorize.php', [
            'client_id' => $this->identifiants['client_id'],
            'response_type' => 'code',
            'redirect_uri' => $redirect,
            'state' => $challenge,
            'username' => $this->identifiants['login'],
            'password' => $this->identifiants['password'],
            'N_Client' => $this->identifiants['n_client'],
        ], [
            'code_challenge_method' => 'sha256',
            'code_challenge' => hash('sha256', $challenge),
        ]);
        $code = null;
        $j = json_decode($r['corps'], true);
        if (is_array($j)) {
            $code = $j['code'] ?? null;
        }
        if (!$code && preg_match('/^Location:.*[?&]code=([\w-]+)/mi', $r['entetes'], $m)) {
            $code = $m[1];
        }
        if (!$code && preg_match('/^[a-f0-9]{20,}$/i', trim($r['corps']))) {
            $code = trim($r['corps']);
        }
        if (!$code) {
            throw new RuntimeException("OAuth étape 1 refusée : aucune code d'autorisation reçu");
        }
        $r = $this->oauthPost('token.php', [
            'client_id' => $this->identifiants['client_id'],
            'client_secret' => $this->identifiants['client_secret'],
            'redirect_uri' => $redirect,
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);
        $j = json_decode($r['corps'], true);
        if (!is_array($j) || empty($j['access_token'])) {
            throw new RuntimeException("OAuth étape 2 refusée : access_token manquant");
        }
        return $this->stockerJetons($j);
    }

    private function jeton(bool $force = false): string
    {
        if (!$force && $this->jetonAcces !== null) {
            return $this->jetonAcces;
        }
        $t = $this->stockage->lireJson('oauth-jetons.json') ?? [];
        if (!$force && !empty($t['access_token']) && (int) ($t['expire'] ?? 0) > time()) {
            $this->jetonAcces = (string) $t['access_token'];
            return $this->jetonAcces;
        }
        $frais = !empty($t['refresh_token']) && (time() - (int) ($t['refresh_cree'] ?? 0)) < 85 * 86400;
        if ($frais) {
            try {
                $r = $this->oauthPost('token.php', [
                    'client_id' => $this->identifiants['client_id'],
                    'client_secret' => $this->identifiants['client_secret'],
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $t['refresh_token'],
                ]);
                $j = json_decode($r['corps'], true);
                if (is_array($j) && !empty($j['access_token'])) {
                    return $this->stockerJetons($j, (string) $t['refresh_token'])['access_token'];
                }
            } catch (Throwable $e) {
            }
        }
        $this->oauthComplet();
        return $this->jetonAcces;
    }

    private function enteteAuth(bool $force = false): string
    {
        return $this->oauthActif()
            ? 'Authorization: Bearer ' . $this->jeton($force)
            : 'Cookie: ' . $this->cookie($force);
    }

    private function trouverRoute(string $methode, string $chemin): ?array
    {
        foreach (self::ROUTES_LECTURE as $route) {
            if ($route[0] === $methode && preg_match($route[1], $chemin)) {
                return ['acces' => 'lecture', 'label' => $route[2]];
            }
        }
        foreach (self::ROUTES_ECRITURE as $route) {
            if ($route[0] === $methode && preg_match($route[1], $chemin)) {
                return ['acces' => 'ecriture', 'label' => $route[2]];
            }
        }
        return null;
    }

    public function requete(string $methode, string $chemin, ?array $query = null, ?array $corps = null, ?string $usage = null, ?array $ecriture = null): array
    {
        $trouvee = $this->trouverRoute($methode, $chemin);
        if ($trouvee === null) {
            throw new RuntimeException("Route non autorisée par le noyau : $methode $chemin");
        }
        if ($trouvee['acces'] === 'ecriture' && ($usage === null || !$this->registre->autoriseEcriture($usage, $trouvee['label']))) {
            throw new RuntimeException("Écriture refusée : {$trouvee['label']} (usage " . ($usage ?? 'aucun') . " non autorisé)");
        }
        $url = $this->base() . $chemin . ($query ? '?' . http_build_query($query) : '');
        $corpsJson = $corps === null ? null : json_encode($corps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $entetes = ['Content-Type: application/json', 'Accept: application/json', $this->enteteAuth()];
        [$statut, $entetesBrutes, $reponse] = $this->appeler($methode, $url, $entetes, $corpsJson);
        if (in_array($statut, [401, 403], true)) {
            $entetes = ['Content-Type: application/json', 'Accept: application/json', $this->enteteAuth(true)];
            [$statut, $entetesBrutes, $reponse] = $this->appeler($methode, $url, $entetes, $corpsJson);
        }
        if ($statut >= 400) {
            throw new RuntimeException("Zeendoc HTTP $statut sur $methode $chemin");
        }
        if ($trouvee['acces'] === 'ecriture' && $ecriture !== null) {
            $this->journal->enregistrer(array_merge($ecriture, [
                'methode' => $methode,
                'route' => $trouvee['label'],
                'usage' => $usage,
                'compte' => $this->identifiants['login'] !== '' ? $this->identifiants['login'] : $this->config->urlClient(),
            ]));
        }
        $donnees = json_decode($reponse, true);
        return is_array($donnees) ? $donnees : ['brut' => $reponse];
    }

    public function contexte(array $query = ['getConfigSets' => 'false']): array
    {
        return $this->requete('GET', '/context', $query);
    }

    public function recherche(string $collId, array $corps): array
    {
        return $this->requete('POST', "/binders/$collId/documents/search", null, $corps);
    }

    public function compte(string $collId, array $corps): array
    {
        return $this->requete('POST', "/binders/$collId/documents/count", null, $corps);
    }

    public function document(string $collId, string $resId): array
    {
        return $this->requete('GET', "/binders/$collId/documents", ['resId' => $resId]);
    }

    public function savedQueries(string $collId, bool $avecNombre = false): array
    {
        return $this->requete('GET', "/binders/$collId/saved-queries", ['nbResult' => $avecNombre ? 'true' : 'false']);
    }

    public function autocomplete(string $collId, string $index, string $terme = ''): array
    {
        return $this->requete('GET', "/binders/$collId/fields/$index/autocomplete-search", ['term' => $terme]);
    }

    public function documentsLies(string $collId, string $resId): array
    {
        return $this->requete('GET', "/binders/$collId/documents/$resId/linked-docs");
    }

    public function dossiers(string $collId, string $resId): array
    {
        return $this->requete('GET', "/binders/$collId/documents/$resId/folders");
    }

    public function historiqueDocuments(array $corps): array
    {
        return $this->requete('POST', '/history-document/search', null, $corps);
    }

    public function historiqueSysteme(array $corps): array
    {
        return $this->requete('POST', '/history-system/search', null, $corps);
    }

    public function patchIndexes(string $collId, string $resId, array $indexes, string $usage, ?array $avant = null): array
    {
        return $this->requete('PATCH', "/binders/$collId/documents/$resId/indexes", null, $indexes, $usage, [
            'collId' => $collId,
            'resId' => $resId,
            'indexes' => $indexes,
            'avant' => $avant,
        ]);
    }

    public function ajouterValeurListe(string $collId, string $champ, array $valeur, string $usage): array
    {
        return $this->requete('POST', "/binders/$collId/fields/$champ/item-list", null, $valeur, $usage, [
            'collId' => $collId,
            'champ' => $champ,
            'valeur' => $valeur,
        ]);
    }

    public function majValeurListe(string $collId, string $champ, array $valeur, string $usage): array
    {
        return $this->requete('PATCH', "/binders/$collId/fields/$champ/item-list", null, $valeur, $usage, [
            'collId' => $collId,
            'champ' => $champ,
            'valeur' => $valeur,
        ]);
    }
}
