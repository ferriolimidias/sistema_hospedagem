<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking_extras.php';

be_require_internal_key($pdo);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT * FROM coupons ORDER BY id DESC');
    jsonResponse($stmt->fetchAll(PDO::FETCH_ASSOC));
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        jsonResponse(['error' => 'JSON inválido'], 400);
    }
    $id = isset($data['id']) ? (int) $data['id'] : 0;
    $code = be_normalize_coupon_code((string) ($data['code'] ?? ''));
    $type = strtolower((string) ($data['type'] ?? 'percent'));
    if (!in_array($type, ['fixed', 'percent'], true)) {
        jsonResponse(['error' => 'type deve ser fixed ou percent'], 400);
    }
    $value = (float) ($data['value'] ?? 0);
    if ($value <= 0) {
        jsonResponse(['error' => 'value inválido'], 400);
    }
    $expiry = !empty($data['expiry_date']) ? (string) $data['expiry_date'] : null;
    $active = isset($data['active']) ? ((int) (bool) $data['active']) : 1;

    if ($code === '') {
        jsonResponse(['error' => 'Código obrigatório'], 400);
    }

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE coupons SET code=?, type=?, value=?, expiry_date=?, active=? WHERE id=?');
        $stmt->execute([$code, $type, $value, $expiry, $active, $id]);
        jsonResponse(['status' => 'ok', 'id' => $id]);
    }
    $stmt = $pdo->prepare('INSERT INTO coupons (code, type, value, expiry_date, active) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$code, $type, $value, $expiry, $active]);
    jsonResponse(['status' => 'ok', 'id' => (int) $pdo->lastInsertId()], 201);
}

if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        jsonResponse(['error' => 'id obrigatório'], 400);
    }
    $pdo->prepare('DELETE FROM coupons WHERE id=?')->execute([$id]);
    jsonResponse(['status' => 'ok']);
}

jsonResponse(['error' => 'Método não permitido'], 405);
