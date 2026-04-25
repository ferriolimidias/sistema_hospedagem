<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/contract_service.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

be_require_admin_auth($pdo);

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

