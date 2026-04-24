<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking_extras.php';

be_require_internal_key($pdo);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($method) {
    case 'GET':
        $reservationId = isset($_GET['reservation_id']) ? (int) $_GET['reservation_id'] : 0;
        if ($reservationId <= 0) {
            jsonResponse(['error' => 'reservation_id é obrigatório'], 400);
        }
        $stmt = $pdo->prepare("
            SELECT id, reservation_id, description, quantity, unit_price, total_price, created_at
            FROM reservation_consumptions
            WHERE reservation_id = ?
            ORDER BY created_at DESC, id DESC
        ");
        $stmt->execute([$reservationId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $total = 0.0;
        foreach ($rows as $r) {
            $total += (float) ($r['total_price'] ?? 0);
        }
        jsonResponse([
            'items' => $rows,
            'total_consumed' => round($total, 2),
        ]);
        break;

    case 'POST':
        $data = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($data)) {
            jsonResponse(['error' => 'Payload inválido'], 400);
        }
        $reservationId = isset($data['reservation_id']) ? (int) $data['reservation_id'] : 0;
        $description = trim((string) ($data['description'] ?? ''));
        $quantity = isset($data['quantity']) ? (int) $data['quantity'] : 1;
        $unitPrice = isset($data['unit_price']) ? (float) $data['unit_price'] : 0.0;
        if ($reservationId <= 0 || $description === '') {
            jsonResponse(['error' => 'reservation_id e description são obrigatórios'], 400);
        }
        if ($quantity < 1) {
            $quantity = 1;
        }
        if ($unitPrice < 0) {
            $unitPrice = 0.0;
        }

        $stRes = $pdo->prepare('SELECT id FROM reservations WHERE id = ? LIMIT 1');
        $stRes->execute([$reservationId]);
        if (!$stRes->fetch(PDO::FETCH_ASSOC)) {
            jsonResponse(['error' => 'Reserva não encontrada'], 404);
        }

        $totalPrice = round($quantity * $unitPrice, 2);
        $stmt = $pdo->prepare("
            INSERT INTO reservation_consumptions (reservation_id, description, quantity, unit_price, total_price)
            VALUES (?, ?, ?, ?, ?)
        ");
        $ok = $stmt->execute([$reservationId, $description, $quantity, $unitPrice, $totalPrice]);
        if (!$ok) {
            jsonResponse(['error' => 'Não foi possível lançar consumo'], 500);
        }
        jsonResponse([
            'status' => 'success',
            'id' => (int) $pdo->lastInsertId(),
            'total_price' => $totalPrice,
        ], 201);
        break;

    case 'PUT':
        $data = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($data)) {
            jsonResponse(['error' => 'Payload inválido'], 400);
        }
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        $description = trim((string) ($data['description'] ?? ''));
        $quantity = isset($data['quantity']) ? (int) $data['quantity'] : 1;
        $unitPrice = isset($data['unit_price']) ? (float) $data['unit_price'] : 0.0;
        if ($id <= 0 || $description === '') {
            jsonResponse(['error' => 'id e description são obrigatórios'], 400);
        }
        if ($quantity < 1) $quantity = 1;
        if ($unitPrice < 0) $unitPrice = 0.0;
        $totalPrice = round($quantity * $unitPrice, 2);
        $stmt = $pdo->prepare("
            UPDATE reservation_consumptions
               SET description = ?, quantity = ?, unit_price = ?, total_price = ?
             WHERE id = ?
        ");
        $stmt->execute([$description, $quantity, $unitPrice, $totalPrice, $id]);
        if ($stmt->rowCount() < 1) {
            jsonResponse(['error' => 'Item de consumo não encontrado'], 404);
        }
        jsonResponse(['status' => 'success', 'total_price' => $totalPrice]);
        break;

    case 'DELETE':
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0) {
            jsonResponse(['error' => 'id é obrigatório'], 400);
        }
        $stmt = $pdo->prepare('DELETE FROM reservation_consumptions WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() < 1) {
            jsonResponse(['error' => 'Item de consumo não encontrado'], 404);
        }
        jsonResponse(['status' => 'success']);
        break;

    default:
        jsonResponse(['error' => 'Método não permitido'], 405);
}

