<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking_extras.php';
require_once __DIR__ . '/contract_service.php';

function evo_setting(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        if (!is_string($v)) return $default;
        $d = json_decode($v, true);
        return (json_last_error() === JSON_ERROR_NONE && is_string($d)) ? $d : $v;
    } catch (Throwable $e) {
        return $default;
    }
}

function evo_flag(PDO $pdo, string $key, bool $default = false): bool
{
    $v = strtolower(trim(evo_setting($pdo, $key, $default ? '1' : '0')));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

function evo_brand_name(PDO $pdo): string
{
    $siteTitle = trim(evo_setting($pdo, 'site_title', ''));
    if ($siteTitle !== '') return $siteTitle;
    $company = trim(evo_setting($pdo, 'company_name', ''));
    if ($company !== '') return $company;
    return 'Hospedagem';
}

function evo_phone_normalize(string $raw): string
{
    $n = preg_replace('/\D/', '', $raw);
    if (!is_string($n)) return '';
    if ($n === '') return '';
    return strlen($n) <= 11 ? ('55' . $n) : $n;
}

function evo_fmt_money(float $n): string
{
    return 'R$ ' . number_format($n, 2, ',', '.');
}

function evo_extract_remote_error(string $body): string
{
    $raw = trim($body);
    if ($raw === '') return '';
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return '';
    $candidate = $decoded['error'] ?? $decoded['message'] ?? ($decoded['response']['message'] ?? '');
    if (is_string($candidate) && trim($candidate) !== '') {
        return trim($candidate);
    }
    if (is_array($candidate)) {
        $flat = [];
        array_walk_recursive($candidate, static function ($v) use (&$flat) {
            if (is_scalar($v)) $flat[] = (string)$v;
        });
        return trim(implode('; ', array_filter($flat)));
    }
    return '';
}

function evo_compose_send_error(int $httpCode, string $curlError, string $body): string
{
    $parts = ['Falha na Evolution (HTTP ' . $httpCode . ')'];
    if (trim($curlError) !== '') {
        $parts[] = 'cURL: ' . trim($curlError);
    }
    $remote = evo_extract_remote_error($body);
    if ($remote !== '') {
        $parts[] = 'Evolution: ' . $remote;
    } elseif (trim($body) !== '') {
        $parts[] = 'Body: ' . trim($body);
    }
    return implode(' ', $parts);
}

function evo_http_config(PDO $pdo): array
{
    $global = function_exists('be_evolution_global_config')
        ? be_evolution_global_config()
        : ['enabled' => false, 'url' => '', 'key' => '', 'env_found' => false];
    $url = rtrim(trim((string) ($global['url'] ?? '')), '/');
    $globalKey = trim((string) ($global['key'] ?? ''));
    $instance = trim(evo_setting($pdo, 'evo_instance', ''));
    // Token da instância no banco tem prioridade; fallback para chave global do .env.
    $instanceApiKey = trim(evo_setting($pdo, 'evo_apikey', ''));
    $apikey = $instanceApiKey !== '' ? $instanceApiKey : $globalKey;
    return ['url' => $url, 'instance' => $instance, 'apikey' => $apikey, 'global_key' => $globalKey];
}

function evo_send_text(PDO $pdo, string $number, string $text): array
{
    $cfg = evo_http_config($pdo);
    $url = (string) ($cfg['url'] ?? '');
    $instance = (string) ($cfg['instance'] ?? '');
    $apikey = (string) ($cfg['apikey'] ?? '');
    if ($url === '' || $instance === '' || $apikey === '') {
        return ['ok' => false, 'error' => 'Evolution API não configurada (.env URL/KEY global e instância ativa são obrigatórios).'];
    }
    $number = preg_replace('/[^0-9]/', '', (string)$number) ?? '';
    if ($number === '' || trim($text) === '') {
        return ['ok' => false, 'error' => 'Número ou texto inválido.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL indisponível no servidor.'];
    }
    $endpoint = $url . '/message/sendText/' . rawurlencode($instance);
    $payload = json_encode(['number' => $number, 'text' => $text], JSON_UNESCAPED_UNICODE);
    try {
        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('Falha ao inicializar cURL.');
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'apikey: ' . $apikey,
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } catch (Throwable $e) {
        error_log('[evolution_service] fail-fast exception: ' . $e->getMessage());
        // Nunca interromper o fluxo principal (reserva/check-in/check-out).
        return ['ok' => true, 'skipped' => true, 'reason' => 'fail_fast_exception'];
    }
    $ok = ($err === '' && $code >= 200 && $code < 300);
    $bodyText = is_string($body) ? $body : '';
    if (!$ok) {
        $logBody = function_exists('mb_substr') ? mb_substr($bodyText, 0, 1200) : substr($bodyText, 0, 1200);
        error_log('[evolution_service] fail http=' . $code . ' err=' . $err . ' body=' . $logBody);
        return [
            'ok' => false,
            'http_code' => $code,
            'error' => evo_compose_send_error($code, $err, $bodyText),
            'body' => $bodyText
        ];
    }
    return ['ok' => true, 'http_code' => $code, 'error' => '', 'body' => $bodyText];
}

function evo_send_media(
    PDO $pdo,
    string $number,
    string $base64Data,
    string $fileName,
    string $mimetype = 'application/pdf',
    string $caption = ''
): array {
    $cfg = evo_http_config($pdo);
    $url = (string) ($cfg['url'] ?? '');
    $instance = (string) ($cfg['instance'] ?? '');
    $apikey = (string) ($cfg['apikey'] ?? '');
    if ($url === '' || $instance === '' || $apikey === '') {
        return ['ok' => false, 'error' => 'Evolution API não configurada (.env URL/KEY global e instância ativa são obrigatórios).'];
    }
    $number = preg_replace('/[^0-9]/', '', (string)$number) ?? '';
    $media = trim($base64Data);
    if (strpos($media, ',') !== false && preg_match('/^data:[^;]+;base64,/i', $media) === 1) {
        $parts = explode(',', $media, 2);
        $media = trim((string)($parts[1] ?? ''));
    }
    if ($number === '' || $media === '' || trim($fileName) === '') {
        return ['ok' => false, 'error' => 'Parâmetros inválidos para envio de mídia.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL indisponível no servidor.'];
    }
    $endpoint = $url . '/message/sendMedia/' . rawurlencode($instance);
    $payload = json_encode([
        'number' => $number,
        'mediatype' => 'document',
        'mimetype' => trim($mimetype) !== '' ? $mimetype : 'application/pdf',
        'media' => $media,
        'fileName' => trim($fileName),
        'caption' => (string)$caption,
    ], JSON_UNESCAPED_UNICODE);
    try {
        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('Falha ao inicializar cURL.');
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'apikey: ' . $apikey,
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } catch (Throwable $e) {
        error_log('[evolution_service] sendMedia exception: ' . $e->getMessage());
        return ['ok' => false, 'http_code' => 0, 'error' => 'Falha cURL: ' . $e->getMessage(), 'body' => ''];
    }
    $bodyText = is_string($body) ? $body : '';
    $ok = ($err === '' && $code >= 200 && $code < 300);
    if (!$ok) {
        $logBody = function_exists('mb_substr') ? mb_substr($bodyText, 0, 1200) : substr($bodyText, 0, 1200);
        error_log('[evolution_service] sendMedia fail http=' . $code . ' err=' . $err . ' body=' . $logBody);
        return [
            'ok' => false,
            'http_code' => $code,
            'error' => evo_compose_send_error($code, $err, $bodyText),
            'body' => $bodyText
        ];
    }
    return ['ok' => true, 'http_code' => $code, 'error' => '', 'body' => $bodyText];
}

function evo_build_folio_receipt_pdf_base64(array $data): array
{
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoloadPath)) {
        return ['ok' => false, 'error' => 'Dependências PHP não instaladas (vendor/autoload.php ausente).'];
    }
    require_once $autoloadPath;
    if (!class_exists(\Dompdf\Dompdf::class) || !class_exists(\Dompdf\Options::class)) {
        return ['ok' => false, 'error' => 'Dompdf indisponível para gerar recibo PDF.'];
    }
    $brand = htmlspecialchars((string)($data['brand'] ?? 'Hospedagem'), ENT_QUOTES, 'UTF-8');
    $guestName = htmlspecialchars((string)($data['guest_name'] ?? 'Hóspede'), ENT_QUOTES, 'UTF-8');
    $reservationId = (int)($data['reservation_id'] ?? 0);
    $totalDiarias = (float)($data['total_diarias'] ?? 0);
    $consumoExtra = (float)($data['consumo_extra'] ?? 0);
    $totalFinal = (float)($data['total_final'] ?? 0);
    $fmt = static fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
    $receiptNo = $reservationId > 0 ? ('RES-' . str_pad((string)$reservationId, 5, '0', STR_PAD_LEFT)) : 'RES-----';
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>
        body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#111;margin:24px}
        h1{font-size:18px;margin:0 0 8px}
        .muted{color:#666;margin-bottom:16px}
        table{width:100%;border-collapse:collapse}
        td{padding:8px;border-bottom:1px solid #e5e7eb}
        td:first-child{font-weight:bold;width:52%}
        .total{font-size:14px;font-weight:bold}
    </style></head><body>
    <h1>Recibo / Extrato de Estadia</h1>
    <div class="muted">' . $brand . ' · ' . date('d/m/Y H:i') . '</div>
    <table>
      <tr><td>Reserva</td><td>#' . htmlspecialchars($receiptNo, ENT_QUOTES, 'UTF-8') . '</td></tr>
      <tr><td>Hóspede</td><td>' . $guestName . '</td></tr>
      <tr><td>Hospedagem</td><td>' . $fmt($totalDiarias) . '</td></tr>
      <tr><td>Consumo/Extras</td><td>' . $fmt($consumoExtra) . '</td></tr>
      <tr><td class="total">Total Geral</td><td class="total">' . $fmt($totalFinal) . '</td></tr>
    </table>
    </body></html>';
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $pdfBytes = $dompdf->output();
    if (!is_string($pdfBytes) || $pdfBytes === '') {
        return ['ok' => false, 'error' => 'Falha ao gerar PDF do recibo.'];
    }
    return ['ok' => true, 'base64' => base64_encode($pdfBytes)];
}

function evo_build_dummy_pdf_base64(string $title, string $subtitle): array
{
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoloadPath)) {
        return ['ok' => false, 'error' => 'Dependências PHP não instaladas (vendor/autoload.php ausente).'];
    }
    require_once $autoloadPath;
    if (!class_exists(\Dompdf\Dompdf::class) || !class_exists(\Dompdf\Options::class)) {
        return ['ok' => false, 'error' => 'Dompdf indisponível para gerar PDF de teste.'];
    }
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>
        body{font-family:DejaVu Sans,sans-serif;margin:24px;color:#111}
        h1{margin:0 0 8px;font-size:20px}
        p{margin:6px 0;font-size:12px}
    </style></head><body>
    <h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>
    <p>' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</p>
    <p>Emitido em ' . date('d/m/Y H:i:s') . '</p>
    </body></html>';
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $bytes = $dompdf->output();
    if (!is_string($bytes) || $bytes === '') {
        return ['ok' => false, 'error' => 'Falha ao gerar PDF de teste.'];
    }
    return ['ok' => true, 'base64' => base64_encode($bytes)];
}

