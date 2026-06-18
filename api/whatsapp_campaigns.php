<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/whatsapp_campaign_lib.php';
require_once __DIR__ . '/cron_provider_service.php';

$admin = be_require_admin_auth($pdo);
wc_assert_tables($pdo);

function wc_request_data(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }
    $raw = file_get_contents('php://input');
    $data = $raw ? json_decode($raw, true) : [];
    return is_array($data) ? $data : [];
}

function wc_int_setting_value(array $data, string $key, int $min, int $max): ?string
{
    if (!array_key_exists($key, $data)) {
        return null;
    }
    return (string) max($min, min($max, (int) $data[$key]));
}

function wc_save_campaign_image(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonResponse(['error' => 'Falha no upload da imagem.'], 422);
    }
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        jsonResponse(['error' => 'Imagem acima de 5 MB.'], 422);
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $original = (string) ($file['name'] ?? 'campanha.jpg');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowedExt, true)) {
        jsonResponse(['error' => 'Formato de imagem nao permitido. Use JPG, PNG ou WEBP.'], 422);
    }
    $mime = function_exists('finfo_open') ? '' : (function_exists('mime_content_type') ? (string) mime_content_type($tmp) : '');
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string) finfo_file($finfo, $tmp) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
    }
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
    if ($mime !== '' && !in_array($mime, $allowedMime, true)) {
        jsonResponse(['error' => 'Conteudo enviado nao parece ser imagem valida.'], 422);
    }
    $dir = __DIR__ . '/../uploads/campaigns';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        jsonResponse(['error' => 'Nao foi possivel criar pasta de campanhas.'], 500);
    }
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "<FilesMatch \"\\.(php|phtml|phar)$\">\nRequire all denied\n</FilesMatch>\n");
    }
    $filename = 'campanha_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($tmp, $dest)) {
        jsonResponse(['error' => 'Nao foi possivel salvar a imagem.'], 500);
    }
    return ['path' => 'uploads/campaigns/' . $filename, 'url' => wc_uploaded_public_url('uploads/campaigns/' . $filename)];
}

$data = wc_request_data();
$action = (string) ($_GET['action'] ?? ($data['action'] ?? 'list'));

