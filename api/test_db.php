<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $stmt = $pdo->query("SELECT email, role FROM admins");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($admins);
}
catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
