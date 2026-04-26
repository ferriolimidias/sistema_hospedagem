<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function mask_value($value): string
{
    if (!is_string($value) || trim($value) === '') {
        return '';
    }
    $raw = trim($value);
    $prefix = substr($raw, 0, 4);
    return $prefix . str_repeat('*', max(0, strlen($raw) - 4));
}

$envPath = __DIR__ . '/../.env';
$envRealPath = realpath($envPath);
$openBasedir = (string) ini_get('open_basedir');
$openBasedirActive = trim($openBasedir) !== '';

$result = [
    'ok' => true,
    'timestamp' => date('c'),
    'php' => [
        'version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'open_basedir_active' => $openBasedirActive,
        'open_basedir' => $openBasedir,
    ],
    'env_file' => [
        'path' => $envPath,
        'realpath' => $envRealPath !== false ? $envRealPath : null,
        'exists' => file_exists($envPath),
        'is_readable' => is_readable($envPath),
    ],
    'env_vars' => [
        'parse_success' => false,
        'error' => null,
        'EVOLUTION_GLOBAL_URL' => '',
        'EVOLUTION_GLOBAL_KEY' => '',
    ],
    'dns_test' => [
        'host' => null,
        'resolved_ip' => null,
        'ok' => false,
        'error' => null,
    ],
    'outbound_test' => [
        'curl_available' => function_exists('curl_init'),
        'target' => 'https://www.google.com',
        'ok' => false,
        'http_code' => null,
        'curl_error' => null,
    ],
];

$envData = null;
if ($result['env_file']['exists'] && $result['env_file']['is_readable']) {
    $parsed = @parse_ini_file($envPath, false, INI_SCANNER_TYPED);
    if (is_array($parsed)) {
        $envData = $parsed;
        $result['env_vars']['parse_success'] = true;
        $result['env_vars']['EVOLUTION_GLOBAL_URL'] = mask_value((string)($parsed['EVOLUTION_GLOBAL_URL'] ?? ''));
        $result['env_vars']['EVOLUTION_GLOBAL_KEY'] = mask_value((string)($parsed['EVOLUTION_GLOBAL_KEY'] ?? ''));
    } else {
        $result['env_vars']['error'] = 'Falha ao interpretar .env com parse_ini_file';
    }
} else {
    $result['env_vars']['error'] = '.env inexistente ou sem permissão de leitura';
}

$url = is_array($envData) ? (string)($envData['EVOLUTION_GLOBAL_URL'] ?? '') : '';
$host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
$result['dns_test']['host'] = $host !== '' ? $host : null;
if ($host !== '') {
    $resolved = gethostbyname($host);
    $result['dns_test']['resolved_ip'] = $resolved;
    if ($resolved !== $host) {
        $result['dns_test']['ok'] = true;
    } else {
        $result['dns_test']['error'] = 'DNS não resolvido para o host configurado';
    }
} else {
    $result['dns_test']['error'] = 'Host inválido ou URL ausente no .env';
}

if ($result['outbound_test']['curl_available']) {
    $ch = curl_init();
    if ($ch !== false) {
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://www.google.com',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        curl_exec($ch);
        $err = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result['outbound_test']['curl_error'] = $err !== '' ? $err : null;
        $result['outbound_test']['http_code'] = $http > 0 ? $http : null;
        $result['outbound_test']['ok'] = ($err === '' && $http >= 200 && $http < 400);
    } else {
        $result['outbound_test']['curl_error'] = 'Falha ao iniciar curl_init';
    }
} else {
    $result['outbound_test']['curl_error'] = 'Extensão cURL não disponível';
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

