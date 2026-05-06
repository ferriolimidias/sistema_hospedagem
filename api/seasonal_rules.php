<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking_extras.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function sr_validate_payload(PDO $pdo, array $data): array
{
    $ruleName = trim((string)($data['rule_name'] ?? ''));
    $startDate = trim((string)($data['start_date'] ?? ''));
    $endDate = trim((string)($data['end_date'] ?? ''));
    $minNights = isset($data['min_nights']) ? (int)$data['min_nights'] : 0;
    $chaletIdRaw = $data['chalet_id'] ?? null;
    $chaletId = null;

    if ($ruleName === '') {
        jsonResponse(['error' => 'Nome da regra é obrigatório.'], 400);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        jsonResponse(['error' => 'Datas inválidas. Use o formato YYYY-MM-DD.'], 400);
    }
    if ($startDate > $endDate) {
        jsonResponse(['error' => 'A data inicial não pode ser maior que a data final.'], 400);
    }
    if ($minNights < 1) {
        jsonResponse(['error' => 'Mínimo de diárias deve ser maior ou igual a 1.'], 400);
    }

    if ($chaletIdRaw !== null && $chaletIdRaw !== '' && strtolower((string)$chaletIdRaw) !== 'all') {
        $chaletId = (int)$chaletIdRaw;
        if ($chaletId <= 0) {
            jsonResponse(['error' => 'Chalé inválido.'], 400);
        }
        $st = $pdo->prepare('SELECT id FROM chalets WHERE id = ? LIMIT 1');
        $st->execute([$chaletId]);
        if (!$st->fetchColumn()) {
            jsonResponse(['error' => 'Chalé não encontrado.'], 404);
        }
    }

    return [
        'rule_name' => $ruleName,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'min_nights' => $minNights,
        'chalet_id' => $chaletId,
    ];
}

if ($method === 'GET') {
    try {
        $filters = [];
        $params = [];

        if (isset($_GET['chalet_id']) && $_GET['chalet_id'] !== '') {
            $chaletId = (int)$_GET['chalet_id'];
            if ($chaletId > 0) {
                $filters[] = '(sr.chalet_id IS NULL OR sr.chalet_id = ?)';
                $params[] = $chaletId;
            }
        }

        $startDate = isset($_GET['start_date']) ? trim((string)$_GET['start_date']) : '';
        $endDate = isset($_GET['end_date']) ? trim((string)$_GET['end_date']) : '';
        if ($startDate !== '' && $endDate !== '') {
            $filters[] = '(sr.start_date <= DATE_SUB(?, INTERVAL 1 DAY) AND sr.end_date >= ?)';
            $params[] = $endDate;
            $params[] = $startDate;
        }

        $sql = "SELECT sr.id, sr.rule_name, sr.start_date, sr.end_date, sr.min_nights, sr.chalet_id, c.name AS chalet_name
                FROM seasonal_rules sr
                LEFT JOIN chalets c ON c.id = sr.chalet_id";
        if ($filters) {
            $sql .= ' WHERE ' . implode(' AND ', $filters);
        }
        $sql .= ' ORDER BY sr.start_date ASC, sr.end_date ASC, sr.id ASC';

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse($rows);
    } catch (Throwable $e) {
        jsonResponse(['error' => 'Falha ao carregar regras sazonais.', 'details' => $e->getMessage()], 500);
    }
}

be_require_internal_key($pdo);

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        jsonResponse(['error' => 'JSON inválido.'], 400);
    }
    $payload = sr_validate_payload($pdo, $data);
    $id = isset($data['id']) ? (int)$data['id'] : 0;

    if ($id > 0) {
        $st = $pdo->prepare('UPDATE seasonal_rules SET rule_name = ?, start_date = ?, end_date = ?, min_nights = ?, chalet_id = ? WHERE id = ?');
        $st->execute([
            $payload['rule_name'],
            $payload['start_date'],
            $payload['end_date'],
            $payload['min_nights'],
            $payload['chalet_id'],
            $id
        ]);
        jsonResponse(['status' => 'ok', 'id' => $id]);
    }

    $st = $pdo->prepare('INSERT INTO seasonal_rules (rule_name, start_date, end_date, min_nights, chalet_id) VALUES (?, ?, ?, ?, ?)');
    $st->execute([
        $payload['rule_name'],
        $payload['start_date'],
        $payload['end_date'],
        $payload['min_nights'],
        $payload['chalet_id']
    ]);
    jsonResponse(['status' => 'ok', 'id' => (int)$pdo->lastInsertId()], 201);
}

if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        jsonResponse(['error' => 'id obrigatório'], 400);
    }
    $pdo->prepare('DELETE FROM seasonal_rules WHERE id = ?')->execute([$id]);
    jsonResponse(['status' => 'ok']);
}

jsonResponse(['error' => 'Método não permitido'], 405);
