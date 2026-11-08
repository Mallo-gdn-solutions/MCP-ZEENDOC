<?php
/**
 * Journal du noyau : chaque écriture faite dans Zeendoc est tracée ici
 * (qui, quand, quel document, quelles valeurs avant/après), en JSONL.
 */
declare(strict_types=1);

if (!defined('NOYAU_JOURNAL')) {
    define('NOYAU_JOURNAL', __DIR__ . '/../ecritures-journal.jsonl');
}

function noyau_journal_ajouter(array $ligne): void
{
    $ligne['quand'] = $ligne['quand'] ?? date('c');
    $json = json_encode($ligne, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException("Journal : échec d'encodage d'une ligne");
    }
    $f = fopen(NOYAU_JOURNAL, 'ab');
    if ($f === false) {
        throw new RuntimeException('Journal : impossible d\'ouvrir ' . NOYAU_JOURNAL);
    }
    flock($f, LOCK_EX);
    fwrite($f, $json . "\n");
    flock($f, LOCK_UN);
    fclose($f);
}

function noyau_journal_lire(): array
{
    if (!is_file(NOYAU_JOURNAL)) {
        return [];
    }
    $out = [];
    foreach (file(NOYAU_JOURNAL, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ligne) {
        $j = json_decode($ligne, true);
        if (is_array($j)) {
            $out[] = $j;
        }
    }
    return $out;
}
