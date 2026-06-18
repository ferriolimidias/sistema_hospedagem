<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/whatsapp_campaign_lib.php';

try {
    wc_assert_tables($pdo);

    $configuredKey = wc_setting($pdo, 'worker_key');
    $providedKey = trim((string) ($_GET['key'] ?? ($_SERVER['HTTP_X_WORKER_KEY'] ?? '')));
    if ($configuredKey === '' || $providedKey === '' || !hash_equals($configuredKey, $providedKey)) {
        jsonResponse(['error' => 'Worker nao autorizado.'], 403);
    }

    wc_set_setting($pdo, 'cron_last_run_at', date('Y-m-d H:i:s'));
    $dryRun = isset($_GET['dry_run']) && (string) $_GET['dry_run'] === '1';
    $result = wc_process_next($pdo, $dryRun);
    wc_set_setting($pdo, 'cron_last_status', 'Ultima execucao: ' . date('Y-m-d H:i:s') . ' | processados=' . (int) ($result['processed'] ?? 0));
    wc_set_setting($pdo, 'cron_last_error', '');
    jsonResponse(['success' => true, 'worker' => $result]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO) {
        wc_set_setting($pdo, 'cron_last_error', substr($e->getMessage(), 0, 1000));
    }
    jsonResponse(['error' => 'Erro no worker de campanhas WhatsApp.', 'detail' => $e->getMessage()], 500);
}
