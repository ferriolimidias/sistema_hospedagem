<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking_extras.php';

be_require_internal_key($pdo);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT * FROM extra_services ORDER BY id DESC');
    jsonResponse($stmt->fetchAll(PDO::FETCH_ASSOC));
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        jsonResponse(['error' => 'JSON inválido'], 400);
    }
    $id = isset($data['id']) ? (int) $data['id'] : 0;
    $name = trim((string) ($data['name'] ?? ''));
    $price = (float) ($data['price'] ?? 0);
    $description = trim((string) ($data['description'] ?? ''));
    $active = isset($data['active']) ? ((int) (bool) $data['active']) : 1;
    if ($name === '' || $price < 0) {
        jsonResponse(['error' => 'Nome e preço válidos são obrigatórios'], 400);
    }

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE extra_services SET name=?, price=?, description=?, active=? WHERE id=?');
        $stmt->execute([$name, $price, $description !== '' ? $description : null, $active, $id]);
        jsonResponse(['status' => 'ok', 'id' => $id]);
    }
    $stmt = $pdo->prepare('INSERT INTO extra_services (name, price, description, active) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $price, $description !== '' ? $description : null, $active]);
    jsonResponse(['status' => 'ok', 'id' => (int) $pdo->lastInsertId()], 201);
}

if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        jsonResponse(['error' => 'id obrigatório'], 400);
    }
    $pdo->prepare('DELETE FROM extra_services WHERE id=?')->execute([$id]);
    jsonResponse(['status' => 'ok']);
}

jsonResponse(['error' => 'Método não permitido'], 405);
