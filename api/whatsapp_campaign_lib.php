<?php
declare(strict_types=1);

require_once __DIR__ . '/evolution_service.php';

function wc_required_tables(PDO $pdo): array
{
    $tables = ['whatsapp_campaigns', 'whatsapp_campaign_recipients', 'whatsapp_opt_outs', 'whatsapp_campaign_settings'];
    $missing = [];
    foreach ($tables as $table) {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        if (!$stmt->fetchColumn()) {
            $missing[] = $table;
        }
    }
    return $missing;
}

function wc_assert_tables(PDO $pdo): void
{
    $missing = wc_required_tables($pdo);
    if ($missing) {
        jsonResponse([
            'error' => 'Migração de campanhas WhatsApp pendente.',
            'missing_tables' => $missing,
            'migration' => 'docs_migracao/MIGRACAO_CAMPANHAS_WHATSAPP_2026_06_18.sql',
        ], 503);
    }
}

function wc_setting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM whatsapp_campaign_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return is_string($value) ? $value : $default;
}

function wc_set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO whatsapp_campaign_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
    );
    $stmt->execute([$key, $value]);
}

function wc_all_settings(PDO $pdo): array
{
    $rows = $pdo->query('SELECT setting_key, setting_value FROM whatsapp_campaign_settings')->fetchAll(PDO::FETCH_ASSOC);
    $settings = [];
    foreach ($rows as $row) {
        $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
    }
    $defaults = [
        'worker_key' => '',
        'cron_external_enabled' => '0',
        'cron_provider' => 'cron-job.org',
        'cron_provider_api_key' => '',
        'cron_job_id' => '',
        'cron_last_run_at' => '',
        'cron_last_status' => '',
        'cron_last_error' => '',
        'min_delay_seconds' => '20',
        'max_delay_seconds' => '30',
        'pause_after_min' => '8',
        'pause_after_max' => '12',
        'pause_min_seconds' => '120',
        'pause_max_seconds' => '300',
        'max_per_worker_run' => '1',
        'append_opt_out_text' => '1',
    ];
    return array_merge($defaults, $settings);
}

function wc_ensure_worker_key(PDO $pdo): string
{
    $key = wc_setting($pdo, 'worker_key');
    if ($key === '') {
        $key = bin2hex(random_bytes(24));
        wc_set_setting($pdo, 'worker_key', $key);
    }
    return $key;
}

function wc_mask_secret(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    return strlen($value) <= 8 ? str_repeat('*', strlen($value)) : substr($value, 0, 4) . str_repeat('*', max(4, strlen($value) - 8)) . substr($value, -4);
}

function wc_worker_url(PDO $pdo): string
{
    $origin = be_request_origin();
    $basePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api/whatsapp_campaigns.php'))), '/');
    if ($basePath === '' || $basePath === '.') {
        $basePath = '/api';
    }
    if (substr($basePath, -4) !== '/api') {
        $basePath .= '/api';
    }
    return $origin . $basePath . '/whatsapp_campaign_worker.php?key=' . rawurlencode(wc_ensure_worker_key($pdo));
}

function wc_normalize_phone(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) <= 11) {
        $digits = '55' . ltrim($digits, '0');
    }
    return $digits;
}

function wc_mask_phone(string $phone): string
{
    $digits = wc_normalize_phone($phone);
    if ($digits === '') {
        return '';
    }
    return substr($digits, 0, 4) . str_repeat('*', max(2, strlen($digits) - 7)) . substr($digits, -3);
}

function wc_valid_phone(string $normalized): bool
{
    return preg_match('/^55\d{10,11}$/', $normalized) === 1;
}

