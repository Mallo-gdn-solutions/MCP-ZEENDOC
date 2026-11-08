<?php

final class ConfigArmoire
{
    private array $config;
    private string $chemin;

    private function __construct(array $config, string $chemin)
    {
        $this->config = $config;
        $this->chemin = $chemin;
    }

    public static function charger(string $chemin): self
    {
        if (!is_file($chemin)) {
            throw new RuntimeException("Configuration introuvable : $chemin");
        }
        $config = json_decode((string) file_get_contents($chemin), true);
        if (!is_array($config)) {
            throw new RuntimeException("Configuration illisible (JSON invalide) : $chemin");
        }
        $instance = new self($config, $chemin);
        $instance->valider();
        return $instance;
    }

    private function valider(): void
    {
        if (empty($this->get('zeendoc.urlClient'))) {
            throw new RuntimeException("Config invalide : zeendoc.urlClient requis");
        }
        $host = (string) $this->get('zeendoc.host', '');
        if ($host === '' || !filter_var($host, FILTER_VALIDATE_URL)) {
            throw new RuntimeException("Config invalide : zeendoc.host doit être une URL");
        }
        foreach (['fournisseurs', 'clients'] as $nom) {
            if (empty($this->get("classeurs.$nom.collId"))) {
                throw new RuntimeException("Config invalide : classeurs.$nom.collId requis");
            }
            if (!is_array($this->get("classeurs.$nom.index", []))) {
                throw new RuntimeException("Config invalide : classeurs.$nom.index doit être un objet");
            }
        }
    }

    public function get(string $cle, $defaut = null)
    {
        $valeur = $this->config;
        foreach (explode('.', $cle) as $segment) {
            if (!is_array($valeur) || !array_key_exists($segment, $valeur)) {
                return $defaut;
            }
            $valeur = $valeur[$segment];
        }
        return $valeur;
    }

    public function section(string $nom): array
    {
        $valeur = $this->config[$nom] ?? [];
        return is_array($valeur) ? $valeur : [];
    }

    public function zeendocHost(): string
    {
        return (string) $this->get('zeendoc.host');
    }

    public function urlClient(): string
    {
        return (string) $this->get('zeendoc.urlClient');
    }

    public function lienDocument(): string
    {
        return (string) $this->get('lienDocument', '');
    }

    public function classeurs(): array
    {
        return $this->section('classeurs');
    }

    public function classeur(string $nom): array
    {
        $classeur = $this->get("classeurs.$nom", []);
        return is_array($classeur) ? $classeur : [];
    }

    public function banque(): array
    {
        return $this->section('banque');
    }

    public function collaborateurs(): array
    {
        return $this->section('collaborateurs');
    }

    public function exclureEcriture(): array
    {
        $liste = $this->get('exclureEcriture', []);
        return is_array($liste) ? $liste : [];
    }

    public function exclusionsSupplementaires(): array
    {
        $exclusions = $this->get('exclusionsSupplementaires', []);
        return is_array($exclusions) ? $exclusions : [];
    }

    public function periodeDebutParDefaut(): string
    {
        return (string) $this->get('periodeDebutParDefaut', '');
    }

    public function paiementsGroupes(): array
    {
        return $this->section('paiementsGroupes');
    }

    public function ecritureActive(): bool
    {
        return (bool) $this->get('ecriture.active', false);
    }

    public function chemin(): string
    {
        return $this->chemin;
    }
}
