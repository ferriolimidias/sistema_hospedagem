<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking_extras.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function sr_is_valid_ymd(string $dateYmd): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateYmd, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    return (bool)$date && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0));
}

function sr_validate_payload(PDO $pdo, array $data): array
{
    $ruleName = trim((string)($data['rule_name'] ?? ''));
    $ruleType = strtolower(trim((string)($data['rule_type'] ?? 'period')));
    if (!in_array($ruleType, ['period', 'recurring'], true)) {
        $ruleType = 'period';
    }
    $startDate = trim((string)($data['start_date'] ?? ''));
    $endDate = trim((string)($data['end_date'] ?? ''));
    $minNights = isset($data['min_nights']) ? (int)$data['min_nights'] : 0;
    $recurringDaysRaw = $data['recurring_days'] ?? null;
    $chaletIdRaw = $data['chalet_id'] ?? null;
    $chaletId = null;
    $recurringDays = null;

    if ($ruleName === '') {
        jsonResponse(['error' => 'Nome da regra é obrigatório.'], 400);
    }
    if ($ruleType === 'period') {
        if (!sr_is_valid_ymd($startDate) || !sr_is_valid_ymd($endDate)) {
            jsonResponse(['error' => 'Datas inválidas. Use o formato YYYY-MM-DD.'], 400);
        }
        if ($startDate > $endDate) {
            jsonResponse(['error' => 'A data inicial não pode ser maior que a data final.'], 400);
        }
    } else {
        $startDate = null;
        $endDate = null;
        if (is_string($recurringDaysRaw)) {
            $decoded = json_decode($recurringDaysRaw, true);
            if (is_array($decoded)) $recurringDaysRaw = $decoded;
        }
        if (!is_array($recurringDaysRaw)) {
            jsonResponse(['error' => 'Dias recorrentes inválidos.'], 400);
        }
        $days = [];
        foreach ($recurringDaysRaw as $d) {
            $i = (int)$d;
            if ($i >= 0 && $i <= 6) $days[] = $i;
        }
        $days = array_values(array_unique($days));
        sort($days);
        if ($days === []) {
            jsonResponse(['error' => 'Selecione ao menos um dia da semana para regra recorrente.'], 400);
        }
        $recurringDays = json_encode($days, JSON_UNESCAPED_UNICODE);
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
        'rule_type' => $ruleType,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'recurring_days' => $recurringDays,
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
            $filters[] = '(
                (sr.rule_type = \'period\' AND sr.start_date IS NOT NULL AND sr.end_date IS NOT NULL AND sr.start_date <= DATE_SUB(?, INTERVAL 1 DAY) AND sr.end_date >= ?)
                OR
                (sr.rule_type = \'recurring\')
            )';
            $params[] = $endDate;
            $params[] = $startDate;
        }

        $sql = "SELECT sr.id, sr.rule_name, sr.rule_type, sr.start_date, sr.end_date, sr.recurring_days, sr.min_nights, sr.chalet_id, c.name AS chalet_name
                FROM seasonal_rules sr
                LEFT JOIN chalets c ON c.id = sr.chalet_id";
        if ($filters) {
            $sql .= ' WHERE ' . implode(' AND ', $filters);
        }
        $sql .= ' ORDER BY sr.start_date ASC, sr.end_date ASC, sr.id ASC';

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if (isset($row['recurring_days']) && $row['recurring_days'] !== null && $row['recurring_days'] !== '') {
                $decoded = json_decode((string)$row['recurring_days'], true);
                if (is_array($decoded)) {
                    $row['recurring_days'] = array_values(array_filter(array_map('intval', $decoded), static fn($d) => $d >= 0 && $d <= 6));
                } else {
                    $row['recurring_days'] = [];
                }
            } else {
                $row['recurring_days'] = [];
            }
        }
        unset($row);
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
        $st = $pdo->prepare('UPDATE seasonal_rules SET rule_name = ?, rule_type = ?, start_date = ?, end_date = ?, recurring_days = ?, min_nights = ?, chalet_id = ? WHERE id = ?');
        $st->execute([
            $payload['rule_name'],
            $payload['rule_type'],
            $payload['start_date'],
            $payload['end_date'],
            $payload['recurring_days'],
            $payload['min_nights'],
            $payload['chalet_id'],
            $id
        ]);
        jsonResponse(['status' => 'ok', 'id' => $id]);
    }

    $st = $pdo->prepare('INSERT INTO seasonal_rules (rule_name, rule_type, start_date, end_date, recurring_days, min_nights, chalet_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $st->execute([
        $payload['rule_name'],
        $payload['rule_type'],
        $payload['start_date'],
        $payload['end_date'],
        $payload['recurring_days'],
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
