<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

be_require_internal_key($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Metodo nao permitido'], 405);
}

function evoi_json_body(): array
{
    $raw = (string) file_get_contents('php://input');
    if (trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function evoi_setting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    if (!is_string($value)) return $default;
    $decoded = json_decode($value, true);
    return (json_last_error() === JSON_ERROR_NONE && is_string($decoded)) ? $decoded : $value;
}

function evoi_save_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}

function evoi_legacy_evolution_settings(PDO $pdo): array
{
    $raw = evoi_setting($pdo, 'evolutionSettings', '');
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function evoi_base_url_setting(PDO $pdo): string
{
    $value = trim(evoi_setting($pdo, 'evo_url', ''));
    if ($value !== '') return rtrim($value, '/');

    $legacy = evoi_legacy_evolution_settings($pdo);
    $legacyUrl = trim((string)($legacy['url'] ?? ''));
    if ($legacyUrl !== '') return rtrim($legacyUrl, '/');

    if (function_exists('be_env_value')) {
        foreach (['EVOLUTION_BASE_URL', 'EVO_BASE_URL', 'EVOLUTION_API_URL'] as $key) {
            $env = trim(be_env_value($key, ''));
            if ($env !== '') return rtrim($env, '/');
        }
    }
    return '';
}

function evoi_api_key_setting(PDO $pdo): string
{
    $value = trim(evoi_setting($pdo, 'evo_apikey', ''));
    if ($value !== '') return $value;

    $legacy = evoi_legacy_evolution_settings($pdo);
    foreach (['companyApikey', 'clientApikey', 'apikey', 'apiKey'] as $key) {
        $legacyKey = trim((string)($legacy[$key] ?? ''));
        if ($legacyKey !== '') return $legacyKey;
    }

    if (function_exists('be_env_value')) {
        foreach (['EVOLUTION_API_KEY', 'EVO_API_KEY', 'EVOLUTION_GLOBAL_KEY'] as $key) {
            $env = trim(be_env_value($key, ''));
            if ($env !== '') return $env;
        }
    }
    return '';
}

function evoi_instance_setting(PDO $pdo): string
{
    $value = trim(evoi_setting($pdo, 'evo_instance', ''));
    if ($value !== '') return $value;

    $legacy = evoi_legacy_evolution_settings($pdo);
    foreach (['companyInstance', 'clientInstance', 'instance'] as $key) {
        $legacyInstance = trim((string)($legacy[$key] ?? ''));
        if ($legacyInstance !== '') return $legacyInstance;
    }

    if (function_exists('be_env_value')) {
        foreach (['EVOLUTION_INSTANCE', 'EVO_INSTANCE'] as $key) {
            $env = trim(be_env_value($key, ''));
            if ($env !== '') return $env;
        }
    }
    return '';
}

function evoi_mask_secret(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    $len = strlen($value);
    if ($len <= 8) return str_repeat('*', $len);
    return substr($value, 0, 4) . str_repeat('*', max(4, $len - 8)) . substr($value, -4);
}

function evoi_slugify_instance(string $raw): string
{
    $value = trim(function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw));
    if (function_exists('iconv')) {
        $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($normalized) && $normalized !== '') $value = strtolower($normalized);
    }
    $value = preg_replace('/[^a-z0-9]+/i', '_', $value) ?? '';
    $value = preg_replace('/_+/', '_', $value) ?? '';
    $value = trim($value, '_-');
    return $value !== '' ? $value : 'pousada_sistema';
}

function evoi_normalize_instance_name(string $raw): string
{
    $value = evoi_slugify_instance($raw);
    $value = preg_replace('/[^a-z0-9_-]/i', '', $value) ?? '';
    return substr(trim($value, '_-'), 0, 80);
}

function evoi_unique_suffix(): string
{
    try {
        return substr(bin2hex(random_bytes(3)), 0, 6);
    } catch (Throwable $e) {
        return substr(hash('sha1', microtime(true) . mt_rand()), 0, 6);
    }
}

function evoi_setting_value(PDO $pdo, string $key): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return is_string($value) ? trim($value) : '';
}

function evoi_generate_instance_name(PDO $pdo): string
{
    $companyName = evoi_setting_value($pdo, 'company_name');
    $siteTitle = evoi_setting_value($pdo, 'site_title');
    $baseName = $companyName !== '' ? $companyName : ($siteTitle !== '' ? $siteTitle : 'pousada_sistema');
    return substr(evoi_normalize_instance_name($baseName), 0, 54) . '_' . evoi_unique_suffix();
}

