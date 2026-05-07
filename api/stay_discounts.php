<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    try {
        $stmt = $pdo->query('SELECT id, min_nights, discount_percentage FROM stay_discounts ORDER BY min_nights ASC, discount_percentage DESC');
        $rows = array_map(static function ($row) {
            return [
                'id' => (int)$row['id'],
                'min_nights' => (int)$row['min_nights'],
                'discount_percentage' => (float)$row['discount_percentage'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        jsonResponse($rows);
    } catch (Throwable $e) {
        jsonResponse(['error' => 'Falha ao carregar descontos por noite.'], 500);
    }
}

be_require_internal_key($pdo);

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        jsonResponse(['error' => 'JSON inválido.'], 400);
    }
    $minNights = max(0, (int)($data['min_nights'] ?? 0));
    $percentage = (float)($data['discount_percentage'] ?? 0);
    if ($minNights < 1) {
        jsonResponse(['error' => 'Informe o mínimo de noites.'], 422);
    }
    if ($percentage <= 0 || $percentage > 100) {
        jsonResponse(['error' => 'Percentual de desconto deve estar entre 0,01 e 100.'], 422);
    }
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE stay_discounts SET min_nights = ?, discount_percentage = ? WHERE id = ?');
        $stmt->execute([$minNights, $percentage, $id]);
        jsonResponse(['status' => 'ok', 'id' => $id]);
    }
    $stmt = $pdo->prepare('INSERT INTO stay_discounts (min_nights, discount_percentage) VALUES (?, ?)');
    $stmt->execute([$minNights, $percentage]);
    jsonResponse(['status' => 'ok', 'id' => (int)$pdo->lastInsertId()], 201);
}

if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        jsonResponse(['error' => 'id obrigatório'], 400);
    }
    $stmt = $pdo->prepare('DELETE FROM stay_discounts WHERE id = ?');
    $stmt->execute([$id]);
    jsonResponse(['status' => 'ok']);
}

jsonResponse(['error' => 'Método não permitido'], 405);
