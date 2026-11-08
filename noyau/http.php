<?php
/**
 * HTTP du noyau : un seul point de passage pour les appels sortants (curl).
 * Retour : ['status' => int, 'entetes' => string, 'corps' => ?string, 'erreur' => ?string]
 * (corps null + erreur renseignée en cas d'échec réseau).
 */
declare(strict_types=1);

function noyau_http(string $url, string $methode, array $entetes, ?string $corps, int $timeout = 60): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'entetes' => '', 'corps' => null, 'erreur' => 'extension curl absente'];
    }
    $options = [
        CURLOPT_CUSTOMREQUEST => $methode,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $entetes,
        CURLOPT_TIMEOUT => $timeout,
    ];
    if (defined('CURLSSLOPT_NATIVE_CA')) {
        $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    }
    if ($corps !== null) {
        $options[CURLOPT_POSTFIELDS] = $corps;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, $options);
    $reponse = curl_exec($ch);
    if ($reponse === false) {
        $erreur = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'entetes' => '', 'corps' => null, 'erreur' => $erreur];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $taille = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $brut = (string) $reponse;
    return [
        'status' => $status,
        'entetes' => substr($brut, 0, $taille),
        'corps' => substr($brut, $taille),
        'erreur' => null,
    ];
}
