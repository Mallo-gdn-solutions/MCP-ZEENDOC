<?php

final class ConfigArmoire
{
    private const PLATEFORMES = ['France', 'Suisse', 'Santé'];
    private const MODES = ['cookie', 'oauth'];

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
        $armoire = $this->section('armoire');
        foreach (['nom', 'url_client', 'n_client', 'plateforme'] as $cle) {
            if (empty($armoire[$cle])) {
                throw new RuntimeException("Config invalide : armoire.$cle requis");
            }
        }
        if (!in_array($armoire['plateforme'], self::PLATEFORMES, true)) {
            throw new RuntimeException("Config invalide : plateforme inconnue ({$armoire['plateforme']})");
        }
        if (!filter_var($armoire['url_client'], FILTER_VALIDATE_URL)) {
            throw new RuntimeException("Config invalide : armoire.url_client n'est pas une URL");
        }
        $connexion = $this->section('connexion');
        $mode = $connexion['mode'] ?? 'cookie';
        if (!in_array($mode, self::MODES, true)) {
            throw new RuntimeException("Config invalide : connexion.mode doit être cookie ou oauth");
        }
        if ($mode === 'oauth') {
            foreach (['client_id', 'client_secret', 'compte_application'] as $cle) {
                if (empty($connexion[$cle])) {
                    throw new RuntimeException("Config invalide : connexion.$cle requis en mode oauth");
                }
            }
        }
        if ($mode === 'cookie' && empty($connexion['cookie'])) {
            throw new RuntimeException("Config invalide : connexion.cookie requis en mode cookie");
        }
        if (!is_array($this->section('classeurs'))) {
            throw new RuntimeException("Config invalide : classeurs doit être un objet");
        }
    }

    public function section(string $nom): array
    {
        $valeur = $this->config[$nom] ?? [];
        return is_array($valeur) ? $valeur : [];
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

    public function armoire(): array
    {
        return $this->section('armoire');
    }

    public function connexion(): array
    {
        return $this->section('connexion');
    }

    public function classeurs(): array
    {
        return $this->section('classeurs');
    }

    public function banque(): array
    {
        return $this->section('banque');
    }

    public function exclusions(): array
    {
        $exclusions = $this->get('banque.exclusions', []);
        return is_array($exclusions) ? $exclusions : [];
    }

    public function usages(): array
    {
        return $this->section('usages');
    }

    public function chemin(): string
    {
        return $this->chemin;
    }
}