function evo_build_portal_url(string $token): string
{
    $scheme = 'http';
    if (
        (isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) === 'on')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
    ) {
        $scheme = 'https';
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api/evolution_service.php'))), '/');
    $rootPath = preg_replace('#/api$#', '', $scriptDir) ?? '';
    return $scheme . '://' . $host . $rootPath . '/checkin.php?id=' . urlencode($token);
}

function evo_message_for_event(PDO $pdo, array $reservation, string $event): string
{
    $brand = evo_brand_name($pdo);
    $name = (string) ($reservation['guest_name'] ?? 'Hóspede');
    $checkin = (string) ($reservation['checkin_date'] ?? '');
    $checkout = (string) ($reservation['checkout_date'] ?? '');
    $fmt = static function (string $d): string {
        if ($d === '') return '';
        $parts = explode('-', $d);
        return count($parts) === 3 ? ($parts[2] . '/' . $parts[1] . '/' . $parts[0]) : $d;
    };
    $checkinBr = $fmt($checkin);
    $checkoutBr = $fmt($checkout);

    if ($event === 'reserva') {
        return "Olá, {$name}! Sua reserva na {$brand} foi recebida com sucesso.\n\nCheck-in: {$checkinBr}\nCheck-out: {$checkoutBr}\n\nObrigado por escolher {$brand}.";
    }
    if ($event === 'checkin') {
        $token = trim((string) ($reservation['fnrh_access_token'] ?? ''));
        $link = $token !== '' ? evo_build_portal_url($token) : '';
        $portal = $link !== '' ? "\nAcompanhe sua conta e consumo em tempo real:\n{$link}" : '';
        return "Check-in efetivado na {$brand}, {$name}! ✅\n\nDesejamos uma excelente estadia.{$portal}";
    }
    if ($event === 'checkout') {
        return "Obrigado pela estadia na {$brand}, {$name}! 🙌\n\nSeu check-out foi finalizado. Esperamos recebê-lo(a) novamente em breve.";
    }
    return '';
}

