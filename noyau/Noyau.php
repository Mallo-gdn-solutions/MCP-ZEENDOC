<?php

spl_autoload_register(static function (string $classe): void {
    $fichier = __DIR__ . '/' . $classe . '.php';
    if (is_file($fichier)) {
        require $fichier;
    }
});

final class Noyau
{
    public const USAGE_RAPPROCHEMENT = 'rapprochement';
    public const ROUTE_REGLEMENT = 'PATCH /binders/{collId}/documents/{resId}/indexes';

    private ConfigArmoire $config;
    private StockageLocal $stockage;
    private JournalEcritures $journal;
    private RegistreUsages $registre;
    private ZeendocClient $client;

    private function __construct(
        ConfigArmoire $config,
        StockageLocal $stockage,
        JournalEcritures $journal,
        RegistreUsages $registre,
        ZeendocClient $client
    ) {
        $this->config = $config;
        $this->stockage = $stockage;
        $this->journal = $journal;
        $this->registre = $registre;
        $this->client = $client;
    }

    public static function depuisConfig(
        string $cheminConfig,
        ?string $dossierDonnees = null,
        ?callable $transport = null,
        ?array $identifiants = null
    ): self {
        $config = ConfigArmoire::charger($cheminConfig);
        $dossierDonnees = $dossierDonnees ?? dirname($cheminConfig);
        $stockage = new StockageLocal($dossierDonnees);
        $journal = new JournalEcritures($stockage);
        $registre = new RegistreUsages();
        if ($config->ecritureActive()) {
            $registre->accorderEcriture(self::USAGE_RAPPROCHEMENT, self::ROUTE_REGLEMENT);
        }
        $client = new ZeendocClient($config, $stockage, $journal, $registre, $transport, $identifiants);
        return new self($config, $stockage, $journal, $registre, $client);
    }

    public function config(): ConfigArmoire
    {
        return $this->config;
    }

    public function stockage(): StockageLocal
    {
        return $this->stockage;
    }

    public function journal(): JournalEcritures
    {
        return $this->journal;
    }

    public function registre(): RegistreUsages
    {
        return $this->registre;
    }

    public function zeendoc(): ZeendocClient
    {
        return $this->client;
    }
}
