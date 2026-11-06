<?php

final class ZeendocClient
{
    private const ROUTES = [
        'GET /context' => 'lecture',
        'POST /binders/{collId}/documents/search' => 'lecture',
        'POST /binders/{collId}/documents/count' => 'lecture',
        'GET /binders/{collId}/documents' => 'lecture',
        'GET /binders/{collId}/documents/{resId}' => 'lecture',
        'GET /binders/{collId}/documents/{resId}/linked-docs' => 'lecture',
        'GET /binders/{collId}/documents/{resId}/folders' => 'lecture',
        'GET /binders/{collId}/saved-queries' => 'lecture',
        'GET /binders/{collId}/fields/{champ}/autocomplete-search' => 'lecture',
        'GET /binders/{collId}/fields/{champ}/item-list' => 'lecture',
        'POST /history-document/search' => 'lecture',
        'POST /history-system/search' => 'lecture',
        'PATCH /binders/{collId}/documents/{resId}/indexes' => 'ecriture',
        'POST /binders/{collId}/fields/{champ}/item-list' => 'ecriture',
        'PATCH /binders/{collId}/fields/{champ}/item-list' => 'ecriture',
    ];

    private const ROUTE_JETON = '/oauth/token';

    private ConfigArmoire $config;
    private StockageLocal $stockage;
    private JournalEcritures $journal;
    private RegistreUsages $registre;
    private $transport;
    private string $mode;
    private ?string $jetonAcces = null;

    public function __construct(
        ConfigArmoire $config,
        StockageLocal $stockage,
        JournalEcritures $journal,
        RegistreUsages $registre,
        ?callable $transport = null
    ) {
        $this->config = $config;
        $this->stockage = $stockage;
        $this->journal = $journal;
        $this->registre = $registre;
        $this->transport = $transport ?? [self::class, 'transportCurl'];
        $this->mode = $config->connexion()['mode'] ?? 'cookie';
    }