try {
    if ($action === 'upload_image') {
        $file = $_FILES['image'] ?? null;
        if (!is_array($file)) {
            jsonResponse(['error' => 'Envie o campo image.'], 422);
        }
        jsonResponse(['success' => true] + wc_save_campaign_image($file));
    }

    if ($action === 'settings_get' || $action === 'cron_config_get') {
        $settings = wc_all_settings($pdo);
        $settings['worker_key_masked'] = wc_mask_secret($settings['worker_key']);
        $settings['cron_provider_api_key_masked'] = wc_mask_secret($settings['cron_provider_api_key']);
        unset($settings['worker_key'], $settings['cron_provider_api_key']);
        jsonResponse([
            'success' => true,
            'settings' => $settings,
            'worker_url' => wc_worker_url($pdo),
            'migration_ok' => true,
        ]);
    }

    if ($action === 'settings_save' || $action === 'cron_config_save') {
        $allowedInts = [
            'min_delay_seconds' => [5, 3600],
            'max_delay_seconds' => [5, 7200],
            'pause_after_min' => [1, 1000],
            'pause_after_max' => [1, 1000],
            'pause_min_seconds' => [0, 86400],
            'pause_max_seconds' => [0, 86400],
            'max_per_worker_run' => [1, 10],
        ];
        foreach ($allowedInts as $key => $bounds) {
            $value = wc_int_setting_value($data, $key, $bounds[0], $bounds[1]);
            if ($value !== null) {
                wc_set_setting($pdo, $key, $value);
            }
        }
        if (array_key_exists('append_opt_out_text', $data)) {
            wc_set_setting($pdo, 'append_opt_out_text', !empty($data['append_opt_out_text']) ? '1' : '0');
        }
        if (array_key_exists('cron_external_enabled', $data)) {
            wc_set_setting($pdo, 'cron_external_enabled', !empty($data['cron_external_enabled']) ? '1' : '0');
        }
        if (array_key_exists('cron_provider_api_key', $data) && trim((string) $data['cron_provider_api_key']) !== '') {
            wc_set_setting($pdo, 'cron_provider_api_key', trim((string) $data['cron_provider_api_key']));
        }
        if (!empty($data['generate_worker_key'])) {
            wc_set_setting($pdo, 'worker_key', bin2hex(random_bytes(24)));
        }
        jsonResponse(['success' => true, 'worker_url' => wc_worker_url($pdo)]);
    }

    if ($action === 'cron_create_or_update') {
        $result = cronjob_org_create_or_update_job($pdo, wc_worker_url($pdo), true, !empty($data['dry_run']));
        jsonResponse(['success' => !empty($result['ok']), 'result' => $result], !empty($result['ok']) ? 200 : 502);
    }

    if ($action === 'cron_disable') {
        $result = cronjob_org_disable_job($pdo, !empty($data['dry_run']));
        jsonResponse(['success' => !empty($result['ok']), 'result' => $result], !empty($result['ok']) ? 200 : 502);
    }

    if ($action === 'preview_recipients') {
        $built = wc_build_recipients($pdo, (string) ($data['audience'] ?? 'all'));
        $sample = array_slice(array_map(static function (array $r): array {
            return ['guest_name' => $r['guest_name'], 'phone_masked' => wc_mask_phone($r['normalized_phone'])];
        }, $built['recipients']), 0, 5);
        jsonResponse(['success' => true, 'count' => count($built['recipients']), 'skipped' => $built['skipped'], 'sample' => $sample]);
    }

    if ($action === 'create' || $action === 'start_campaign') {
        $campaignId = (int) ($data['campaign_id'] ?? 0);
        if ($campaignId <= 0) {
            $campaignId = wc_create_campaign($pdo, $data, (int) ($admin['id'] ?? 0));
        }
        if ($action === 'create') {
            jsonResponse(['success' => true, 'campaign_id' => $campaignId]);
        }
        $started = wc_start_campaign($pdo, $campaignId, (string) ($data['audience'] ?? 'all'));
        jsonResponse(['success' => true] + $started);
    }

    if ($action === 'pause' || $action === 'resume' || $action === 'cancel') {
        $campaignId = (int) ($data['campaign_id'] ?? 0);
        if ($campaignId <= 0) {
            jsonResponse(['error' => 'Campanha invalida.'], 422);
        }
        if ($action === 'pause') {
            $pdo->prepare("UPDATE whatsapp_campaigns SET status = 'paused', paused_at = NOW(), updated_at = NOW() WHERE id = ? AND status = 'sending'")->execute([$campaignId]);
        } elseif ($action === 'resume') {
            $pdo->prepare("UPDATE whatsapp_campaigns SET status = 'sending', paused_at = NULL, updated_at = NOW() WHERE id = ? AND status = 'paused'")->execute([$campaignId]);
        } else {
            $pdo->prepare("UPDATE whatsapp_campaigns SET status = 'cancelled', cancelled_at = NOW(), updated_at = NOW() WHERE id = ? AND status IN ('draft','sending','paused')")->execute([$campaignId]);
            $pdo->prepare("UPDATE whatsapp_campaign_recipients SET status = 'skipped', last_error = 'Campanha cancelada', updated_at = NOW() WHERE campaign_id = ? AND status = 'pending'")->execute([$campaignId]);
        }
        wc_refresh_counts($pdo, $campaignId);
        jsonResponse(['success' => true]);
    }

    if ($action === 'dry_run' || $action === 'process_next') {
        jsonResponse(['success' => true, 'result' => wc_process_next($pdo, $action === 'dry_run', (int) ($data['campaign_id'] ?? 0) ?: null)]);
    }

    if ($action === 'add_opt_out' || $action === 'remove_opt_out') {
        $phone = wc_normalize_phone((string) ($data['phone'] ?? ''));
        if (!wc_valid_phone($phone)) {
            jsonResponse(['error' => 'Telefone invalido.'], 422);
        }
        if ($action === 'add_opt_out') {
            $stmt = $pdo->prepare('INSERT INTO whatsapp_opt_outs (normalized_phone, reason) VALUES (?, ?) ON DUPLICATE KEY UPDATE reason = VALUES(reason), updated_at = NOW()');
            $stmt->execute([$phone, substr((string) ($data['reason'] ?? 'manual'), 0, 255)]);
        } else {
            $pdo->prepare('DELETE FROM whatsapp_opt_outs WHERE normalized_phone = ?')->execute([$phone]);
        }
        jsonResponse(['success' => true]);
    }

    if ($action === 'get') {
        jsonResponse(['success' => true] + wc_campaign_details($pdo, (int) ($data['campaign_id'] ?? $_GET['campaign_id'] ?? 0)));
    }

    $rows = $pdo->query('SELECT id, title, status, total_recipients, pending_count, sent_count, failed_count, skipped_count, started_at, completed_at, created_at FROM whatsapp_campaigns ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['success' => true, 'campaigns' => $rows]);
} catch (Throwable $e) {
    jsonResponse(['error' => 'Erro nas campanhas WhatsApp.', 'detail' => $e->getMessage()], 500);
}
