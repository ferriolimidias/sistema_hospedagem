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
    $minRaw = $data['min_nights'] ?? $data['minNights'] ?? null;
    $minNights = 0;
    if (is_int($minRaw) || is_float($minRaw)) {
        $minNights = max(0, (int) round((float) $minRaw));
    } else {
        $minStr = trim((string) ($minRaw ?? ''));
        $minStrNorm = str_replace(',', '.', preg_replace('/[^\d.,-]/', '', $minStr));
        if ($minStrNorm !== '' && is_numeric($minStrNorm)) {
            $minNights = max(0, (int) floor((float) $minStrNorm));
        }
    }
    $pctRaw = $data['discount_percentage'] ?? $data['discountPercentage'] ?? null;
    $percentage = 0.0;
    if (is_int($pctRaw) || is_float($pctRaw)) {
        $percentage = (float) $pctRaw;
    } elseif (is_string($pctRaw)) {
        $pctClean = str_replace([' ', '%'], '', str_replace(',', '.', trim($pctRaw)));
        if ($pctClean !== '' && is_numeric($pctClean)) {
            $percentage = (float) $pctClean;
        }
    }
    $percentage = round(max(0.0, min(100.0, $percentage)), 2);
    if ($minNights < 1) {
        jsonResponse(['error' => 'Informe o mínimo de noites (número inteiro ≥ 1).'], 422);
    }
    if ($percentage <= 0 || $percentage > 100) {
        jsonResponse(['error' => 'Percentual de desconto deve estar entre 0,01 e 100.'], 422);
    }
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE stay_discounts SET min_nights = ?, discount_percentage = ? WHERE id = ?');
            $stmt->execute([$minNights, $percentage, $id]);
            jsonResponse(['status' => 'ok', 'id' => $id]);
        }
        $stmt = $pdo->prepare('INSERT INTO stay_discounts (min_nights, discount_percentage) VALUES (?, ?)');
        $stmt->execute([$minNights, $percentage]);
        jsonResponse(['status' => 'ok', 'id' => (int)$pdo->lastInsertId()], 201);
    } catch (Throwable $e) {
        jsonResponse(['error' => 'Falha ao gravar no banco de dados.', 'details' => $e->getMessage()], 500);
    }
}

if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        jsonResponse(['error' => 'id obrigatório'], 400);
    }
    try {
        $stmt = $pdo->prepare('DELETE FROM stay_discounts WHERE id = ?');
        $stmt->execute([$id]);
        jsonResponse(['status' => 'ok']);
    } catch (Throwable $e) {
        jsonResponse(['error' => 'Falha ao excluir no banco de dados.', 'details' => $e->getMessage()], 500);
    }
}

jsonResponse(['error' => 'Método não permitido'], 405);
