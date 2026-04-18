<?php
/**
 * Disponibilidade por calendário (sem simulação de preço).
 * O valor da estadia é calculado em api/pricing.php + api/reservations.php (POST).
 */
require_once __DIR__ . '/db.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

try {
    cleanupExpiredPendingReservations($pdo);

    $chaletId = isset($_GET['chalet_id']) ? (int)$_GET['chalet_id'] : 0;
    if ($chaletId <= 0) {
        jsonResponse(['error' => 'chalet_id é obrigatório'], 400);
    }

    $periodStart = $_GET['start_date'] ?? null;
    $periodEnd = $_GET['end_date'] ?? null;

    $month = isset($_GET['month']) ? (int)$_GET['month'] : 0;
    $year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
    if ((!$periodStart || !$periodEnd) && $month >= 1 && $month <= 12 && $year >= 2000) {
        $periodStart = sprintf('%04d-%02d-01', $year, $month);
        $periodEnd = date('Y-m-d', strtotime($periodStart . ' +1 month'));
    }

    $query = "
        SELECT id, checkin_date, checkout_date, status, expires_at
        FROM reservations
        WHERE chalet_id = ?
          AND (
                status = 'Confirmada'
                OR (status = 'Aguardando Pagamento' AND expires_at IS NOT NULL AND expires_at > NOW())
              )
    ";
    $params = [$chaletId];

    if (!empty($periodStart) && !empty($periodEnd)) {
        $query .= " AND checkin_date < ? AND checkout_date > ?";
        $params[] = $periodEnd;
        $params[] = $periodStart;
    }

    $query .= " ORDER BY checkin_date ASC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $blockedIntervals = array_map(static function ($row) {
        return [
            'reservation_id' => (int)$row['id'],
            'checkin_date' => $row['checkin_date'],
            'checkout_date' => $row['checkout_date'],
            'status' => $row['status'],
            'expires_at' => $row['expires_at']
        ];
    }, $rows);

    $isAvailable = null;
    if (!empty($periodStart) && !empty($periodEnd)) {
        $isAvailable = empty($blockedIntervals);
    }

    jsonResponse([
        'chalet_id' => $chaletId,
        'start_date' => $periodStart,
        'end_date' => $periodEnd,
        'is_available' => $isAvailable,
        'blocked_intervals' => $blockedIntervals
    ]);
} catch (Exception $e) {
    jsonResponse(['error' => 'Falha ao consultar disponibilidade', 'details' => $e->getMessage()], 500);
}
?>
