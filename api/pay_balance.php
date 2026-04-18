<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/contract_access.php';

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
            $pct = isset($item['percent_now']) ? (float)$item['percent_now'] : -1;
            $label = trim((string)($item['label'] ?? ''));
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

$headers = [];
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0) {
        $name = strtolower(str_replace('_', '-', substr($k, 5)));
        $headers[$name] = (string)$v;
    }
}

$providedKey = trim((string)($headers['x-internal-key'] ?? ''));
$internalApiKey = getOrCreateInternalApiKey($pdo);
if ($providedKey === '' || !hash_equals($internalApiKey, $providedKey)) {
    jsonResponse(['error' => 'Não autorizado'], 403);
}

$data = json_decode(file_get_contents('php://input'), true);
$reservationId = isset($data['reservation_id']) ? (int)$data['reservation_id'] : 0;
if ($reservationId <= 0) {
    jsonResponse(['error' => 'reservation_id é obrigatório'], 400);
}

function appendBalanceAuditLog(int $reservationId): void
{
    $logsDir = realpath(__DIR__ . '/../storage/logs');
    if (!$logsDir || !is_dir($logsDir)) {
        $logsDir = __DIR__ . '/../storage/logs';
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
        }
        $logsDir = realpath($logsDir) ?: $logsDir;
    }
    $htaccess = $logsDir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Deny from all\n");
    }
    $line = date('c') . "\treservation_id={$reservationId}\tip=" . ($_SERVER['REMOTE_ADDR'] ?? '-') . "\n";
    @file_put_contents($logsDir . DIRECTORY_SEPARATOR . 'balance_audit.txt', $line, FILE_APPEND | LOCK_EX);
}

try {
    $policies = loadPaymentPolicies($pdo);
    $stmtLoad = $pdo->prepare("
        SELECT id, status, payment_rule, balance_paid, guest_name, guest_email, guest_phone, total_amount, checkin_date, checkout_date, chalet_id
        FROM reservations
        WHERE id = ?
        LIMIT 1
    ");
    $stmtLoad->execute([$reservationId]);
    $row = $stmtLoad->fetch();
    if (!$row) {
        jsonResponse(['error' => 'Reserva não encontrada'], 404);
    }

    $status = trim((string)($row['status'] ?? ''));
    if ($status !== 'Confirmada') {
        jsonResponse([
            'error' => 'Baixa manual só é permitida para reservas confirmadas.',
            'detail' => 'Status atual: ' . ($status !== '' ? $status : '(vazio)')
        ], 409);
    }

    $policy = findPaymentPolicyByCode($policies, strtolower((string)($row['payment_rule'] ?? 'full')));
    $percentNow = (float)($policy['percent_now'] ?? 100.0);
    if ($percentNow >= 100.0) {
        jsonResponse(['error' => 'Esta reserva já foi configurada para quitação total no primeiro pagamento.'], 409);
    }

    if ((int)($row['balance_paid'] ?? 0) === 1) {
        jsonResponse(['error' => 'O saldo desta reserva já foi registrado como pago.'], 409);
    }

    $stmtUpd = $pdo->prepare("
        UPDATE reservations
        SET balance_paid = 1,
            balance_paid_at = NOW()
        WHERE id = ?
          AND status = 'Confirmada'
          AND balance_paid = 0
        LIMIT 1
    ");
    $stmtUpd->execute([$reservationId]);
    if ($stmtUpd->rowCount() === 0) {
        jsonResponse(['error' => 'Não foi possível aplicar a baixa. Verifique o estado da reserva.'], 409);
    }

    appendBalanceAuditLog($reservationId);

    $stmtRes = $pdo->prepare("
        SELECT r.*, c.name AS chalet_name
        FROM reservations r
        LEFT JOIN chalets c ON c.id = r.chalet_id
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmtRes->execute([$reservationId]);
    $res = $stmtRes->fetch();
    if (!$res) {
        jsonResponse(['error' => 'Reserva não encontrada após atualização'], 500);
    }

    $totalNum = (float)$res['total_amount'];
    $payload = [
        'event' => 'balance_paid',
        'manual' => true,
        'id' => $reservationId,
        'clientName' => $res['guest_name'],
        'clientEmail' => $res['guest_email'],
        'clientPhone' => $res['guest_phone'],
        'chaletName' => $res['chalet_name'] ?? '',
        'checkin' => $res['checkin_date'],
        'checkout' => $res['checkout_date'],
        'total' => 'R$ ' . number_format($totalNum, 2, ',', '.'),
        'valorPago' => 'R$ ' . number_format($totalNum, 2, ',', '.'),
        'condicao' => 'Saldo recebido no check-in',
        'paymentRule' => $res['payment_rule'] ?? 'full'
    ];

    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $apiPath = dirname($_SERVER['SCRIPT_NAME'] ?? '/api');
    $sendWebhookUrl = rtrim($base . $apiPath, '/') . '/send_webhook.php';
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($payload),
            'timeout' => 10
        ]
    ]);
    @file_get_contents($sendWebhookUrl, false, $ctx);

    jsonResponse([
        'success' => true,
        'reservation_id' => $reservationId,
        'balance_paid' => 1,
        'balance_paid_at' => $res['balance_paid_at'] ?? null
    ]);
} catch (Throwable $e) {
    jsonResponse(['error' => 'Falha ao registrar baixa de saldo', 'details' => $e->getMessage()], 500);
}
