<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
be_require_admin_auth($pdo);
if (strtolower(trim(be_env_value('APP_DEBUG', 'false'))) !== 'true') {
    jsonResponse(['error' => 'Teste de banco desativado em produção.'], 403);
}
try {
    $stmt = $pdo->query("SELECT email, role FROM admins");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($admins);
}
catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
