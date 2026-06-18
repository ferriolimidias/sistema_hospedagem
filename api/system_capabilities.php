<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/contract_service.php';
require_once __DIR__ . '/evolution_service.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Método não permitido'], 405);
}

$isAdmin = be_get_admin_from_cookie($pdo) !== null;

$payload = [
    'ok' => true,
    'pdf_available' => function_exists('isPdfFeatureAvailable') ? isPdfFeatureAvailable() : false,
    'pdf_message' => 'Recurso de PDF indisponível neste ambiente.',
    'evolution_configured' => function_exists('evo_is_configured') ? evo_is_configured($pdo) : false,
    'whatsapp_configured' => function_exists('evo_is_configured') ? evo_is_configured($pdo) : false,
    'evolution_go_configured' => function_exists('evo_is_configured') ? evo_is_configured($pdo) : false,
    'csv_available' => true,
];

if ($isAdmin) {
    $cfg = function_exists('evo_http_config') ? evo_http_config($pdo) : ['url' => '', 'instance' => ''];
    $payload['evolution_url_configured'] = trim((string)($cfg['url'] ?? '')) !== '';
    $payload['evolution_instance_configured'] = trim((string)($cfg['instance'] ?? '')) !== '';
    $payload['evolution_go_url_configured'] = $payload['evolution_url_configured'];
    $payload['evolution_go_instance_configured'] = $payload['evolution_instance_configured'];
}

jsonResponse($payload);