    public static function transportCurl(string $methode, string $url, ?string $corps, array $entetes): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $methode,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $entetes,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($corps !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $corps);
        }
        $reponse = curl_exec($curl);
        if ($reponse === false) {
            $erreur = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException("Appel Zeendoc impossible ($url) : $erreur");
        }
        $statut = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return [(int) $statut, (string) $reponse];
    }

    private function urlBase(): string
    {
        return rtrim($this->config->armoire()['url_client'], '/');
    }

    private function apparierRoute(string $methode, string $route): ?array
    {
        foreach (self::ROUTES as $motif => $acces) {
            [$m, $chemin] = explode(' ', $motif, 2);
            if ($m !== $methode) {
                continue;
            }
            $regex = '#^';
            foreach (preg_split('/(\{[^}]+\})/', $chemin, -1, PREG_SPLIT_DELIM_CAPTURE) as $partie) {
                if ($partie === '') {
                    continue;
                }
                $regex .= preg_match('/^\{[^}]+\}$/', $partie) ? '([^/]+)' : preg_quote($partie, '#');
            }
            $regex .= '$#';
            if (preg_match($regex, $route, $correspondances)) {
                array_shift($correspondances);
                return ['acces' => $acces, 'params' => $correspondances];
            }
        }
        return null;
    }

    private function appeler(string $methode, string $url, ?string $corps, array $entetes): array
    {
        return call_user_func($this->transport, $methode, $url, $corps, $entetes);
    }

    private function entetes(string $auth): array
    {
        $entetes = ['Accept: application/json', $auth];
        return $entetes;
    }

    private function enteteAuth(): string
    {
        if ($this->mode === 'cookie') {
            return 'Cookie: ' . ($this->config->connexion()['cookie'] ?? '');
        }
        return 'Authorization: Bearer ' . $this->jeton();
    }

    private function jeton(): string
    {
        if ($this->jetonAcces !== null) {
            return $this->jetonAcces;
        }
        $jetons = $this->stockage->lireJson('oauth-jetons.json') ?? [];
        if (!empty($jetons['access_token']) && (int) ($jetons['expire_le'] ?? 0) > time() + 60) {
            $this->jetonAcces = (string) $jetons['access_token'];
            return $this->jetonAcces;
        }
        if (!empty($jetons['refresh_token'])) {
            $this->renouvelerJeton((string) $jetons['refresh_token']);
            if ($this->jetonAcces !== null) {
                return $this->jetonAcces;
            }
        }
        $this->obtenirJeton();
        return $this->jetonAcces;
    }

    private function stockerJetons(?array $jetons, ?string $refreshConserve = null): void
    {
        if (!is_array($jetons) || empty($jetons['access_token'])) {
            throw new RuntimeException('Réponse OAuth invalide : access_token manquant');
        }
        $this->jetonAcces = (string) $jetons['access_token'];
        $this->stockage->ecrireJson('oauth-jetons.json', [
            'access_token' => $this->jetonAcces,
            'refresh_token' => (string) ($jetons['refresh_token'] ?? $refreshConserve ?? ''),
            'expire_le' => time() + (int) ($jetons['expires_in'] ?? 3600),
        ]);
    }

    private function obtenirJeton(): void
    {
        $connexion = $this->config->connexion();
        $corps = json_encode([
            'grant_type' => 'client_credentials',
            'client_id' => $connexion['client_id'] ?? '',
            'client_secret' => $connexion['client_secret'] ?? '',
            'compte' => $connexion['compte_application'] ?? '',
            'n_client' => $this->config->armoire()['n_client'] ?? '',
        ]);
        [$statut, $reponse] = $this->appeler('POST', $this->urlBase() . self::ROUTE_JETON, $corps, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        if ($statut >= 400) {
            throw new RuntimeException("Échec d'obtention du jeton OAuth (HTTP $statut)");
        }
        $this->stockerJetons(json_decode($reponse, true));
    }

    private function renouvelerJeton(string $refresh): void
    {
        $connexion = $this->config->connexion();
        $corps = json_encode([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
            'client_id' => $connexion['client_id'] ?? '',
        ]);
        [$statut, $reponse] = $this->appeler('POST', $this->urlBase() . self::ROUTE_JETON, $corps, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        if ($statut >= 400) {
            $this->jetonAcces = null;
            $this->obtenirJeton();
            return;
        }
        $this->stockerJetons(json_decode($reponse, true), $refresh);
    }

    public function requete(string $methode, string $route, ?array $corps = null, ?string $usage = null, ?array $ecriture = null): array
    {
        $routePure = explode('?', $route, 2)[0];
        $trouvee = $this->apparierRoute($methode, $routePure);
        if ($trouvee === null) {
            throw new RuntimeException("Route hors liste blanche : $methode $routePure");
        }
        if ($trouvee['acces'] === 'ecriture' && ($usage === null || !$this->registre->autoriseEcriture($usage, "$methode $routePure"))) {
            throw new RuntimeException("Écriture refusée : $methode $routePure (usage " . ($usage ?? 'aucun') . " non autorisé)");
        }
        $url = $this->urlBase() . '/' . ltrim($route, '/');
        $corpsJson = $corps === null ? null : json_encode($corps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $entetes = $this->entetes($this->enteteAuth());
        if ($corpsJson !== null) {
            $entetes[] = 'Content-Type: application/json';
        }
        [$statut, $reponse] = $this->appeler($methode, $url, $corpsJson, $entetes);
        if ($statut === 401 && $this->mode === 'oauth') {
            $this->jetonAcces = null;
            $jetons = $this->stockage->lireJson('oauth-jetons.json');
            if (!empty($jetons['refresh_token'])) {
                $this->renouvelerJeton((string) $jetons['refresh_token']);
            } else {
                $this->obtenirJeton();
            }
            $entetes = $this->entetes($this->enteteAuth());
            if ($corpsJson !== null) {
                $entetes[] = 'Content-Type: application/json';
            }
            [$statut, $reponse] = $this->appeler($methode, $url, $corpsJson, $entetes);
        }
        if ($statut < 200 || $statut >= 300) {
            throw new RuntimeException("Zeendoc a répondu HTTP $statut sur $methode $routePure");
        }
        if ($trouvee['acces'] === 'ecriture' && $ecriture !== null) {
            $this->journal->enregistrer(array_merge($ecriture, [
                'methode' => $methode,
                'route' => $routePure,
                'usage' => $usage,
                'compte' => $this->config->connexion()['compte_application'] ?? $this->config->armoire()['nom'],
            ]));
        }
        $donnees = json_decode($reponse, true);
        return is_array($donnees) ? $donnees : [];
    }

    public function contexte(): array
    {
        return $this->requete('GET', '/context');
    }

    public function rechercheDocuments(string $collId, array $filtres = [], int $debut = 0, int $taille = 100): array
    {
        return $this->requete('POST', "/binders/$collId/documents/search", array_merge($filtres, [
            'offset' => $debut,
            'limit' => $taille,
        ]));
    }

    public function compteDocuments(string $collId, array $filtres = []): array
    {
        return $this->requete('POST', "/binders/$collId/documents/count", $filtres);
    }

    public function ficheDocument(string $collId, string $resId): array
    {
        return $this->requete('GET', "/binders/$collId/documents/$resId");
    }

    public function documentsLies(string $collId, string $resId): array
    {
        return $this->requete('GET', "/binders/$collId/documents/$resId/linked-docs");
    }

    public function dossiersDocument(string $collId, string $resId): array
    {
        return $this->requete('GET', "/binders/$collId/documents/$resId/folders");
    }

    public function recherchesEnregistrees(string $collId): array
    {
        return $this->requete('GET', "/binders/$collId/saved-queries");
    }

    public function autocomplete(string $collId, string $champ, string $texte = ''): array
    {
        $query = $texte === '' ? '' : '?q=' . rawurlencode($texte);
        return $this->requete('GET', "/binders/$collId/fields/$champ/autocomplete-search$query");
    }

    public function listeValeurs(string $collId, string $champ): array
    {
        return $this->requete('GET', "/binders/$collId/fields/$champ/item-list");
    }

    public function historiqueDocuments(array $filtres = []): array
    {
        return $this->requete('POST', '/history-document/search', $filtres);
    }

    public function historiqueSysteme(array $filtres = []): array
    {
        return $this->requete('POST', '/history-system/search', $filtres);
    }

    public function patchIndexes(string $collId, string $resId, array $indexes, string $usage, ?array $avant = null): array
    {
        return $this->requete('PATCH', "/binders/$collId/documents/$resId/indexes", $indexes, $usage, [
            'collId' => $collId,
            'resId' => $resId,
            'indexes' => $indexes,
            'avant' => $avant,
        ]);
    }

    public function ajouterValeurListe(string $collId, string $champ, array $valeur, string $usage): array
    {
        return $this->requete('POST', "/binders/$collId/fields/$champ/item-list", $valeur, $usage, [
            'collId' => $collId,
            'champ' => $champ,
            'valeur' => $valeur,
        ]);
    }

    public function majValeurListe(string $collId, string $champ, string $identifiant, array $valeur, string $usage): array
    {
        return $this->requete('PATCH', "/binders/$collId/fields/$champ/item-list", array_merge($valeur, [
            'id' => $identifiant,
        ]), $usage, [
            'collId' => $collId,
            'champ' => $champ,
            'identifiant' => $identifiant,
            'valeur' => $valeur,
        ]);
    }
}
