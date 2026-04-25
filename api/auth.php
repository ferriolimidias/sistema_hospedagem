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
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_email'] = (string) $admin['email'];
    $_SESSION['admin_role'] = (string) ($admin['role'] ?? 'admin');
    $internalKey = '';
    try {
        $stKey = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key IN ('internalApiKey', 'internal_key') ORDER BY CASE setting_key WHEN 'internalApiKey' THEN 0 ELSE 1 END LIMIT 1");
        $stKey->execute();
        $keyValue = $stKey->fetchColumn();
        if (is_string($keyValue) && trim($keyValue) !== '') {
            $internalKey = trim($keyValue);
        }
    } catch (Throwable $e) {
        $internalKey = '';
    }
    $token = hash('sha256', $admin['email'] . time());
    $permissions = !empty($admin['permissions']) ? json_decode($admin['permissions'], true) : null;
    jsonResponse([
        'status' => 'success',
        'token' => $token,
        'internalKey' => $internalKey,
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
