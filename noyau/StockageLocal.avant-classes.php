<?php

final class StockageLocal
{
    private string $dossier;

    public function __construct(string $dossier)
    {
        $this->dossier = rtrim($dossier, '/');
        if (!is_dir($this->dossier) && !mkdir($this->dossier, 0700, true)) {
            throw new RuntimeException("Impossible de créer le dossier de données : {$this->dossier}");
        }
    }

    public function dossier(): string
    {
        return $this->dossier;
    }

    private function chemin(string $nom): string
    {
        if ($nom === '' || preg_match('#[/\\\\]#', $nom) || str_contains($nom, '..')) {
            throw new InvalidArgumentException("Nom de fichier local non autorisé : $nom");
        }
        return $this->dossier . '/' . $nom;
    }

    public function existe(string $nom): bool
    {
        return is_file($this->chemin($nom));
    }

    public function lireJson(string $nom): ?array
    {
        $chemin = $this->chemin($nom);
        if (!is_file($chemin)) {
            return null;
        }
        $donnees = json_decode((string) file_get_contents($chemin), true);
        return is_array($donnees) ? $donnees : null;
    }

    public function ecrireJson(string $nom, array $donnees): void
    {
        $json = json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException("Échec d'encodage JSON pour $nom");
        }
        if (file_put_contents($this->chemin($nom), $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException("Échec d'écriture du fichier local : $nom");
        }
    }

    public function ajouterLigne(string $nom, array $ligne): void
    {
        $json = json_encode($ligne, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException("Échec d'encodage JSON pour $nom");
        }
        $fichier = fopen($this->chemin($nom), 'ab');
        if ($fichier === false) {
            throw new RuntimeException("Échec d'ouverture du fichier local : $nom");
        }
        flock($fichier, LOCK_EX);
        fwrite($fichier, $json . "\n");
        flock($fichier, LOCK_UN);
        fclose($fichier);
    }
}