function evo_message_for_recipient(PDO $pdo, array $reservation, string $event, string $recipient): string
{
    $brand = evo_brand_name($pdo);
    $name = trim((string) ($reservation['guest_name'] ?? 'Hóspede'));
    $chaletName = trim((string) ($reservation['chalet_name'] ?? ''));
    $totalAmount = isset($reservation['total_amount']) ? (float) $reservation['total_amount'] : 0.0;
    $totalText = $totalAmount > 0 ? evo_fmt_money($totalAmount) : '';
    $checkin = trim((string) ($reservation['checkin_date'] ?? ''));
    $checkout = trim((string) ($reservation['checkout_date'] ?? ''));
    $fmt = static function (string $d): string {
        if ($d === '') return '';
        $parts = explode('-', $d);
        return count($parts) === 3 ? ($parts[2] . '/' . $parts[1] . '/' . $parts[0]) : $d;
    };
    $checkinBr = $fmt($checkin);
    $checkoutBr = $fmt($checkout);

    if ($event === 'reserva_pendente') {
        $manualTemplate = trim(evo_setting($pdo, 'manual_pix_instructions', ''));
        if ($manualTemplate === '') {
            $manualTemplate = "Olá, {nome}! Recebemos sua pré-reserva em {pousada}.\n"
                . "Check-in: {checkin}\nCheck-out: {checkout}\nTotal da reserva: {total}\n\n"
                . "Envie o comprovante para concluir a confirmação.";
        }
        $replacements = [
            '{nome}' => $name,
            '{pousada}' => $brand,
            '{checkin}' => $checkinBr,
            '{checkout}' => $checkoutBr,
            '{total}' => $totalText,
            '{id}' => (string)($reservation['id'] ?? ''),
            '{chale}' => $chaletName,
            '{chalet}' => $chaletName,
        ];
        $msg = strtr($manualTemplate, $replacements);
        if ($recipient === 'owner') {
            $msg .= "\n\n🔔 Reserva pendente de pagamento manual registrada no sistema.";
        }
        return $msg;
    }

    if ($event === 'reserva') {
        if ($recipient === 'owner') {
            return "🔔 *NOVA RESERVA RECEBIDA*\n\n"
                . "Hóspede: {$name}\n"
                . ($chaletName !== '' ? "Chalé: {$chaletName}\n" : '')
                . ($checkinBr !== '' ? "Check-in: {$checkinBr}\n" : '')
                . ($checkoutBr !== '' ? "Check-out: {$checkoutBr}\n" : '')
                . ($totalText !== '' ? "Valor: {$totalText}\n" : '')
                . "Sistema: {$brand}";
        }
        return "Olá, {$name}! Sua reserva na {$brand} foi recebida com sucesso.\n\n"
            . ($chaletName !== '' ? "Chalé: {$chaletName}\n" : '')
            . ($checkinBr !== '' ? "Check-in: {$checkinBr}\n" : '')
            . ($checkoutBr !== '' ? "Check-out: {$checkoutBr}\n" : '')
            . ($totalText !== '' ? "Valor da reserva: {$totalText}\n" : '')
            . "\nObrigado por escolher {$brand}.";
    }

    if ($event === 'payment_confirmed') {
        if ($recipient === 'owner') {
            return "✅ *PAGAMENTO APROVADO*\n\n"
                . "Hóspede: {$name}\n"
                . ($chaletName !== '' ? "Chalé: {$chaletName}\n" : '')
                . ($totalText !== '' ? "Total: {$totalText}\n" : '')
                . "Sistema: {$brand}";
        }
        return "Pagamento confirmado com sucesso, {$name}! ✅\n\n"
            . ($chaletName !== '' ? "Reserva: {$chaletName}\n" : '')
            . ($totalText !== '' ? "Total da reserva: {$totalText}\n" : '')
            . "Em breve você receberá as próximas orientações da {$brand}.";
    }

    if ($event === 'balance_paid') {
        if ($recipient === 'owner') {
            return "💵 *SALDO REGISTRADO COMO PAGO*\n\n"
                . "Hóspede: {$name}\n"
                . ($chaletName !== '' ? "Chalé: {$chaletName}\n" : '')
                . ($checkinBr !== '' ? "Check-in: {$checkinBr}\n" : '')
                . ($checkoutBr !== '' ? "Check-out: {$checkoutBr}\n" : '')
                . "Sistema: {$brand}";
        }
        return "Saldo da sua reserva registrado com sucesso, {$name}. ✅\n\nObrigado por concluir os pagamentos da estadia.";
    }

    if ($event === 'checkout') {
        $stayTotal = isset($reservation['stay_total']) ? (float)$reservation['stay_total'] : 0.0;
        $consumptionTotal = isset($reservation['consumption_total']) ? (float)$reservation['consumption_total'] : 0.0;
        $grandTotal = isset($reservation['grand_total']) ? (float)$reservation['grand_total'] : ($stayTotal + $consumptionTotal);
        $template = "Olá, {{guest_name}}! Sua estadia em {{company_name}} foi finalizada com sucesso. 🏨\n\n"
            . "📝 Resumo do Fechamento:\n"
            . "🏠 Hospedagem: {{stay_total}}\n"
            . "🥤 Consumo/Extras: {{consumption_total}}\n"
            . "💰 Total Geral: {{grand_total}}\n\n"
            . "Agradecemos a preferência e esperamos te ver em breve!";
        return strtr($template, [
            '{{company_name}}' => $brand,
            '{{guest_name}}' => $name,
            '{{stay_total}}' => evo_fmt_money($stayTotal),
            '{{consumption_total}}' => evo_fmt_money($consumptionTotal),
            '{{grand_total}}' => evo_fmt_money($grandTotal),
        ]);
    }

    return evo_message_for_event($pdo, $reservation, $event);
}

