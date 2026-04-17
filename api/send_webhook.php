<?php
/**
 * Envia mensagens de confirmação de reserva via Evolution API (server-side).
 * Evita problemas de CORS quando o frontend chama a Evolution API diretamente.
 */
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['clientName']) || empty($input['clientPhone'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Dados da reserva inválidos']);
    exit;
}

// Buscar configurações da Evolution API
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'evolutionSettings'");
$stmt->execute();
$row = $stmt->fetch();
$settings = $row ? json_decode($row['setting_value'], true) : null;

if (!$settings || empty($settings['url']) || empty($settings['clientInstance']) || empty($settings['clientApikey']) || empty($settings['companyInstance']) || empty($settings['companyApikey']) || empty($settings['companyPhone'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Evolution API não configurada. Configure em Configurações > Integrações.']);
    exit;
}

$url = rtrim($settings['url'], '/');
$clientInstance = $settings['clientInstance'];
$clientApikey = $settings['clientApikey'];
$companyInstance = $settings['companyInstance'];
$companyApikey = $settings['companyApikey'];
$companyPhone = preg_replace('/\D/', '', $settings['companyPhone']);
$reservationMsg = $settings['reservationMsg'] ?? '';

// Formatar datas DD/MM/YYYY
$fmtDate = function ($s) {
    if (!$s) return $s;
    $parts = explode('-', $s);
    if (count($parts) === 3 && strlen($parts[0]) === 4) {
        return $parts[2] . '/' . $parts[1] . '/' . $parts[0];
    }
    return $s;
};

$checkinBR = $fmtDate($input['checkin'] ?? '');
$checkoutBR = $fmtDate($input['checkout'] ?? '');
$totalReserva = $input['total'] ?? '';
$valorPago = $input['valorPago'] ?? $input['total'] ?? '';
$condicao = $input['condicao'] ?? '100% à vista';

$numCliente = preg_replace('/\D/', '', $input['clientPhone']);
$formatPhoneClient = strlen($numCliente) <= 11 ? '55' . $numCliente : $numCliente;

// Montar mensagem para o cliente
if ($reservationMsg && trim($reservationMsg) !== '') {
    $msgClient = $reservationMsg;
    $msgClient = preg_replace('/\{nome\}/iu', $input['clientName'], $msgClient);
    $msgClient = preg_replace('/\{chale\}/iu', $input['chaletName'] ?? '', $msgClient);
    $msgClient = preg_replace('/\{checkin\}/iu', $checkinBR, $msgClient);
    $msgClient = preg_replace('/\{checkout\}/iu', $checkoutBR, $msgClient);
    $msgClient = preg_replace('/\{total\}/iu', $totalReserva, $msgClient);
    $msgClient = preg_replace('/\{valor_pago\}/iu', $valorPago, $msgClient);
    $msgClient = preg_replace('/\{condicao\}/iu', $condicao, $msgClient);
    $msgClient = preg_replace('/\{id\}/iu', $input['id'] ?? '---', $msgClient);
} else {
    $linhaValor = !empty($input['valorPago'])
        ? "💰 Valor pago ({$condicao}): *{$valorPago}*\n💰 Total da reserva: *{$totalReserva}*" . (($input['paymentRule'] ?? '') === 'half' ? "\n(O restante será pago no check-in)" : '')
        : "💰 Total: *{$totalReserva}*";
    $msgClient = "*Recantos da Serra*\n\nOlá, {$input['clientName']}! Sua reserva foi confirmada com sucesso. 🎉\n\n*Detalhes da sua estadia:*\n🏠 Acomodação: *" . ($input['chaletName'] ?? '') . "*\n📅 Check-in: *{$checkinBR}*\n📅 Check-out: *{$checkoutBR}*\n{$linhaValor}\n\nAguardamos ansiosamente a sua chegada. Para qualquer dúvida, responda essa mensagem.";
}

// Mensagem para a empresa
$linhaValorAdmin = !empty($input['valorPago'])
    ? "*Valor pago:* {$valorPago} ({$condicao})\n*Total da reserva:* {$totalReserva}"
    : "*Valor:* {$totalReserva}";
$tituloAdmin = !empty($input['manual']) ? "🔔 *ADMIN: NOVA RESERVA RECEBIDA (CRIADA MANUALMENTE)*" : "🔔 *ADMIN: NOVA RESERVA RECEBIDA*";
$msgEmpresa = "{$tituloAdmin}\n\n*Hóspede:* {$input['clientName']}\n*Contato:* {$input['clientPhone']}\n*Acomodação:* " . ($input['chaletName'] ?? '') . "\n*Check-in:* {$checkinBR}\n*Check-out:* {$checkoutBR}\n{$linhaValorAdmin}\n*ID:* #" . ($input['id'] ?? '---');

$sendToEvolution = function ($instance, $apikey, $number, $text) use ($url) {
    $endpoint = $url . '/message/sendText/' . $instance;
    $payload = json_encode(['number' => $number, 'text' => $text]);
    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\napikey: {$apikey}\r\n",
            'content' => $payload,
            'timeout' => 15
        ]
    ];
    $ctx = stream_context_create($opts);
    $result = @file_get_contents($endpoint, false, $ctx);
    return $result !== false;
};

$clienteOk = $sendToEvolution($clientInstance, $clientApikey, $formatPhoneClient, $msgClient);
$empresaOk = $sendToEvolution($companyInstance, $companyApikey, $companyPhone, $msgEmpresa);

echo json_encode([
    'success' => $clienteOk,
    'message' => $clienteOk ? 'Mensagens enviadas com sucesso.' : 'Falha ao enviar mensagem para o cliente. Verifique as configurações da Evolution API.',
    'error' => $clienteOk ? null : 'Evolution API não respondeu ou credenciais inválidas.'
]);