function wc_recipient_source(PDO $pdo, string $audience = 'all'): array
{
    $where = ["guest_phone IS NOT NULL", "TRIM(guest_phone) <> ''"];
    if ($audience === 'confirmed') {
        $where[] = "LOWER(status) IN ('confirmada','confirmado','confirmed','pago','aprovada','aprovado')";
    } elseif ($audience === 'completed') {
        $where[] = 'checkout_date < CURDATE()';
    } elseif ($audience === 'last_6_months') {
        $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)';
    } elseif ($audience === 'last_12_months') {
        $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)';
    }
    $sql = 'SELECT id, guest_name, guest_phone, status, checkout_date, created_at FROM reservations WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function wc_build_recipients(PDO $pdo, string $audience = 'all'): array
{
    $optOuts = [];
    foreach ($pdo->query('SELECT normalized_phone FROM whatsapp_opt_outs')->fetchAll(PDO::FETCH_COLUMN) as $phone) {
        $optOuts[(string) $phone] = true;
    }
    $seen = [];
    $valid = [];
    $skipped = ['invalid_phone' => 0, 'duplicate' => 0, 'opt_out' => 0];
    foreach (wc_recipient_source($pdo, $audience) as $row) {
        $normalized = wc_normalize_phone((string) ($row['guest_phone'] ?? ''));
        if (!wc_valid_phone($normalized)) {
            $skipped['invalid_phone']++;
            continue;
        }
        if (isset($seen[$normalized])) {
            $skipped['duplicate']++;
            continue;
        }
        $seen[$normalized] = true;
        if (isset($optOuts[$normalized])) {
            $skipped['opt_out']++;
            continue;
        }
        $valid[] = [
            'reservation_id' => (int) $row['id'],
            'guest_name' => (string) ($row['guest_name'] ?? ''),
            'phone' => (string) ($row['guest_phone'] ?? ''),
            'normalized_phone' => $normalized,
        ];
    }
    return ['recipients' => $valid, 'skipped' => $skipped];
}

function wc_schedule_times(PDO $pdo, int $count): array
{
    $s = wc_all_settings($pdo);
    $minDelay = max(5, (int) $s['min_delay_seconds']);
    $maxDelay = max($minDelay, (int) $s['max_delay_seconds']);
    $pauseAfterMin = max(1, (int) $s['pause_after_min']);
    $pauseAfterMax = max($pauseAfterMin, (int) $s['pause_after_max']);
    $pauseMin = max(0, (int) $s['pause_min_seconds']);
    $pauseMax = max($pauseMin, (int) $s['pause_max_seconds']);
    $blockSize = random_int($pauseAfterMin, $pauseAfterMax);
    $inBlock = 0;
    $time = time();
    $times = [];
    for ($i = 0; $i < $count; $i++) {
        $time += random_int($minDelay, $maxDelay);
        $inBlock++;
        if ($inBlock >= $blockSize && $i < $count - 1) {
            $time += random_int($pauseMin, $pauseMax);
            $inBlock = 0;
            $blockSize = random_int($pauseAfterMin, $pauseAfterMax);
        }
        $times[] = date('Y-m-d H:i:s', $time);
    }
    return $times;
}

function wc_fill_message(PDO $pdo, string $template, array $recipient): string
{
    $name = trim((string) ($recipient['guest_name'] ?? ''));
    $firstName = $name !== '' ? preg_split('/\s+/', $name)[0] : '';
    $brand = evo_brand_name($pdo);
    $message = strtr($template, [
        '{nome}' => $name,
        '{primeiro_nome}' => $firstName,
        '{pousada}' => $brand,
        '{telefone}' => (string) ($recipient['phone'] ?? ''),
    ]);
    if (wc_setting($pdo, 'append_opt_out_text', '1') === '1') {
        $message .= "\n\nPara nao receber novas campanhas, responda SAIR.";
    }
    return trim($message);
}

