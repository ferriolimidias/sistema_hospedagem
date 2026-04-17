<?php
/**
 * Verifica o que está na tabela settings.
 * Acesse: /api/check_settings.php
 */
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $stmt = $pdo->query("SELECT setting_key, LENGTH(setting_value) as tamanho, LEFT(setting_value, 100) as preview FROM settings");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        'total_registros' => count($rows),
        'registros' => $rows,
        'customization_existe' => !empty(array_filter($rows, fn($r) => $r['setting_key'] === 'customization'))
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['erro' => $e->getMessage()]);
}
