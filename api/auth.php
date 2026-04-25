<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    jsonResponse(['error' => 'Método inválido'], 405);
}

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data['email'], $data['password'])) {
    jsonResponse(['error' => 'Email e senha são obrigatórios'], 400);
}

$stmt = $pdo->prepare("SELECT id, name, email, password, role, permissions FROM admins WHERE email = ?");
$stmt->execute([$data['email']]);
$admin = $stmt->fetch();

if ($admin && password_verify($data['password'], $admin['password'])) {
    $token = bin2hex(random_bytes(32));
    $stUpdate = $pdo->prepare('UPDATE admins SET auth_token = ? WHERE id = ?');
    $stUpdate->execute([$token, (int) $admin['id']]);
    setcookie('admin_token', $token, time() + (86400 * 30), '/', '', isset($_SERVER['HTTPS']), true);
    jsonResponse(['status' => 'success']);
}
else {
    jsonResponse(['error' => 'Credenciais inválidas'], 401);
}
?>