function wc_refresh_counts(PDO $pdo, int $campaignId): void
{
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) total,
            SUM(status = 'pending') pending_count,
            SUM(status = 'sent') sent_count,
            SUM(status = 'failed') failed_count,
            SUM(status = 'skipped') skipped_count
         FROM whatsapp_campaign_recipients WHERE campaign_id = ?"
    );
    $stmt->execute([$campaignId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $upd = $pdo->prepare(
        'UPDATE whatsapp_campaigns
         SET total_recipients = ?, pending_count = ?, sent_count = ?, failed_count = ?, skipped_count = ?, updated_at = NOW()
         WHERE id = ?'
    );
    $upd->execute([
        (int) ($row['total'] ?? 0),
        (int) ($row['pending_count'] ?? 0),
        (int) ($row['sent_count'] ?? 0),
        (int) ($row['failed_count'] ?? 0),
        (int) ($row['skipped_count'] ?? 0),
        $campaignId,
    ]);
}

function wc_create_campaign(PDO $pdo, array $data, ?int $adminId = null): int
{
    $title = trim((string) ($data['title'] ?? ''));
    $message = trim((string) ($data['message'] ?? ''));
    if ($title === '' || $message === '') {
        jsonResponse(['error' => 'Informe titulo e mensagem da campanha.'], 422);
    }
    $stmt = $pdo->prepare('INSERT INTO whatsapp_campaigns (title, message, image_path, status, created_by) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$title, $message, trim((string) ($data['image_path'] ?? '')) ?: null, 'draft', $adminId]);
    return (int) $pdo->lastInsertId();
}

function wc_start_campaign(PDO $pdo, int $campaignId, string $audience = 'all'): array
{
    $stmt = $pdo->prepare('SELECT * FROM whatsapp_campaigns WHERE id = ? LIMIT 1');
    $stmt->execute([$campaignId]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$campaign) {
        jsonResponse(['error' => 'Campanha nao encontrada.'], 404);
    }
    if (!in_array((string) $campaign['status'], ['draft', 'paused'], true)) {
        jsonResponse(['error' => 'A campanha nao pode ser iniciada neste status.'], 409);
    }
    $built = wc_build_recipients($pdo, $audience);
    $recipients = $built['recipients'];
    if (!$recipients) {
        jsonResponse(['error' => 'Nenhum hospede elegivel encontrado para esta campanha.', 'skipped' => $built['skipped']], 422);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM whatsapp_campaign_recipients WHERE campaign_id = ?')->execute([$campaignId]);
        $times = wc_schedule_times($pdo, count($recipients));
        $ins = $pdo->prepare(
            'INSERT INTO whatsapp_campaign_recipients
             (campaign_id, reservation_id, guest_name, phone, normalized_phone, status, scheduled_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($recipients as $i => $recipient) {
            $ins->execute([
                $campaignId,
                $recipient['reservation_id'],
                $recipient['guest_name'],
                $recipient['phone'],
                $recipient['normalized_phone'],
                'pending',
                $times[$i],
            ]);
        }
        $pdo->prepare("UPDATE whatsapp_campaigns SET status = 'sending', started_at = COALESCE(started_at, NOW()), paused_at = NULL, updated_at = NOW() WHERE id = ?")->execute([$campaignId]);
        wc_refresh_counts($pdo, $campaignId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        jsonResponse(['error' => 'Erro ao preparar campanha.', 'detail' => $e->getMessage()], 500);
    }
    return ['campaign_id' => $campaignId, 'recipient_count' => count($recipients), 'skipped' => $built['skipped']];
}

function wc_uploaded_public_url(string $relativePath): string
{
    return rtrim(be_request_origin(), '/') . '/' . ltrim($relativePath, '/');
}

function wc_send_to_recipient(PDO $pdo, array $campaign, array $recipient, bool $dryRun = false): array
{
    $message = wc_fill_message($pdo, (string) $campaign['message'], $recipient);
    $imagePath = trim((string) ($campaign['image_path'] ?? ''));
    if ($imagePath !== '') {
        $abs = realpath(__DIR__ . '/../' . ltrim($imagePath, '/'));
        if (!$abs || !is_file($abs)) {
            return ['ok' => false, 'error' => 'Imagem da campanha nao encontrada.'];
        }
        $bytes = @file_get_contents($abs);
        if ($bytes === false) {
            return ['ok' => false, 'error' => 'Nao foi possivel ler a imagem da campanha.'];
        }
        $mime = function_exists('mime_content_type') ? (string) mime_content_type($abs) : 'image/jpeg';
        $number = evo_format_number((string) $recipient['normalized_phone']);
        $cfg = evo_http_config($pdo);
        $instance = (string) ($cfg['instance'] ?? '');
        if (!evo_is_configured($pdo)) {
            return ['ok' => false, 'error' => 'Evolution API nao configurada. Configure URL/API key e conecte o WhatsApp no painel.'];
        }
        return evo_request($pdo, 'POST', '/message/sendMedia/' . rawurlencode($instance), [
            'number' => $number,
            'mediatype' => 'image',
            'mimetype' => $mime !== '' ? $mime : 'image/jpeg',
            'media' => base64_encode($bytes),
            'mediaBase64' => base64_encode($bytes),
            'fileName' => basename($abs),
            'caption' => $message,
        ], 20, $dryRun);
    }
    return evo_send_text_request($pdo, (string) $recipient['normalized_phone'], $message, $dryRun);
}

function wc_process_next(PDO $pdo, bool $dryRun = false, ?int $campaignId = null): array
{
    $limit = max(1, min(10, (int) wc_setting($pdo, 'max_per_worker_run', '1')));
    $params = [];
    $where = "status = 'sending'";
    if ($campaignId !== null && $campaignId > 0) {
        $where .= ' AND id = ?';
        $params[] = $campaignId;
    }
    $stmt = $pdo->prepare("SELECT * FROM whatsapp_campaigns WHERE {$where} ORDER BY started_at ASC, id ASC LIMIT 1");
    $stmt->execute($params);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$campaign) {
        return ['ok' => true, 'processed' => 0, 'message' => 'Nenhuma campanha ativa.'];
    }
    $recStmt = $pdo->prepare(
        "SELECT * FROM whatsapp_campaign_recipients
         WHERE campaign_id = ? AND status = 'pending' AND (scheduled_at IS NULL OR scheduled_at <= NOW())
         ORDER BY scheduled_at ASC, id ASC LIMIT {$limit}"
    );
    $recStmt->execute([(int) $campaign['id']]);
    $recipients = $recStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$recipients) {
        $pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_campaign_recipients WHERE campaign_id = ? AND status = 'pending'");
        $pendingStmt->execute([(int) $campaign['id']]);
        if ((int) $pendingStmt->fetchColumn() === 0) {
            $pdo->prepare("UPDATE whatsapp_campaigns SET status = 'completed', completed_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([(int) $campaign['id']]);
        }
        wc_refresh_counts($pdo, (int) $campaign['id']);
        return ['ok' => true, 'processed' => 0, 'campaign_id' => (int) $campaign['id'], 'message' => 'Nenhum destinatario vencido no momento.'];
    }
    $results = [];
    foreach ($recipients as $recipient) {
        $result = wc_send_to_recipient($pdo, $campaign, $recipient, $dryRun);
        if ($dryRun) {
            $results[] = ['recipient_id' => (int) $recipient['id'], 'phone' => wc_mask_phone((string) $recipient['normalized_phone']), 'dry_run' => true, 'result' => $result];
            continue;
        }
        if (!empty($result['ok'])) {
            $pdo->prepare("UPDATE whatsapp_campaign_recipients SET status = 'sent', attempts = attempts + 1, sent_at = NOW(), last_error = NULL, updated_at = NOW() WHERE id = ?")->execute([(int) $recipient['id']]);
        } else {
            $error = substr((string) ($result['error'] ?? 'Falha no envio'), 0, 1000);
            $pdo->prepare("UPDATE whatsapp_campaign_recipients SET status = 'failed', attempts = attempts + 1, last_error = ?, updated_at = NOW() WHERE id = ?")->execute([$error, (int) $recipient['id']]);
        }
        $results[] = ['recipient_id' => (int) $recipient['id'], 'phone' => wc_mask_phone((string) $recipient['normalized_phone']), 'ok' => !empty($result['ok']), 'error' => $result['error'] ?? ''];
    }
    if (!$dryRun) {
        wc_refresh_counts($pdo, (int) $campaign['id']);
    }
    return ['ok' => true, 'processed' => count($recipients), 'campaign_id' => (int) $campaign['id'], 'results' => $results];
}

function wc_campaign_details(PDO $pdo, int $campaignId): array
{
    $stmt = $pdo->prepare('SELECT * FROM whatsapp_campaigns WHERE id = ? LIMIT 1');
    $stmt->execute([$campaignId]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$campaign) {
        jsonResponse(['error' => 'Campanha nao encontrada.'], 404);
    }
    $rec = $pdo->prepare('SELECT id, guest_name, phone, normalized_phone, status, attempts, last_error, scheduled_at, sent_at FROM whatsapp_campaign_recipients WHERE campaign_id = ? ORDER BY id DESC LIMIT 300');
    $rec->execute([$campaignId]);
    $recipients = [];
    foreach ($rec->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['phone_masked'] = wc_mask_phone((string) $row['normalized_phone']);
        unset($row['normalized_phone']);
        $recipients[] = $row;
    }
    return ['campaign' => $campaign, 'recipients' => $recipients];
}
