<?php
/**
 * Webhook do Mercado Pago - recebe notificações quando o pagamento é aprovado.
 * Atualiza a reserva e envia WhatsApp mesmo se o usuário não for redirecionado.
 *
 * Configure em: https://www.mercadopago.com.br/developers/panel/app
 * Webhooks > Configurar notificações > URL HTTPS > Evento: Pagamentos
 *
 * Ou a notification_url na preferência já envia para este endpoint.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/contract_service.php';

// MP envia POST com JSON ou query params
$input = file_get_contents('php://input');
$payload = json_decode($input, true);

// Query params (algumas notificações vêm assim)
$paymentId = $_GET['data.id'] ?? $payload['data']['id'] ?? null;
$type = $_GET['type'] ?? $payload['type'] ?? null;

if (!$paymentId || $type !== 'payment') {
    // Eventos não relacionados a pagamento devem ser ignorados com 200
    // para evitar retries desnecessários do provedor.
    http_response_code(200);
    exit;
}

// Buscar Access Token do Mercado Pago
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'mercadoPagoSettings'");
$stmt->execute();
$row = $stmt->fetch();
$mpSettings = $row ? json_decode($row['setting_value'], true) : null;
$accessToken = $mpSettings['accessToken'] ?? null;

if (!$accessToken) {
    error_log('MP Webhook: Mercado Pago não configurado');
    // Mantém ACK 200 para não falhar teste de webhook do painel.
    http_response_code(200);
    exit;
}

// Buscar detalhes do pagamento na API do MP
$ch = curl_init('https://api.mercadopago.com/v1/payments/' . $paymentId);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ],
    CURLOPT_TIMEOUT => 15
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    error_log('MP Webhook: Falha ao buscar pagamento ' . $paymentId);
    // O painel do MP pode enviar IDs de teste/fictícios. Não quebrar o endpoint.
    http_response_code(200);
    exit;
}

$payment = json_decode($response, true);
$status = $payment['status'] ?? '';
$externalRef = $payment['external_reference'] ?? null;

if ($status !== 'approved' || !$externalRef) {
    // Pagamento não aprovado ou sem referência - ignorar
    http_response_code(200);
    exit;
}

$reservationId = (int) $externalRef;
if ($reservationId <= 0) {
    http_response_code(200);
    exit;
}

// Atualizar reserva para Confirmada e marcar saldo quitado quando for pagamento integral.
$stmt = $pdo->prepare("
    UPDATE reservations
    SET status = 'Confirmada',
        balance_paid = CASE WHEN LOWER(payment_rule) = 'full' THEN 1 ELSE balance_paid END,
        balance_paid_at = CASE WHEN LOWER(payment_rule) = 'full' THEN NOW() ELSE balance_paid_at END
    WHERE id = ? AND status = 'Aguardando Pagamento'
");
$stmt->execute([$reservationId]);

if ($stmt->rowCount() > 0) {
    // Gera contrato PDF automaticamente no momento da confirmação.
    try {
        generateContractForReservation($pdo, $reservationId);
    } catch (Throwable $e) {
        error_log('MP Webhook: Falha ao gerar contrato da reserva #' . $reservationId . ' - ' . $e->getMessage());
    }

    // Buscar dados da reserva e enviar WhatsApp
    $stmtRes = $pdo->prepare("SELECT r.*, c.name as chalet_name FROM reservations r LEFT JOIN chalets c ON r.chalet_id = c.id WHERE r.id = ?");
    $stmtRes->execute([$reservationId]);
    $res = $stmtRes->fetch();
    if ($res) {
        $totalNum = (float) $res['total_amount'];
        $isHalf = strtolower($res['payment_rule'] ?? '') === 'half';
        $valorPagoNum = $isHalf ? $totalNum / 2 : $totalNum;
        $webhookPayload = [
            'clientName' => $res['guest_name'],
            'clientPhone' => $res['guest_phone'],
            'chaletName' => $res['chalet_name'] ?? '',
            'checkin' => $res['checkin_date'],
            'checkout' => $res['checkout_date'],
            'total' => 'R$ ' . number_format($totalNum, 2, ',', '.'),
            'valorPago' => 'R$ ' . number_format($valorPagoNum, 2, ',', '.'),
            'condicao' => $isHalf ? 'Sinal de 50%' : '100% à vista',
            'paymentRule' => $res['payment_rule'] ?? 'full',
            'id' => $res['id']
        ];
        // Enviar WhatsApp via send_webhook
        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $apiPath = dirname($_SERVER['SCRIPT_NAME'] ?? '/api');
        $webhookUrl = rtrim($base . $apiPath, '/') . '/send_webhook.php';
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($webhookPayload),
                'timeout' => 10
            ]
        ]);
        @file_get_contents($webhookUrl, false, $ctx);
    }
}

http_response_code(200);
