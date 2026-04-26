<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking_extras.php';

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

function evo_send_text(PDO $pdo, string $number, string $text): array
{
    $global = function_exists('be_evolution_global_config')
        ? be_evolution_global_config()
        : ['enabled' => false, 'url' => '', 'key' => ''];
    $url = !empty($global['enabled'])
        ? rtrim(trim((string) ($global['url'] ?? '')), '/')
        : rtrim(trim(evo_setting($pdo, 'evo_url', '')), '/');
    $instance = trim(evo_setting($pdo, 'evo_instance', ''));
    $apikey = trim(evo_setting($pdo, 'evo_apikey', ''));
    if ($url === '' || $instance === '' || $apikey === '') {
        return ['ok' => false, 'error' => 'Evolution API não configurada (url/instance/apikey).'];
    }
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
    if (!$ok) {
        error_log('[evolution_service] fail http=' . $code . ' err=' . $err . ' body=' . (is_string($body) ? mb_substr($body, 0, 300) : ''));
    }
    return ['ok' => $ok, 'http_code' => $code, 'error' => $err, 'body' => is_string($body) ? $body : ''];
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
    if ($action === 'test_notify') {
        $ownerPhoneRaw = trim((string) ($data['number'] ?? evo_setting($pdo, 'owner_whatsapp', '')));
        $ownerPhone = evo_phone_normalize($ownerPhoneRaw);
        if ($ownerPhone === '') {
            jsonResponse(['ok' => false, 'error' => 'owner_whatsapp não configurado para teste.'], 400);
        }
        $fakePhone = evo_phone_normalize('5511999999999');
        $brand = evo_brand_name($pdo);
        $testMessage = "🚀 Teste de Sistema: A integração de {$brand} com a Evolution API está funcionando perfeitamente!";

        $ownerResult = evo_send_text($pdo, $ownerPhone, $testMessage);
        $fakeResult = evo_send_text($pdo, $fakePhone, $testMessage);

        $ok = !empty($ownerResult['ok']) || !empty($fakeResult['ok']);
        jsonResponse([
            'ok' => $ok,
            'results' => [
                'owner' => $ownerResult,
                'fake' => $fakeResult,
            ],
        ], $ok ? 200 : 400);
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
        $receiptNo = $reservationId > 0 ? ('#RES-' . str_pad((string) $reservationId, 3, '0', STR_PAD_LEFT)) : '#RES----';

        $text = "📄 *Extrato de Estadia - {$brand}*\n"
            . "Reserva: *{$receiptNo}*\n"
            . "👤 Hóspede: *{$guestName}*\n"
            . "🛏️ Diárias (Total): *" . evo_fmt_money($totalDiarias) . "*\n"
            . "🍔 Consumo Extra: *" . evo_fmt_money($consumoExtra) . "*\n"
            . "💰 *TOTAL A PAGAR:* *" . evo_fmt_money($totalFinal) . "*";
    }

    if ($text === '') jsonResponse(['error' => 'text é obrigatório'], 400);
    $r = evo_send_text($pdo, $number, $text);
    jsonResponse($r, $r['ok'] ? 200 : 400);
}