function evo_notify_event(PDO $pdo, array $reservation, string $event): array
{
    $toggleKey = in_array($event, ['reserva', 'reserva_pendente'], true)
        ? 'evo_notify_reserva'
        : ($event === 'checkin' ? 'evo_notify_checkin' : 'evo_notify_checkout');
    if (in_array($event, ['reserva', 'reserva_pendente', 'checkin', 'checkout'], true) && !evo_flag($pdo, $toggleKey, true)) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'toggle_off'];
    }

    $targets = [];
    $guestPhone = evo_phone_normalize((string) ($reservation['guest_phone'] ?? ''));
    if ($guestPhone !== '') {
        $targets[] = ['recipient' => 'guest', 'number' => $guestPhone];
    }

    if (in_array($event, ['reserva', 'reserva_pendente', 'payment_confirmed', 'balance_paid'], true)) {
        $ownerPhone = evo_phone_normalize(evo_setting($pdo, 'owner_whatsapp', ''));
        if ($ownerPhone !== '') {
            $targets[] = ['recipient' => 'owner', 'number' => $ownerPhone];
        }
    }

    if (count($targets) === 0) {
        return ['ok' => false, 'error' => 'Nenhum destinatário válido para envio.'];
    }

    $results = [];
    $overallOk = true;
    foreach ($targets as $target) {
        $text = evo_message_for_recipient($pdo, $reservation, $event, $target['recipient']);
        if (trim($text) === '') {
            $results[] = [
                'recipient' => $target['recipient'],
                'ok' => false,
                'error' => 'Mensagem vazia para o evento.'
            ];
            $overallOk = false;
            continue;
        }
        $sendResult = evo_send_text($pdo, $target['number'], $text);
        $results[] = ['recipient' => $target['recipient']] + $sendResult;
        if (empty($sendResult['ok'])) {
            $overallOk = false;
        }
    }

    return ['ok' => $overallOk, 'results' => $results];
}

