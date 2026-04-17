<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $stmt = $pdo->query("SELECT id, name, email, role, permissions, created_at FROM admins ORDER BY id ASC");
        $users = $stmt->fetchAll();
        foreach ($users as &$u) {
            $u['permissions'] = !empty($u['permissions']) ? json_decode($u['permissions'], true) : [];
        }
        jsonResponse($users);
        break;

    case 'POST':
        $data = json_decode(file_get_contents("php://input"), true);
        if (!$data || empty($data['email'])) {
            jsonResponse(['error' => 'Email é obrigatório'], 400);
        }

        $name = trim($data['name'] ?? '');
        $email = trim($data['email']);
        $password = $data['password'] ?? '';
        $role = trim($data['role'] ?? 'admin');
        $permissions = isset($data['permissions']) ? json_encode($data['permissions']) : null;

        if (empty($email)) {
            jsonResponse(['error' => 'Email é obrigatório'], 400);
        }

        $id = !empty($data['id']) ? (int)$data['id'] : null;

        if ($id) {
            // UPDATE
            $stmt = $pdo->prepare("SELECT id FROM admins WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                jsonResponse(['error' => 'Usuário não encontrado'], 404);
            }

            $stmt = $pdo->prepare("SELECT id FROM admins WHERE email = ? AND id != ?");
            $stmt->execute([$email, $id]);
            if ($stmt->fetch()) {
                jsonResponse(['error' => 'Este e-mail já está em uso por outro usuário'], 400);
            }

            if (!empty($password)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE admins SET name=?, email=?, password=?, role=?, permissions=? WHERE id=?");
                $stmt->execute([$name ?: null, $email, $hash, $role, $permissions, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE admins SET name=?, email=?, role=?, permissions=? WHERE id=?");
                $stmt->execute([$name ?: null, $email, $role, $permissions, $id]);
            }
            jsonResponse(['status' => 'success', 'id' => $id]);
        } else {
            // INSERT
            if (empty($password)) {
                jsonResponse(['error' => 'Senha é obrigatória para novo usuário'], 400);
            }

            $stmt = $pdo->prepare("SELECT id FROM admins WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                jsonResponse(['error' => 'Este e-mail já está cadastrado'], 400);
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO admins (name, email, password, role, permissions) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name ?: null, $email, $hash, $role, $permissions]);
            $newId = $pdo->lastInsertId();
            jsonResponse(['status' => 'success', 'id' => (int)$newId], 201);
        }
        break;

    case 'DELETE':
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
        if (!$id) {
            jsonResponse(['error' => 'ID é obrigatório'], 400);
        }
        $stmt = $pdo->prepare("DELETE FROM admins WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            jsonResponse(['error' => 'Usuário não encontrado'], 404);
        }
        jsonResponse(['status' => 'success']);
        break;

    default:
        jsonResponse(['error' => 'Método não permitido'], 405);
}
?>
