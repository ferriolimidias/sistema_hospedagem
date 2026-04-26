<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking_extras.php';

be_require_internal_key($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Método não permitido'], 405);
}

/**
 * @return array<string,mixed>
 */
function evoi_json_body(): array
{
    $raw = (string) file_get_contents('php://input');
    if (trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function evoi_setting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return is_string($value) ? $value : $default;
}

function evoi_save_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}

function evoi_instance_name(): string
{
    return 'p_' . substr(hash('sha256', uniqid((string) mt_rand(), true)), 0, 18);
}

/**
 * @return array{ok:bool,http_code:int,body:string,error:string}
 */
function evoi_call(string $method, string $endpoint, string $apikey, ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'cURL indisponível'];
    }
    $ch = curl_init();
    if ($ch === false) {
        return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'Falha ao iniciar cURL'];
    }
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . $apikey,
    ];
    $opts = [
        CURLOPT_URL => $endpoint,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ok = ($error === '' && $httpCode >= 200 && $httpCode < 300);
    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'body' => is_string($body) ? $body : '',
        'error' => $error,
    ];
}

function evoi_extract_token(array $decoded): string
{
    $candidates = [
        $decoded['token'] ?? null,
        $decoded['apikey'] ?? null,
        $decoded['apiKey'] ?? null,
        $decoded['instance']['token'] ?? null,
        $decoded['instance']['apikey'] ?? null,
        $decoded['instance']['apiKey'] ?? null,
        $decoded['data']['token'] ?? null,
        $decoded['data']['apikey'] ?? null,
        $decoded['data']['apiKey'] ?? null,
    ];
    foreach ($candidates as $item) {
        if (is_string($item) && trim($item) !== '') {
            return trim($item);
        }
    }
    return '';
}

function evoi_extract_qr_base64(array $decoded): string
{
    $candidates = [
        $decoded['qrcode'] ?? null,
        $decoded['qrCode'] ?? null,
        $decoded['base64'] ?? null,
        $decoded['qrcode']['base64'] ?? null,
        $decoded['qrcode']['code'] ?? null,
        $decoded['data']['qrcode'] ?? null,
        $decoded['data']['base64'] ?? null,
        $decoded['data']['qrcode']['base64'] ?? null,
        $decoded['data']['qrcode']['code'] ?? null,
    ];
    foreach ($candidates as $item) {
        if (is_string($item) && trim($item) !== '') {
            return trim($item);
        }
    }
    return '';
}

function evoi_extract_status(array $decoded): string
{
    $candidates = [
        $decoded['state'] ?? null,
        $decoded['status'] ?? null,
        $decoded['instance']['state'] ?? null,
        $decoded['instance']['status'] ?? null,
        $decoded['data']['state'] ?? null,
        $decoded['data']['status'] ?? null,
        $decoded['data']['instance']['state'] ?? null,
    ];
    foreach ($candidates as $item) {
        if (is_string($item) && trim($item) !== '') {
            return strtolower(trim($item));
        }
    }
    return 'close';
}

try {
    $global = be_evolution_global_config();
    if (empty($global['enabled']) || trim((string) ($global['url'] ?? '')) === '' || trim((string) ($global['key'] ?? '')) === '') {
        jsonResponse([
            'ok' => false,
            'error' => 'Configuração global da Evolution não encontrada no .env (EVOLUTION_GLOBAL_URL/EVOLUTION_GLOBAL_KEY).'
        ], 412);
    }

    $baseUrl = rtrim((string) $global['url'], '/');
    $globalKey = (string) $global['key'];
    $body = evoi_json_body();
    $action = strtolower(trim((string) ($body['action'] ?? 'check_status')));

    if ($action === 'get_qr') {
        $instance = trim(evoi_setting($pdo, 'evo_instance', ''));
        $token = trim(evoi_setting($pdo, 'evo_apikey', ''));

        if ($instance === '') {
            $instance = evoi_instance_name();
            $createPayload = [
                'instanceName' => $instance,
                'token' => $token !== '' ? $token : substr(hash('sha1', $instance . microtime(true)), 0, 32),
                'qrcode' => true,
            ];
            $createResp = evoi_call('POST', $baseUrl . '/instance/create', $globalKey, $createPayload);
            if (!$createResp['ok']) {
                jsonResponse(['ok' => false, 'error' => 'Falha ao criar instância na Evolution', 'details' => $createResp], 502);
            }
            $createDecoded = json_decode($createResp['body'], true);
            if (!is_array($createDecoded)) {
                $createDecoded = [];
            }
            $apiToken = evoi_extract_token($createDecoded);
            if ($apiToken !== '') {
                $token = $apiToken;
            }
            evoi_save_setting($pdo, 'evo_instance', $instance);
            if ($token !== '') {
                evoi_save_setting($pdo, 'evo_apikey', $token);
            }
        }

        $connectResp = evoi_call(
            'GET',
            $baseUrl . '/instance/connect/' . rawurlencode($instance),
            $globalKey
        );
        if (!$connectResp['ok']) {
            $connectResp = evoi_call(
                'POST',
                $baseUrl . '/instance/connect/' . rawurlencode($instance),
                $globalKey,
                ['instanceName' => $instance]
            );
        }
        if (!$connectResp['ok']) {
            jsonResponse(['ok' => false, 'error' => 'Falha ao obter QR Code da instância', 'details' => $connectResp], 502);
        }

        $connectDecoded = json_decode($connectResp['body'], true);
        if (!is_array($connectDecoded)) {
            $connectDecoded = [];
        }
        $qrBase64 = evoi_extract_qr_base64($connectDecoded);
        $status = evoi_extract_status($connectDecoded);
        jsonResponse([
            'ok' => true,
            'instance' => $instance,
            'status' => $status,
            'qr_base64' => $qrBase64,
            'raw' => $connectDecoded,
        ]);
    }

    if ($action === 'check_status') {
        $instance = trim(evoi_setting($pdo, 'evo_instance', ''));
        if ($instance === '') {
            jsonResponse(['ok' => true, 'instance' => '', 'status' => 'close']);
        }
        $stateResp = evoi_call('GET', $baseUrl . '/instance/connectionState/' . rawurlencode($instance), $globalKey);
        if (!$stateResp['ok']) {
            jsonResponse(['ok' => false, 'error' => 'Falha ao consultar status da instância', 'details' => $stateResp], 502);
        }
        $decoded = json_decode($stateResp['body'], true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        jsonResponse([
            'ok' => true,
            'instance' => $instance,
            'status' => evoi_extract_status($decoded),
            'raw' => $decoded,
        ]);
    }

    if ($action === 'disconnect') {
        $instance = trim(evoi_setting($pdo, 'evo_instance', ''));
        if ($instance !== '') {
            $logoutResp = evoi_call('DELETE', $baseUrl . '/instance/logout/' . rawurlencode($instance), $globalKey);
            if (!$logoutResp['ok']) {
                // Não bloqueia limpeza local em caso de falha remota.
                error_log('[evolution_instance] Falha no logout remoto: ' . json_encode($logoutResp));
            }
        }
        evoi_save_setting($pdo, 'evo_instance', '');
        evoi_save_setting($pdo, 'evo_apikey', '');
        jsonResponse(['ok' => true, 'status' => 'close']);
    }

    jsonResponse(['ok' => false, 'error' => 'Ação inválida'], 400);
} catch (Throwable $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
}

