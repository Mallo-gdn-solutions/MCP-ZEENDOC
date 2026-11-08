<?php
/**
 * Outils génériques du noyau : normalisation, dates, montants.
 * Fonctions pures, sans réseau, sans écriture.
 */
declare(strict_types=1);

function rb_utf8(string $s): string
{
    if ($s === '' || (function_exists('mb_check_encoding') && mb_check_encoding($s, 'UTF-8'))) {
        return $s;
    }
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
    return utf8_encode($s);
}

function rb_norm(string $s): string
{
    $s = rb_utf8($s);
    if (function_exists('iconv')) {
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false) {
            $s = $t;
        }
    }
    $s = function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
    $s = preg_replace('/[^A-Z0-9]+/', ' ', $s) ?? '';
    return trim(preg_replace('/\s+/', ' ', $s) ?? '');
}

function rb_date(?string $s): ?string
{
    if ($s === null) {
        return null;
    }
    $s = trim(rb_utf8($s));
    if ($s === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
        $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
    } elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})#', $s, $m)) {
        $d = (int) $m[1]; $mo = (int) $m[2]; $y = (int) $m[3];
    } else {
        return null;
    }
    if (!checkdate($mo, $d, $y)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

function rb_ajout(string $date, int $jours): string
{
    $t = strtotime($date . ' ' . ($jours >= 0 ? '+' : '') . $jours . ' days');
    return $t === false ? $date : date('Y-m-d', $t);
}

function rb_jours(string $a, string $b): int
{
    $ta = strtotime($a);
    $tb = strtotime($b);
    if ($ta === false || $tb === false) {
        return 0;
    }
    return (int) round(($tb - $ta) / 86400);
}

function rb_cents(string $s): int
{
    $s = rb_utf8($s);
    $s = str_replace(["\xC2\xA0", "\xE2\x80\xAF", ' ', '€', "\t"], '', $s);
    if ($s === '' || $s === '-') {
        return 0;
    }
    $neg = str_starts_with($s, '-');
    $s = ltrim($s, '-+');
    if (str_contains($s, ',') && str_contains($s, '.')) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif (str_contains($s, ',')) {
        $s = str_replace(',', '.', $s);
    }
    if (!is_numeric($s)) {
        return 0;
    }
    $cents = (int) round(((float) $s) * 100);
    return $neg ? -$cents : $cents;
}

function rb_eur(int $cents): string
{
    $signe = $cents < 0 ? '-' : '';
    return $signe . number_format(abs($cents) / 100, 2, ',', ' ');
}

function rb_compact(string $s): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', rb_utf8($s)) ?? '');
}
