<?php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_email'] = (string) $admin['email'];
    $_SESSION['admin_role'] = (string) ($admin['role'] ?? 'admin');
    $token = hash('sha256', $admin['email'] . time());
    $permissions = !empty($admin['permissions']) ? json_decode($admin['permissions'], true) : null;
    jsonResponse([
        'status' => 'success',
        'token' => $token,
        'email' => $admin['email'],
        'name' => $admin['name'] ?? '',
        'role' => $admin['role'] ?? 'admin',
        'permissions' => $permissions
    ]);
}
else {
    jsonResponse(['error' => 'Credenciais inválidas'], 401);
}
?>
