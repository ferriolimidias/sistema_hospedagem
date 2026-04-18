<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pricing.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$reservationId = isset($input['reservation_id']) ? (int)$input['reservation_id'] : 0;
if ($reservationId <= 0) {
    jsonResponse(['error' => 'reservation_id é obrigatório'], 400);
}

try {
    // Limpeza leve de holds vencidos.
    cleanupExpiredPendingReservations($pdo);

    $stmt = $pdo->prepare("
        SELECT r.*, c.name AS chalet_name
        FROM reservations r
        LEFT JOIN chalets c ON c.id = r.chalet_id
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->execute([$reservationId]);
    $reservation = $stmt->fetch();

    if (!$reservation) {
        jsonResponse(['error' => 'Reserva não encontrada'], 404);
    }

    // Idempotência: reaproveita link de checkout ainda válido.
    $existingInitPoint = trim((string)($reservation['mp_init_point'] ?? ''));
    $expiresAt = $reservation['expires_at'] ?? null;
    if ($existingInitPoint !== '' && !empty($expiresAt) && strtotime((string)$expiresAt) > time()) {
        $idempotentRule = strtolower((string)($reservation['payment_rule'] ?? 'full'));
        $idempotentTotal = round((float)($reservation['total_amount'] ?? 0), 2);
        $idempotentAmount = $idempotentRule === 'half' ? round($idempotentTotal / 2, 2) : $idempotentTotal;
        jsonResponse([
            'success' => true,
            'reservation_id' => $reservationId,
            'payment_rule' => $idempotentRule,
            'charged_amount' => $idempotentAmount,
            'init_point' => $existingInitPoint,
            'idempotent' => true
        ]);
    }

    $stmtSettings = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'mercadoPagoSettings' LIMIT 1");
    $stmtSettings->execute();
    $row = $stmtSettings->fetch();
    $mpSettings = $row ? json_decode($row['setting_value'], true) : null;
    $accessToken = $mpSettings['accessToken'] ?? null;

    if (!$accessToken) {
        jsonResponse(['error' => 'Mercado Pago não está configurado'], 400);
    }

    $totalAmount = isset($reservation['total_amount']) ? (float) $reservation['total_amount'] : 0.0;
    if ($totalAmount <= 0) {
        jsonResponse(['error' => 'Valor da reserva inválido'], 400);
    }

    // Coerência com tarifário atual (registo em log; reservas novas já vêm com total calculado em reservations.php)
    $stmtCh = $pdo->prepare('SELECT * FROM chalets WHERE id = ? LIMIT 1');
    $stmtCh->execute([(int) ($reservation['chalet_id'] ?? 0)]);
    $chaletForPricing = $stmtCh->fetch(PDO::FETCH_ASSOC);
    if ($chaletForPricing) {
        $stmtH = $pdo->prepare('SELECT custom_date AS date, price FROM chalet_custom_prices WHERE chalet_id = ?');
        $stmtH->execute([(int) $chaletForPricing['id']]);
        $chaletForPricing['holidays'] = $stmtH->fetchAll(PDO::FETCH_ASSOC);
        $expectedTotal = pricing_reservation_total(
            $chaletForPricing,
            (string) ($reservation['checkin_date'] ?? ''),
            (string) ($reservation['checkout_date'] ?? ''),
            (int) ($reservation['guests_adults'] ?? 2),
            (int) ($reservation['guests_children'] ?? 0)
        );
        if (abs($expectedTotal - $totalAmount) > 0.05) {
            error_log(sprintf(
                'create_preference: total_amount (%.2f) difere do recalculado (%.2f) reserva_id=%s',
                $totalAmount,
                $expectedTotal,
                (string) $reservationId
            ));
        }
    }

    $paymentRule = strtolower((string)($reservation['payment_rule'] ?? 'full'));
    $isHalf = $paymentRule === 'half';
    $chargeAmount = $isHalf ? $totalAmount / 2 : $totalAmount;
    $chargeAmount = round($chargeAmount, 2);

    if ($chargeAmount <= 0) {
        jsonResponse(['error' => 'Valor de cobrança inválido'], 400);
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $requestPath = $_SERVER['REQUEST_URI'] ?? '/api/create_preference.php';
    $basePath = rtrim(str_replace('\\', '/', dirname(dirname($requestPath))), '/');
    $baseUrl = $scheme . '://' . $host . ($basePath ? $basePath : '');
    $webhookUrl = $baseUrl . '/api/mp_webhook.php';

    $guestName = trim((string)($reservation['guest_name'] ?? 'Hóspede'));
    $nameParts = preg_split('/\s+/', $guestName);
    $payerName = $nameParts[0] ?? 'Hóspede';
    $payerSurname = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';

    $description = sprintf(
        'Check-in: %s | Check-out: %s',
        (string)($reservation['checkin_date'] ?? ''),
        (string)($reservation['checkout_date'] ?? '')
    );
    if (!empty($reservation['coupon_code'])) {
        $description .= ' | Cupom: ' . (string) $reservation['coupon_code'];
    }
    if (!empty($reservation['extras_total']) && (float) $reservation['extras_total'] > 0) {
        $description .= ' | Serviços extras: R$ ' . number_format((float) $reservation['extras_total'], 2, ',', '.');
    }

    $preferenceData = [
        'items' => [
            [
                'title' => 'Reserva - ' . ((string)($reservation['chalet_name'] ?? 'Chalé')),
                'description' => $description,
                'quantity' => 1,
                'currency_id' => 'BRL',
                'unit_price' => $chargeAmount
            ]
        ],
        'payer' => [
            'name' => $payerName,
            'surname' => $payerSurname,
            'email' => (string)($reservation['guest_email'] ?? '')
        ],
        'back_urls' => [
            'success' => $baseUrl . '/index.php?payment_success=true&reservation_id=' . $reservationId,
            'failure' => $baseUrl . '/index.php?payment_failed=true&reservation_id=' . $reservationId,
            'pending' => $baseUrl . '/index.php?payment_pending=true&reservation_id=' . $reservationId
        ],
        'auto_return' => 'approved',
        'external_reference' => (string)$reservationId,
        'notification_url' => strpos($webhookUrl, 'https://') === 0 ? $webhookUrl : null
    ];

    $ch = curl_init('https://api.mercadopago.com/checkout/preferences');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($preferenceData),
        CURLOPT_TIMEOUT => 20
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        jsonResponse(['error' => 'Erro ao comunicar com Mercado Pago', 'details' => $curlError], 502);
    }

    $mpResponse = json_decode($response, true);
    if (!is_array($mpResponse)) {
        jsonResponse(['error' => 'Resposta inválida do Mercado Pago', 'raw' => $response], 502);
    }

    if ($httpCode < 200 || $httpCode >= 300 || empty($mpResponse['init_point'])) {
        jsonResponse([
            'error' => 'Falha ao criar preferência no Mercado Pago',
            'status' => $httpCode,
            'mercado_pago' => $mpResponse
        ], 502);
    }

    // Hold de calendário: trava a reserva por 15 minutos com o mesmo link.
    $stmtUpdate = $pdo->prepare("
        UPDATE reservations
        SET mp_init_point = ?,
            expires_at = (NOW() + INTERVAL 15 MINUTE)
        WHERE id = ?
    ");
    $stmtUpdate->execute([(string)$mpResponse['init_point'], $reservationId]);

    jsonResponse([
        'success' => true,
        'reservation_id' => $reservationId,
        'payment_rule' => $paymentRule,
        'charged_amount' => $chargeAmount,
        'init_point' => $mpResponse['init_point'],
        'idempotent' => false
    ]);
} catch (Exception $e) {
    jsonResponse(['error' => 'Erro interno ao criar preferência', 'details' => $e->getMessage()], 500);
}
?>
