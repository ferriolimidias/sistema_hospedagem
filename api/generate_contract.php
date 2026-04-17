<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/contract_service.php';
require_once __DIR__ . '/contract_access.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

function getRequestHeadersNormalized(): array
{
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = (string)$value;
        }
    }
    return $headers;
}

function isContractGenerationAuthorized(PDO $pdo, array $headers): bool
{
    if (defined('INTERNAL_CONTRACT_CALL') && INTERNAL_CONTRACT_CALL === true) {
        return true;
    }

    $provided = trim((string)($headers['x-internal-key'] ?? ''));
    if ($provided === '') {
        return false;
    }

    $expected = getOrCreateInternalApiKey($pdo);
    if ($expected === '') {
        return false;
    }

    return hash_equals($expected, $provided);
}

$headers = getRequestHeadersNormalized();
if (!isContractGenerationAuthorized($pdo, $headers)) {
    jsonResponse(['error' => 'Não autorizado para gerar contrato'], 403);
}

$input = json_decode(file_get_contents('php://input'), true);
$reservationId = isset($input['reservation_id']) ? (int)$input['reservation_id'] : 0;
if ($reservationId <= 0) {
    jsonResponse(['error' => 'reservation_id é obrigatório'], 400);
}

try {
    $result = generateContractForReservation($pdo, $reservationId);
    jsonResponse([
        'success' => true,
        'reservation_id' => $result['reservation_id'],
        'contract_filename' => $result['filename']
    ]);
} catch (Throwable $e) {
    jsonResponse(['error' => 'Falha ao gerar contrato', 'details' => $e->getMessage()], 500);
}

