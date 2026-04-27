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

function evoi_slugify_instance(string $raw): string
{
    $value = trim(function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw));
    if (function_exists('iconv')) {
        $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($normalized) && $normalized !== '') {
            $value = strtolower($normalized);
        }
    }
    $value = preg_replace('/[^a-z0-9]+/i', '_', $value) ?? '';
    $value = preg_replace('/_+/', '_', $value) ?? '';
    $value = trim($value, '_');
    if ($value === '') {
        $value = 'pousada_sistema';
    }
    return $value;
}

function evoi_setting_value(PDO $pdo, string $key): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return is_string($v) ? trim($v) : '';
}

function evoi_instance_name(PDO $pdo): string
{
    $companyName = evoi_setting_value($pdo, 'company_name');
    $siteTitle = evoi_setting_value($pdo, 'site_title');
    $baseName = $companyName !== '' ? $companyName : ($siteTitle !== '' ? $siteTitle : 'pousada_sistema');
    return evoi_slugify_instance($baseName);
}

function evoi_debug_enabled(): bool
{
    $q = strtolower(trim((string) ($_GET['debug'] ?? '')));
    if (in_array($q, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    $env = strtolower(trim((string) getenv('APP_DEBUG')));
    return in_array($env, ['1', 'true', 'yes', 'on'], true);
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
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
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
    $ok = ($error === '' && in_array($httpCode, [200, 201], true));
    if (!$ok) {
        $logBody = is_string($body) ? $body : '';
        if (function_exists('mb_substr')) {
            $logBody = mb_substr($logBody, 0, 1200);
        } else {
            $logBody = substr($logBody, 0, 1200);
        }
        error_log('[evolution_instance] HTTP/CURL falha endpoint=' . $endpoint
            . ' code=' . $httpCode
            . ' curl_error=' . $error
            . ' body=' . $logBody);
    }
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

function evoi_compose_error_message(string $prefix, array $resp): string
{
    $http = (int) ($resp['http_code'] ?? 0);
    $curlErr = trim((string) ($resp['error'] ?? ''));
    $body = trim((string) ($resp['body'] ?? ''));
    $remoteErr = '';
    if ($body !== '') {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $candidate = $decoded['error'] ?? $decoded['message'] ?? ($decoded['response']['message'] ?? '');
            if (is_string($candidate) && trim($candidate) !== '') {
                $remoteErr = trim($candidate);
            }
        }
    }
    $parts = [];
    $parts[] = $prefix;
    $parts[] = '(HTTP ' . $http . ')';
    if ($curlErr !== '') $parts[] = 'cURL: ' . $curlErr;
    if ($remoteErr !== '') $parts[] = 'Evolution: ' . $remoteErr;
    return implode(' ', $parts);
}

try {
    $global = be_evolution_global_config();
    if (empty($global['env_found'])) {
        jsonResponse([
            'ok' => false,
            'error' => '.env não encontrado na raiz do projeto'
        ], 412);
    }
    $debug = evoi_debug_enabled();
    $globalUrl = trim((string) ($global['url'] ?? ''));
    $globalKey = trim((string) ($global['key'] ?? ''));
    if ($globalUrl === '' || $globalKey === '') {
        $payload = [
            'ok' => false,
            'error' => 'Credenciais globais não configuradas no .env'
        ];
        if ($debug) {
            $payload['debug'] = [
                'url_loaded' => $globalUrl !== '',
                'key_loaded' => $globalKey !== '',
            ];
        }
        jsonResponse($payload, 412);
    }

    $baseUrl = rtrim($globalUrl, '/');
    $host = (string) (parse_url($baseUrl, PHP_URL_HOST) ?? '');
    if ($host === '') {
        jsonResponse([
            'ok' => false,
            'error' => 'URL da Evolution inválida no .env'
        ], 422);
    }
    $resolved = gethostbyname($host);
    if ($resolved === $host) {
        error_log('[evolution_instance] DNS não resolvido para host Evolution: ' . $host);
        jsonResponse([
            'ok' => false,
            'error' => 'Falha de DNS ao resolver host da Evolution API'
        ], 400);
    }

    $body = evoi_json_body();
    $action = strtolower(trim((string) ($body['action'] ?? 'check_status')));

    if ($action === 'get_qr') {
        $instance = trim(evoi_setting($pdo, 'evo_instance', ''));
        $token = trim(evoi_setting($pdo, 'evo_apikey', ''));

        if ($instance === '') {
            $instance = evoi_instance_name($pdo);
            if ($instance === '') {
                $instance = 'pousada_sistema';
            }
            error_log('Tentando criar instancia: ' . $instance);
            $createPayload = [
                'instanceName' => $instance,
                'token' => $token !== '' ? $token : substr(hash('sha1', $instance . microtime(true)), 0, 32),
                'qrcode' => true,
                'integration' => 'WHATSAPP-BAILEYS',
            ];
            $createResp = evoi_call('POST', $baseUrl . '/instance/create', $globalKey, $createPayload);
            if (!$createResp['ok']) {
                $payload = ['ok' => false, 'error' => 'A Evolution recusou a criação: ' . (string)($createResp['body'] ?? '')];
                jsonResponse($payload, 400);
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
            $payload = [
                'ok' => false,
                'error' => evoi_compose_error_message('Falha na Evolution ao obter QR', $connectResp)
            ];
            if ($debug) $payload['details'] = $connectResp;
            jsonResponse($payload, 400);
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
            $payload = [
                'ok' => false,
                'error' => evoi_compose_error_message('Falha na Evolution ao consultar status', $stateResp)
            ];
            if ($debug) $payload['details'] = $stateResp;
            jsonResponse($payload, 400);
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
            $deleteResp = evoi_call('DELETE', $baseUrl . '/instance/delete/' . rawurlencode($instance), $globalKey);
            if (!$deleteResp['ok']) {
                // Não bloqueia limpeza local em caso de falha remota.
                error_log('[evolution_instance] Falha na exclusão remota da instância: ' . json_encode($deleteResp));
            }
        }
        evoi_save_setting($pdo, 'evo_instance', '');
        evoi_save_setting($pdo, 'evo_apikey', '');
        jsonResponse(['ok' => true, 'status' => 'close']);
    }

    jsonResponse(['ok' => false, 'error' => 'Ação inválida'], 400);
} catch (Throwable $e) {
    error_log('[evolution_instance] exceção: ' . $e->getMessage());
    $payload = ['ok' => false, 'error' => 'Erro interno ao processar integração Evolution'];
    if (evoi_debug_enabled()) {
        $payload['details'] = $e->getMessage();
    }
    jsonResponse($payload, 500);
}