function evoi_save_instance(PDO $pdo, string $instance): void
{
    evoi_save_setting($pdo, 'evolution_provider', 'evolution_api');
    evoi_save_setting($pdo, 'evo_instance', $instance);
}

function evoi_extract_qr(array $decoded): array
{
    $candidates = [
        $decoded['qrcode']['base64'] ?? null,
        $decoded['qrcode']['code'] ?? null,
        $decoded['qrCode']['base64'] ?? null,
        $decoded['qrCode']['code'] ?? null,
        $decoded['base64'] ?? null,
        $decoded['code'] ?? null,
        $decoded['pairingCode'] ?? null,
        $decoded['pairing_code'] ?? null,
        $decoded['data']['qrcode']['base64'] ?? null,
        $decoded['data']['qrcode']['code'] ?? null,
        $decoded['data']['base64'] ?? null,
        $decoded['data']['code'] ?? null,
        $decoded['data']['pairingCode'] ?? null,
        $decoded['instance']['qrcode']['base64'] ?? null,
    ];
    foreach ($candidates as $item) {
        if (is_string($item) && trim($item) !== '') {
            $value = trim($item);
            $looksImage = str_starts_with($value, 'data:image') || strlen($value) > 120;
            return [
                'qr_base64' => $looksImage ? $value : '',
                'qr_code' => $looksImage ? '' : $value,
            ];
        }
    }
    return ['qr_base64' => '', 'qr_code' => ''];
}

function evoi_extract_status(array $decoded): string
{
    $candidates = [
        $decoded['instance']['state'] ?? null,
        $decoded['instance']['status'] ?? null,
        $decoded['state'] ?? null,
        $decoded['status'] ?? null,
        $decoded['data']['instance']['state'] ?? null,
        $decoded['data']['state'] ?? null,
        $decoded['data']['status'] ?? null,
    ];
    foreach ($candidates as $item) {
        if (is_string($item) && trim($item) !== '') return strtolower(trim($item));
    }
    return 'close';
}

function evoi_call(string $method, string $endpoint, string $apikey, ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'cURL indisponivel'];
    }
    $ch = curl_init();
    if ($ch === false) {
        return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'Falha ao iniciar cURL'];
    }
    $opts = [
        CURLOPT_URL => $endpoint,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'apikey: ' . $apikey,
        ],
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
    if (!$ok) {
        $logBody = is_string($body) ? (function_exists('mb_substr') ? mb_substr($body, 0, 1200) : substr($body, 0, 1200)) : '';
        error_log('[evolution_instance] fail endpoint=' . $endpoint . ' http=' . $httpCode . ' err=' . $error . ' body=' . $logBody);
    }
    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'body' => is_string($body) ? $body : '',
        'error' => $error,
    ];
}

function evoi_remote_error(array $resp, string $fallback): string
{
    $http = (int)($resp['http_code'] ?? 0);
    $body = trim((string)($resp['body'] ?? ''));
    $curl = trim((string)($resp['error'] ?? ''));
    $remote = '';
    if ($body !== '') {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $candidate = $decoded['error'] ?? $decoded['message'] ?? ($decoded['response']['message'] ?? '');
            if (is_array($candidate)) {
                $flat = [];
                array_walk_recursive($candidate, static function ($v) use (&$flat) {
                    if (is_scalar($v)) $flat[] = (string)$v;
                });
                $remote = trim(implode('; ', $flat));
            } elseif (is_string($candidate)) {
                $remote = trim($candidate);
            }
        }
    }
    $parts = [$fallback, '(HTTP ' . $http . ')'];
    if ($curl !== '') $parts[] = 'cURL: ' . $curl;
    if ($remote !== '') $parts[] = 'Evolution: ' . $remote;
    return implode(' ', $parts);
}

function evoi_is_create_conflict(array $resp): bool
{
    $http = (int)($resp['http_code'] ?? 0);
    if (in_array($http, [403, 409], true)) return true;
    $body = strtolower((string)($resp['body'] ?? ''));
    foreach (['already exists', 'already in use', 'duplicate', 'ja existe', 'já existe', 'em uso'] as $needle) {
        if (strpos($body, $needle) !== false) return true;
    }
    return false;
}

