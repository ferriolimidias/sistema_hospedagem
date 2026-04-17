<?php
require 'c:/laragon/www/recantodaserra/api/db.php';
try {
    $stmt = $pdo->query("SELECT email, role FROM admins");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($admins);
}
catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