// Endpoint opcional para disparo manual (admin-only)
if (PHP_SAPI !== 'cli' && basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    be_require_internal_key($pdo);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        jsonResponse(['error' => 'Método não permitido'], 405);
    }
    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) jsonResponse(['error' => 'Payload inválido'], 400);
    $action = strtolower(trim((string) ($data['action'] ?? 'send_text')));
    if ($action === 'notify_event') {
        $event = strtolower(trim((string) ($data['event'] ?? 'reserva')));
        $reservation = $data['reservation'] ?? [];
        if (!is_array($reservation)) {
            jsonResponse(['error' => 'reservation inválida'], 400);
        }
        $r = evo_notify_event($pdo, $reservation, $event);
        jsonResponse($r, !empty($r['ok']) ? 200 : 400);
    }
    if ($action === 'test_notify' || $action === 'test_notification') {
        $targetPhone = trim((string)($data['phone'] ?? ''));
        $ownerPhone = evo_phone_normalize($targetPhone);
        if ($ownerPhone === '') {
            jsonResponse(['ok' => false, 'error' => 'O número de telefone (phone) é obrigatório para teste.'], 400);
        }
        $brand = evo_brand_name($pdo);
        $testMessage = "🚀 Teste de Sistema: A integração de {$brand} com a Evolution API está funcionando perfeitamente!";

        $ownerResult = evo_send_text($pdo, $ownerPhone, $testMessage);
        $ok = !empty($ownerResult['ok']);
        jsonResponse([
            'ok' => $ok,
            'results' => [
                'owner' => $ownerResult,
            ],
        ], $ok ? 200 : 400);
    }

    if ($action === 'test_contract_media' || $action === 'test_receipt_media') {
        $targetPhone = trim((string)($data['phone'] ?? ''));
        $number = evo_phone_normalize($targetPhone);
        if ($number === '') {
            jsonResponse(['ok' => false, 'error' => 'O número de telefone (phone) é obrigatório para teste de mídia.'], 400);
        }
        $brand = evo_brand_name($pdo);
        $kind = $action === 'test_contract_media' ? 'Contrato' : 'Recibo';
        $dummy = evo_build_dummy_pdf_base64(
            "Teste de {$kind} - {$brand}",
            "Documento de teste da integração Evolution API ({$kind})."
        );
        if (empty($dummy['ok'])) {
            jsonResponse(['ok' => false, 'error' => (string)($dummy['error'] ?? 'Falha ao gerar PDF de teste')], 400);
        }
        $fileName = strtolower($action === 'test_contract_media' ? 'teste_contrato.pdf' : 'teste_recibo.pdf');
        $caption = "Teste de envio de {$kind} em PDF via WhatsApp.";
        $r = evo_send_media($pdo, $number, (string)$dummy['base64'], $fileName, 'application/pdf', $caption);
        jsonResponse($r, !empty($r['ok']) ? 200 : 400);
    }

    if ($action === 'resend_contract_media') {
        $reservationId = (int)($data['reservation_id'] ?? 0);
        if ($reservationId <= 0) {
            jsonResponse(['ok' => false, 'error' => 'reservation_id é obrigatório.'], 400);
        }
        $stmt = $pdo->prepare('SELECT id, guest_name, guest_phone, contract_filename FROM reservations WHERE id = ? LIMIT 1');
        $stmt->execute([$reservationId]);
        $resv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$resv) {
            jsonResponse(['ok' => false, 'error' => 'Reserva não encontrada.'], 404);
        }
        $guestPhone = evo_phone_normalize((string)($resv['guest_phone'] ?? ''));
        if ($guestPhone === '') {
            jsonResponse(['ok' => false, 'error' => 'Telefone do hóspede não informado na reserva.'], 400);
        }
        $contractFile = trim((string)($resv['contract_filename'] ?? ''));
        $contractPath = '';
        if ($contractFile !== '') {
            $contractsDir = realpath(__DIR__ . '/../storage/contracts');
            if ($contractsDir) {
                $candidate = $contractsDir . DIRECTORY_SEPARATOR . basename($contractFile);
                if (is_file($candidate)) {
                    $contractPath = $candidate;
                }
            }
        }
        if ($contractPath === '') {
            $gen = generateContractForReservation($pdo, $reservationId);
            $contractPath = (string)($gen['path'] ?? '');
            $contractFile = (string)($gen['filename'] ?? ('contrato_reserva_' . $reservationId . '.pdf'));
        }
        if (!is_file($contractPath)) {
            jsonResponse(['ok' => false, 'error' => 'PDF do contrato não encontrado para reenvio.'], 400);
        }
        $bytes = @file_get_contents($contractPath);
        if (!is_string($bytes) || $bytes === '') {
            jsonResponse(['ok' => false, 'error' => 'Falha ao ler PDF do contrato para reenvio.'], 400);
        }
        $guestName = trim((string)($resv['guest_name'] ?? 'Hóspede'));
        $caption = "Olá, {$guestName}! Segue o contrato da sua reserva em anexo.";
        $r = evo_send_media($pdo, $guestPhone, base64_encode($bytes), $contractFile, 'application/pdf', $caption);
        if (!empty($r['ok'])) {
            $upd = $pdo->prepare('UPDATE reservations SET last_contract_sent_at = NOW() WHERE id = ?');
            $upd->execute([$reservationId]);
            $r['last_contract_sent_at'] = date('Y-m-d H:i:s');
        }
        jsonResponse($r, !empty($r['ok']) ? 200 : 400);
    }

    $number = evo_phone_normalize((string) ($data['number'] ?? ''));
    if ($number === '') jsonResponse(['error' => 'number é obrigatório'], 400);
    $text = trim((string) ($data['text'] ?? ''));

    if ($action === 'folio_receipt') {
        $brand = evo_brand_name($pdo);
        $reservationId = (int) ($data['reservation_id'] ?? 0);
        $guestName = trim((string) ($data['guest_name'] ?? 'Hóspede'));
        $totalDiarias = (float) ($data['total_diarias'] ?? 0);
        $consumoExtra = (float) ($data['consumo_extra'] ?? 0);
        $totalFinal = (float) ($data['total_final'] ?? 0);
        $pdf = evo_build_folio_receipt_pdf_base64([
            'brand' => $brand,
            'reservation_id' => $reservationId,
            'guest_name' => $guestName,
            'total_diarias' => $totalDiarias,
            'consumo_extra' => $consumoExtra,
            'total_final' => $totalFinal,
        ]);
        if (empty($pdf['ok'])) {
            jsonResponse(['ok' => false, 'error' => (string)($pdf['error'] ?? 'Falha ao montar recibo PDF')], 400);
        }
        $fileName = 'recibo_reserva_' . ($reservationId > 0 ? $reservationId : 'sem_id') . '.pdf';
        $caption = 'Segue o recibo/extrato da sua estadia em ' . $brand . '.';
        $r = evo_send_media($pdo, $number, (string)$pdf['base64'], $fileName, 'application/pdf', $caption);
        jsonResponse($r, !empty($r['ok']) ? 200 : 400);
    }

    if ($text === '') jsonResponse(['error' => 'text é obrigatório'], 400);
    $r = evo_send_text($pdo, $number, $text);
    jsonResponse($r, $r['ok'] ? 200 : 400);
}