try {
    $body = evoi_json_body();
    $action = strtolower(trim((string)($body['action'] ?? 'status')));

    if ($action === 'save_config') {
        $baseUrl = rtrim(trim((string)($body['base_url'] ?? ($body['url'] ?? ''))), '/');
        $apiKey = trim((string)($body['api_key'] ?? ($body['apikey'] ?? '')));
        $instance = evoi_normalize_instance_name((string)($body['instance'] ?? ''));
        if ($baseUrl !== '' && !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            jsonResponse(['ok' => false, 'error' => 'URL da Evolution API invalida'], 422);
        }
        if ($baseUrl !== '') evoi_save_setting($pdo, 'evo_url', $baseUrl);
        if ($apiKey !== '') evoi_save_setting($pdo, 'evo_apikey', $apiKey);
        if ($instance !== '') evoi_save_instance($pdo, $instance);
        jsonResponse([
            'ok' => true,
            'base_url' => evoi_base_url_setting($pdo),
            'instance' => evoi_instance_setting($pdo),
            'api_key_configured' => evoi_api_key_setting($pdo) !== '',
            'api_key_masked' => evoi_mask_secret(evoi_api_key_setting($pdo)),
            'status' => 'close',
        ]);
    }

    if ($action === 'get_config') {
        $key = evoi_api_key_setting($pdo);
        jsonResponse([
            'ok' => true,
            'base_url' => evoi_base_url_setting($pdo),
            'instance' => evoi_instance_setting($pdo),
            'api_key_configured' => $key !== '',
            'api_key_masked' => evoi_mask_secret($key),
            'status' => 'close',
        ]);
    }

    $baseUrl = evoi_base_url_setting($pdo);
    $apiKey = evoi_api_key_setting($pdo);
    if ($baseUrl === '' || $apiKey === '') {
        jsonResponse([
            'ok' => false,
            'error' => 'Configuracao Evolution API incompleta. Informe URL e API key no painel WhatsApp / Evolution API.'
        ], 412);
    }

    $host = (string)(parse_url($baseUrl, PHP_URL_HOST) ?? '');
    if ($host === '') {
        jsonResponse(['ok' => false, 'error' => 'URL da Evolution API invalida'], 422);
    }

    if (in_array($action, ['create', 'connect', 'get_qr', 'qrcode'], true)) {
        $instance = evoi_instance_setting($pdo);
        $createdNow = false;
        if ($instance === '') {
            $instance = evoi_generate_instance_name($pdo);
            $createResp = evoi_call('POST', $baseUrl . '/instance/create', $apiKey, [
                'instanceName' => $instance,
                'qrcode' => true,
                'integration' => 'WHATSAPP-BAILEYS',
            ]);
            if (!$createResp['ok'] && !evoi_is_create_conflict($createResp)) {
                jsonResponse(['ok' => false, 'error' => evoi_remote_error($createResp, 'A Evolution API recusou a criacao da instancia')], 400);
            }
            evoi_save_instance($pdo, $instance);
            $createdNow = true;
            $decodedCreate = json_decode((string)$createResp['body'], true);
            if (is_array($decodedCreate)) {
                $qr = evoi_extract_qr($decodedCreate);
                if (($qr['qr_base64'] ?? '') !== '' || ($qr['qr_code'] ?? '') !== '') {
                    jsonResponse([
                        'ok' => true,
                        'instance' => $instance,
                        'instance_generated' => true,
                        'status' => evoi_extract_status($decodedCreate),
                        'qr_base64' => $qr['qr_base64'],
                        'qr_code' => $qr['qr_code'],
                    ]);
                }
            }
        }

        if ($action === 'create') {
            jsonResponse([
                'ok' => true,
                'instance' => $instance,
                'instance_generated' => $createdNow,
                'status' => 'close',
                'api_key_configured' => true,
                'api_key_masked' => evoi_mask_secret($apiKey),
            ]);
        }

        $connectResp = evoi_call('GET', $baseUrl . '/instance/connect/' . rawurlencode($instance), $apiKey);
        if (!$connectResp['ok']) {
            jsonResponse(['ok' => false, 'error' => evoi_remote_error($connectResp, 'Falha na Evolution API ao obter QR')], 400);
        }
        $decoded = json_decode($connectResp['body'], true);
        if (!is_array($decoded)) $decoded = [];
        $qr = evoi_extract_qr($decoded);
        jsonResponse([
            'ok' => true,
            'instance' => $instance,
            'instance_generated' => $createdNow,
            'status' => evoi_extract_status($decoded),
            'qr_base64' => $qr['qr_base64'],
            'qr_code' => $qr['qr_code'],
        ]);
    }

    if (in_array($action, ['check_status', 'status'], true)) {
        $instance = evoi_instance_setting($pdo);
        if ($instance === '') {
            jsonResponse(['ok' => true, 'instance' => '', 'status' => 'close', 'needs_instance_creation' => true]);
        }
        $stateResp = evoi_call('GET', $baseUrl . '/instance/connectionState/' . rawurlencode($instance), $apiKey);
        if (!$stateResp['ok']) {
            jsonResponse(['ok' => false, 'error' => evoi_remote_error($stateResp, 'Falha na Evolution API ao consultar status')], 400);
        }
        $decoded = json_decode($stateResp['body'], true);
        if (!is_array($decoded)) $decoded = [];
        jsonResponse(['ok' => true, 'instance' => $instance, 'status' => evoi_extract_status($decoded)]);
    }

    if (in_array($action, ['disconnect', 'reset', 'delete'], true)) {
        $instance = evoi_instance_setting($pdo);
        if ($instance !== '') {
            $deleteResp = evoi_call('DELETE', $baseUrl . '/instance/delete/' . rawurlencode($instance), $apiKey);
            if (!$deleteResp['ok']) {
                error_log('[evolution_instance] Falha na exclusao remota: ' . json_encode($deleteResp));
            }
        }
        evoi_save_setting($pdo, 'evo_instance', '');

        if ($action === 'reset') {
            $newInstance = evoi_generate_instance_name($pdo);
            $createResp = evoi_call('POST', $baseUrl . '/instance/create', $apiKey, [
                'instanceName' => $newInstance,
                'qrcode' => true,
                'integration' => 'WHATSAPP-BAILEYS',
            ]);
            if (!$createResp['ok'] && !evoi_is_create_conflict($createResp)) {
                jsonResponse([
                    'ok' => false,
                    'instance' => '',
                    'status' => 'close',
                    'error' => evoi_remote_error($createResp, 'A conexao local foi resetada, mas a Evolution API recusou a nova instancia')
                ], 400);
            }
            evoi_save_instance($pdo, $newInstance);

            $decodedCreate = json_decode((string)$createResp['body'], true);
            if (is_array($decodedCreate)) {
                $qr = evoi_extract_qr($decodedCreate);
                if (($qr['qr_base64'] ?? '') !== '' || ($qr['qr_code'] ?? '') !== '') {
                    jsonResponse([
                        'ok' => true,
                        'instance' => $newInstance,
                        'instance_generated' => true,
                        'status' => evoi_extract_status($decodedCreate),
                        'qr_base64' => $qr['qr_base64'],
                        'qr_code' => $qr['qr_code'],
                    ]);
                }
            }

            $connectResp = evoi_call('GET', $baseUrl . '/instance/connect/' . rawurlencode($newInstance), $apiKey);
            if (!$connectResp['ok']) {
                jsonResponse([
                    'ok' => false,
                    'instance' => $newInstance,
                    'status' => 'close',
                    'error' => evoi_remote_error($connectResp, 'A nova instancia foi criada, mas a Evolution API nao retornou QR Code')
                ], 400);
            }
            $decoded = json_decode($connectResp['body'], true);
            if (!is_array($decoded)) $decoded = [];
            $qr = evoi_extract_qr($decoded);
            jsonResponse([
                'ok' => true,
                'instance' => $newInstance,
                'instance_generated' => true,
                'status' => evoi_extract_status($decoded),
                'qr_base64' => $qr['qr_base64'],
                'qr_code' => $qr['qr_code'],
            ]);
        }

        jsonResponse(['ok' => true, 'instance' => '', 'status' => 'close', 'needs_instance_creation' => true]);
    }

    jsonResponse(['ok' => false, 'error' => 'Acao invalida'], 400);
} catch (Throwable $e) {
    error_log('[evolution_instance] exception: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erro interno ao processar integracao Evolution API'], 500);
}
