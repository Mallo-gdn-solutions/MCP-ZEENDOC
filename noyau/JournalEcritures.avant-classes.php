<?php

final class JournalEcritures
{
    public const FICHIER = 'ecritures-journal.jsonl';

    private StockageLocal $stockage;

    public function __construct(StockageLocal $stockage)
    {
        $this->stockage = $stockage;
    }

    public function enregistrer(array $entree): void
    {
        $entree['quand'] = $entree['quand'] ?? date('c');
        $this->stockage->ajouterLigne(self::FICHIER, $entree);
    }
}
