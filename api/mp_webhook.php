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

function loadPaymentPolicies(PDO $pdo): array
{
    $fallback = [
        ['code' => 'half', 'label' => 'Sinal de 50% para reserva', 'percent_now' => 50.0],
        ['code' => 'full', 'label' => 'Pagamento 100% Antecipado', 'percent_now' => 100.0],
    ];
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'payment_policies' LIMIT 1");
        $stmt->execute();
        $raw = $stmt->fetchColumn();
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded) || count($decoded) === 0) return $fallback;
        $clean = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) continue;
            $code = strtolower(trim((string)($item['code'] ?? '')));
            $label = trim((string)($item['label'] ?? ''));
            $pct = isset($item['percent_now']) ? (float)$item['percent_now'] : -1;
            if ($code === '' || $pct <= 0) continue;
            $clean[] = ['code' => $code, 'label' => $label, 'percent_now' => max(0.0, min(100.0, $pct))];
        }
        return count($clean) ? $clean : $fallback;
    } catch (Throwable $e) {
        return $fallback;
    }
}

function findPaymentPolicyByCode(array $policies, string $code): array
{
    foreach ($policies as $policy) {
        if (strtolower((string)($policy['code'] ?? '')) === strtolower($code)) return $policy;
    }
    return strtolower($code) === 'half'
        ? ['code' => 'half', 'label' => 'Sinal de 50% para reserva', 'percent_now' => 50.0]
        : ['code' => 'full', 'label' => 'Pagamento 100% Antecipado', 'percent_now' => 100.0];
}

// MP envia POST com JSON ou query params
$input = file_get_contents('php://input');
$payload = json_decode($input, true);

// Query params (algumas notificações vêm assim)
$paymentId = $_GET['data.id'] ?? $payload['data']['id'] ?? null;
$type = $_GET['type'] ?? $payload['type'] ?? null;

if (!$paymentId || $type !== 'payment') {
    error_log('MP Webhook: evento ignorado (sem paymentId válido ou type diferente de payment)');
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
    error_log('MP Webhook: pagamento não aprovado ou sem external_reference. status=' . (string)$status);
    // Pagamento não aprovado ou sem referência - ignorar
    http_response_code(200);
    exit;
}

$reservationId = (int) $externalRef;
if ($reservationId <= 0) {
    error_log('MP Webhook: external_reference inválida: ' . (string)$externalRef);
    http_response_code(200);
    exit;
}

// Resolve política de pagamento para determinar se quita saldo no primeiro pagamento.
$policies = loadPaymentPolicies($pdo);
$stmtRule = $pdo->prepare("SELECT payment_rule FROM reservations WHERE id = ? LIMIT 1");
$stmtRule->execute([$reservationId]);
$paymentRuleCode = strtolower((string)($stmtRule->fetchColumn() ?: 'full'));
$policy = findPaymentPolicyByCode($policies, $paymentRuleCode);
$percentNow = (float)($policy['percent_now'] ?? 100);
$markAsPaid = $percentNow >= 100 ? 1 : 0;

// Atualizar reserva para Confirmada e marcar saldo quitado quando for pagamento integral.
$stmt = $pdo->prepare("
    UPDATE reservations
    SET status = 'Confirmada',
        balance_paid = CASE WHEN ? = 1 THEN 1 ELSE balance_paid END,
        balance_paid_at = CASE WHEN ? = 1 THEN NOW() ELSE balance_paid_at END
    WHERE id = ? AND status = 'Aguardando Pagamento'
");
$stmt->execute([$markAsPaid, $markAsPaid, $reservationId]);
if ($stmt->rowCount() > 0) {
    error_log('MP Webhook: reserva #' . $reservationId . ' confirmada com sucesso.');
} else {
    error_log('MP Webhook: nenhuma atualização aplicada para reserva #' . $reservationId . ' (status possivelmente já atualizado).');
}

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
        $policyRes = findPaymentPolicyByCode($policies, strtolower((string)($res['payment_rule'] ?? 'full')));
        $pctRes = (float)($policyRes['percent_now'] ?? 100);
        $valorPagoNum = ($totalNum * $pctRes) / 100;
        $condicao = trim((string)($policyRes['label'] ?? ''));
        if ($condicao === '') {
            $condicao = $pctRes >= 100 ? 'Pagamento antecipado integral' : 'Pagamento parcial na reserva';
        }
        $webhookPayload = [
            'clientName' => $res['guest_name'],
            'clientPhone' => $res['guest_phone'],
            'chaletName' => $res['chalet_name'] ?? '',
            'checkin' => $res['checkin_date'],
            'checkout' => $res['checkout_date'],
            'total' => 'R$ ' . number_format($totalNum, 2, ',', '.'),
            'valorPago' => 'R$ ' . number_format($valorPagoNum, 2, ',', '.'),
            'condicao' => $condicao,
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
        $webhookResult = @file_get_contents($webhookUrl, false, $ctx);
        if ($webhookResult === false) {
            error_log('MP Webhook: falha ao chamar send_webhook para reserva #' . $reservationId);
        } else {
            error_log('MP Webhook: send_webhook executado com sucesso para reserva #' . $reservationId);
        }
    }
}

http_response_code(200);
