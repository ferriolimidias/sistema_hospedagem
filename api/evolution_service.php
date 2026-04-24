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
    $url = rtrim(trim(evo_setting($pdo, 'evo_url', '')), '/');
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

function evo_notify_event(PDO $pdo, array $reservation, string $event): array
{
    $toggleKey = $event === 'reserva'
        ? 'evo_notify_reserva'
        : ($event === 'checkin' ? 'evo_notify_checkin' : 'evo_notify_checkout');
    if (!evo_flag($pdo, $toggleKey, true)) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'toggle_off'];
    }
    $phone = evo_phone_normalize((string) ($reservation['guest_phone'] ?? ''));
    if ($phone === '') {
        return ['ok' => false, 'error' => 'Telefone do hóspede ausente.'];
    }
    $text = evo_message_for_event($pdo, $reservation, $event);
    if (trim($text) === '') {
        return ['ok' => false, 'error' => 'Mensagem vazia para o evento.'];
    }
    return evo_send_text($pdo, $phone, $text);
}

// Endpoint opcional para disparo manual (admin-only)
if (PHP_SAPI !== 'cli' && basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    be_require_internal_key($pdo);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        jsonResponse(['error' => 'Método não permitido'], 405);
    }
    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) jsonResponse(['error' => 'Payload inválido'], 400);
    $number = evo_phone_normalize((string) ($data['number'] ?? ''));
    if ($number === '') jsonResponse(['error' => 'number é obrigatório'], 400);
    $action = strtolower(trim((string) ($data['action'] ?? 'send_text')));
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

