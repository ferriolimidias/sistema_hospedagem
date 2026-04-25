<?php
/**
 * Envia mensagens de confirmação de reserva via Evolution API (server-side).
 * Evita problemas de CORS quando o frontend chama a Evolution API diretamente.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/contract_access.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

be_require_admin_auth($pdo);

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
    error_log('send_webhook: Evolution API não configurada corretamente.');
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
$welcomeMsg = $settings['welcomeMsg'] ?? '';
$event = (string)($input['event'] ?? 'reservation_confirmed');

$runtimeCfg = [
    'checkin_time' => '14:00',
    'checkout_time' => '12:00',
    'house_rules' => '',
    'wifi_name' => '',
    'wifi_password' => '',
    'company_name' => '',
];
try {
    $stmtRuntime = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('checkin_time', 'checkout_time', 'house_rules', 'wifi_name', 'wifi_password', 'company_name')");
    foreach ($stmtRuntime ? $stmtRuntime->fetchAll(PDO::FETCH_ASSOC) : [] as $rowCfg) {
        $keyCfg = (string) ($rowCfg['setting_key'] ?? '');
        $valCfg = trim((string) ($rowCfg['setting_value'] ?? ''));
        if ($keyCfg !== '' && array_key_exists($keyCfg, $runtimeCfg) && $valCfg !== '') {
            $runtimeCfg[$keyCfg] = $valCfg;
        }
    }
} catch (Throwable $e) {
    error_log('send_webhook: falha ao carregar settings de runtime - ' . $e->getMessage());
}
if ($runtimeCfg['company_name'] === '') {
    try {
        $stmtBrand = $pdo->query("SELECT hero_titulo FROM personalizacao ORDER BY id DESC LIMIT 1");
        $heroBrand = trim((string) ($stmtBrand ? $stmtBrand->fetchColumn() : ''));
        if ($heroBrand !== '') {
            $runtimeCfg['company_name'] = $heroBrand;
        }
    } catch (Throwable $e) {
        error_log('send_webhook: falha ao obter marca na personalização - ' . $e->getMessage());
    }
}
$companyDisplayName = $runtimeCfg['company_name'] !== '' ? $runtimeCfg['company_name'] : 'Hospedagem';

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
$condicao = $input['condicao'] ?? 'Condição de pagamento configurada';
$reservationId = isset($input['id']) ? (int)$input['id'] : 0;

$numCliente = preg_replace('/\D/', '', $input['clientPhone']);
$formatPhoneClient = strlen($numCliente) <= 11 ? '55' . $numCliente : $numCliente;

// Montar mensagem para o cliente
if ($event === 'balance_paid') {
    if ($welcomeMsg && trim($welcomeMsg) !== '') {
        $msgClient = $welcomeMsg;
        $msgClient = preg_replace('/\{nome\}/iu', $input['clientName'], $msgClient);
        $msgClient = preg_replace('/\{chale\}/iu', $input['chaletName'] ?? '', $msgClient);
        $msgClient = preg_replace('/\{checkin\}/iu', $checkinBR, $msgClient);
        $msgClient = preg_replace('/\{checkout\}/iu', $checkoutBR, $msgClient);
    } else {
        $wifiLine = '';
        if ($runtimeCfg['wifi_name'] !== '') {
            $wifiLine = "\n📶 Wi-Fi: Rede *{$runtimeCfg['wifi_name']}*";
            if ($runtimeCfg['wifi_password'] !== '') {
                $wifiLine .= " | Senha *{$runtimeCfg['wifi_password']}*";
            }
        }
        $rulesText = trim((string) $runtimeCfg['house_rules']);
        if ($rulesText === '') {
            $rulesText = 'Check-in a partir de ' . $runtimeCfg['checkin_time'] . ' e check-out até ' . $runtimeCfg['checkout_time'] . '.';
        }
        $msgClient = "*{$companyDisplayName}*\n\nOlá, {$input['clientName']}! Confirmamos o recebimento do saldo da sua reserva. ✅\n\nEstamos ansiosos para recebê-lo(a) em *" . ($input['chaletName'] ?? '') . "*.{$wifiLine}\n🏡 Regras da casa: {$rulesText}\n\nQualquer dúvida, responda esta mensagem.";
    }
} elseif ($reservationMsg && trim($reservationMsg) !== '') {
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
    $msgClient = "*{$companyDisplayName}*\n\nOlá, {$input['clientName']}! Sua reserva foi confirmada com sucesso. 🎉\n\n*Detalhes da sua estadia:*\n🏠 Acomodação: *" . ($input['chaletName'] ?? '') . "*\n📅 Check-in: *{$checkinBR}*\n📅 Check-out: *{$checkoutBR}*\n{$linhaValor}\n\nAguardamos ansiosamente a sua chegada. Para qualquer dúvida, responda essa mensagem.";
}

// Tenta incluir link seguro do contrato PDF quando existir arquivo gerado.
if ($reservationId > 0) {
    try {
        $stmtContract = $pdo->prepare("SELECT contract_filename, guest_email, checkout_date FROM reservations WHERE id = ? LIMIT 1");
        $stmtContract->execute([$reservationId]);
        $contract = $stmtContract->fetch();
        $contractFilename = trim((string)($contract['contract_filename'] ?? ''));
        $guestEmail = trim((string)($contract['guest_email'] ?? ($input['clientEmail'] ?? '')));

        if ($contractFilename !== '' && $guestEmail !== '') {
            $secret = getOrCreateInternalApiKey($pdo);
            $token = buildContractAccessToken(
                $reservationId,
                $contractFilename,
                $guestEmail,
                (string)($contract['checkout_date'] ?? ''),
                $secret
            );
            $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $apiPath = dirname($_SERVER['SCRIPT_NAME'] ?? '/api');
            $downloadUrl = rtrim($base . $apiPath, '/') . '/download_contract.php?id=' . $reservationId . '&email=' . rawurlencode($guestEmail) . '&token=' . rawurlencode($token);
            $msgClient .= "\n\n📄 *Contrato em PDF:*\n{$downloadUrl}";
        }

        $stmtFnrh = $pdo->prepare('SELECT fnrh_access_token FROM reservations WHERE id = ? LIMIT 1');
        $stmtFnrh->execute([$reservationId]);
        $fnrhTok = trim((string) ($stmtFnrh->fetchColumn() ?: ''));
        if ($fnrhTok !== '') {
            $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/api/send_webhook.php'));
            $apiDir = dirname($scriptPath);
            $siteRoot = dirname($apiDir);
            $siteRoot = $siteRoot === '/' || $siteRoot === '\\' ? '' : $siteRoot;
            $checkinUrl = rtrim($base . $siteRoot, '/') . '/checkin.php?id=' . rawurlencode($fnrhTok);
            $msgClient .= "\n\n📋 *Check-in online (FNRH):*\n{$checkinUrl}";
        }
    } catch (Throwable $e) {
        // Não interrompe envio da mensagem por falha de link do contrato.
        error_log('send_webhook: falha ao compor links auxiliares da reserva #' . $reservationId . ' - ' . $e->getMessage());
    }
}

// Mensagem para a empresa
$linhaValorAdmin = !empty($input['valorPago'])
    ? "*Valor pago:* {$valorPago} ({$condicao})\n*Total da reserva:* {$totalReserva}"
    : "*Valor:* {$totalReserva}";
$tituloAdmin = !empty($input['manual']) ? "🔔 *ADMIN: NOVA RESERVA RECEBIDA (CRIADA MANUALMENTE)*" : "🔔 *ADMIN: NOVA RESERVA RECEBIDA*";
if ($event === 'balance_paid') {
    $msgEmpresa = "💵 *ADMIN: SALDO RECEBIDO NO CHECK-IN*\n\n*Hóspede:* {$input['clientName']}\n*Contato:* {$input['clientPhone']}\n*Acomodação:* " . ($input['chaletName'] ?? '') . "\n*Check-in:* {$checkinBR}\n*Check-out:* {$checkoutBR}\n*Status financeiro:* Total quitado\n*ID:* #" . ($input['id'] ?? '---');
} else {
    $msgEmpresa = "{$tituloAdmin}\n\n*Hóspede:* {$input['clientName']}\n*Contato:* {$input['clientPhone']}\n*Acomodação:* " . ($input['chaletName'] ?? '') . "\n*Check-in:* {$checkinBR}\n*Check-out:* {$checkoutBR}\n{$linhaValorAdmin}\n*ID:* #" . ($input['id'] ?? '---');
}

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
if ($clienteOk && $empresaOk) {
    error_log('send_webhook: envio concluído reserva #' . ($reservationId > 0 ? $reservationId : 0) . ' evento=' . $event);
} else {
    error_log('send_webhook: falha no envio reserva #' . ($reservationId > 0 ? $reservationId : 0) . ' evento=' . $event . ' cliente=' . ($clienteOk ? 'ok' : 'erro') . ' empresa=' . ($empresaOk ? 'ok' : 'erro'));
}

echo json_encode([
    'success' => $clienteOk,
    'message' => $clienteOk ? 'Mensagens enviadas com sucesso.' : 'Falha ao enviar mensagem para o cliente. Verifique as configurações da Evolution API.',
    'error' => $clienteOk ? null : 'Evolution API não respondeu ou credenciais inválidas.'
]);
