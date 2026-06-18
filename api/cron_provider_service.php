<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/whatsapp_campaign_lib.php';

function cron_provider_is_configured(PDO $pdo): bool
{
    return wc_setting($pdo, 'cron_provider', 'cron-job.org') === 'cron-job.org'
        && trim(wc_setting($pdo, 'cron_provider_api_key')) !== '';
}

function cronjob_org_payload(string $workerUrl, bool $enabled): array
{
    return [
        'job' => [
            'title' => 'Campanhas WhatsApp - Sistema Hospedagem',
            'url' => $workerUrl,
            'enabled' => $enabled,
            'saveResponses' => true,
            'requestMethod' => 0,
            'schedule' => [
                'timezone' => 'America/Sao_Paulo',
                'expiresAt' => 0,
                'hours' => [-1],
                'mdays' => [-1],
                'minutes' => [-1],
                'months' => [-1],
                'wdays' => [-1],
            ],
        ],
    ];
}

function cronjob_org_request(PDO $pdo, string $method, string $path, ?array $payload = null, bool $dryRun = false): array
{
    $apiKey = trim(wc_setting($pdo, 'cron_provider_api_key'));
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'API key do cron-job.org nao configurada.'];
    }
    $endpoint = 'https://api.cron-job.org' . $path;
    if ($dryRun) {
        return ['ok' => true, 'dry_run' => true, 'method' => $method, 'endpoint' => $endpoint, 'payload' => $payload];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'Extensao cURL indisponivel no PHP.'];
    }
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = is_string($body) ? json_decode($body, true) : null;
    $ok = $curlError === '' && $code >= 200 && $code < 300;
    return [
        'ok' => $ok,
        'http_code' => $code,
        'error' => $ok ? '' : ($curlError !== '' ? $curlError : 'cron-job.org retornou HTTP ' . $code),
        'body' => is_array($decoded) ? $decoded : (is_string($body) ? $body : ''),
    ];
}

function cronjob_org_create_or_update_job(PDO $pdo, string $workerUrl, bool $enabled = true, bool $dryRun = false): array
{
    $jobId = trim(wc_setting($pdo, 'cron_job_id'));
    $payload = cronjob_org_payload($workerUrl, $enabled);
    $result = $jobId !== ''
        ? cronjob_org_request($pdo, 'PATCH', '/jobs/' . rawurlencode($jobId), $payload, $dryRun)
        : cronjob_org_request($pdo, 'PUT', '/jobs', $payload, $dryRun);

    if (!empty($result['ok']) && !$dryRun) {
        $body = $result['body'] ?? [];
        $newJobId = '';
        if (is_array($body)) {
            $newJobId = (string) ($body['jobId'] ?? ($body['job']['jobId'] ?? ($body['id'] ?? '')));
        }
        if ($newJobId !== '') {
            wc_set_setting($pdo, 'cron_job_id', $newJobId);
            $jobId = $newJobId;
        }
        wc_set_setting($pdo, 'cron_external_enabled', $enabled ? '1' : '0');
        wc_set_setting($pdo, 'cron_last_status', 'Job sincronizado em ' . date('Y-m-d H:i:s'));
        wc_set_setting($pdo, 'cron_last_error', '');
    } elseif (empty($result['ok']) && !$dryRun) {
        wc_set_setting($pdo, 'cron_last_error', (string) ($result['error'] ?? 'Falha ao sincronizar job.'));
    }
    $result['job_id'] = $jobId;
    return $result;
}

function cronjob_org_disable_job(PDO $pdo, bool $dryRun = false): array
{
    $jobId = trim(wc_setting($pdo, 'cron_job_id'));
    if ($jobId === '') {
        wc_set_setting($pdo, 'cron_external_enabled', '0');
        return ['ok' => true, 'message' => 'Nenhum job externo cadastrado.'];
    }
    $workerUrl = wc_worker_url($pdo);
    $payload = cronjob_org_payload($workerUrl, false);
    $result = cronjob_org_request($pdo, 'PATCH', '/jobs/' . rawurlencode($jobId), $payload, $dryRun);
    if (!empty($result['ok']) && !$dryRun) {
        wc_set_setting($pdo, 'cron_external_enabled', '0');
        wc_set_setting($pdo, 'cron_last_status', 'Job desativado em ' . date('Y-m-d H:i:s'));
        wc_set_setting($pdo, 'cron_last_error', '');
    } elseif (empty($result['ok']) && !$dryRun) {
        wc_set_setting($pdo, 'cron_last_error', (string) ($result['error'] ?? 'Falha ao desativar job.'));
    }
    $result['job_id'] = $jobId;
    return $result;
}

if (basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'cron_provider_service.php') {
    be_require_admin_auth($pdo);
    wc_assert_tables($pdo);
    $raw = file_get_contents('php://input');
    $data = $raw ? json_decode($raw, true) : [];
    if (!is_array($data)) {
        $data = [];
    }
    $action = (string) ($_GET['action'] ?? ($data['action'] ?? 'status'));
    if ($action === 'create_or_update') {
        $result = cronjob_org_create_or_update_job($pdo, wc_worker_url($pdo), true, !empty($data['dry_run']));
        jsonResponse(['success' => !empty($result['ok']), 'result' => $result], !empty($result['ok']) ? 200 : 502);
    }
    if ($action === 'disable') {
        $result = cronjob_org_disable_job($pdo, !empty($data['dry_run']));
        jsonResponse(['success' => !empty($result['ok']), 'result' => $result], !empty($result['ok']) ? 200 : 502);
    }
    jsonResponse([
        'success' => true,
        'configured' => cron_provider_is_configured($pdo),
        'provider' => wc_setting($pdo, 'cron_provider', 'cron-job.org'),
        'job_id' => wc_setting($pdo, 'cron_job_id'),
        'api_key_masked' => wc_mask_secret(wc_setting($pdo, 'cron_provider_api_key')),
        'worker_url' => wc_worker_url($pdo),
    ]);
}
