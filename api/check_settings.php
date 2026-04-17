<?php
/**
 * Verifica o que está na tabela settings.
 * Acesse: /api/check_settings.php
 */
header('Content-Type: application/json; charset=utf-8');

$host = '127.0.0.1';
$db = 'recantodaserra_db';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    echo json_encode(['erro' => 'Conexão falhou', 'detalhes' => $e->getMessage()]);
    exit;
}

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
