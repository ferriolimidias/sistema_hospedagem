<?php
declare(strict_types=1);

if (defined('APP_BOOTSTRAPPED')) {
    return;
}
define('APP_BOOTSTRAPPED', true);

$configPath = __DIR__ . '/config/database.php';
$isInstalled = file_exists($configPath);

if ($isInstalled) {
    return;
}

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?: $scriptName;
$normalizedPath = str_replace('\\', '/', (string)$requestPath);

$isSetupRequest = preg_match('#/setup\.php$#i', $normalizedPath) === 1;
$isApiRequest = preg_match('#/api(/|$)#i', $normalizedPath) === 1;

if ($isSetupRequest) {
    return;
}

if ($isApiRequest) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => 'Aplicação ainda não instalada. Execute /setup.php para concluir a instalação.'
    ]);
    exit;
}

header('Location: /setup.php');
exit;

