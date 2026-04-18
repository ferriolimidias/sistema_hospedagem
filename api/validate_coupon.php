<?php
/**
 * Pré-visualização segura do desconto (subtotal enviado pelo cliente — validação final na criação da reserva).
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking_extras.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$code = (string) ($input['code'] ?? '');
$subtotal = isset($input['subtotal']) ? (float) $input['subtotal'] : 0.0;
if ($subtotal < 0) {
    $subtotal = 0.0;
}

try {
    $coupon = be_find_active_coupon($pdo, $code);
    if (!$coupon) {
        jsonResponse(['valid' => false, 'discount' => 0.0, 'message' => 'Cupom inválido ou expirado.']);
    }
    $discount = be_compute_discount($subtotal, $coupon);
    jsonResponse([
        'valid' => true,
        'discount' => $discount,
        'type' => $coupon['type'],
    ]);
} catch (Throwable $e) {
    jsonResponse(['valid' => false, 'discount' => 0.0, 'message' => 'Erro ao validar cupom.'], 500);
}
