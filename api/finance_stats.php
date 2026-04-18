<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking_extras.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

be_require_internal_key($pdo);

try {
    $revenue = (float) $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM reservations WHERE status = 'Confirmada'")->fetchColumn();

    $balanceHalf = (float) $pdo->query("
        SELECT COALESCE(SUM(total_amount / 2), 0) FROM reservations
        WHERE status = 'Confirmada' AND LOWER(payment_rule) = 'half' AND balance_paid = 0
    ")->fetchColumn();

    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    $daysInMonth = (int) date('t');
    $activeChalets = (int) $pdo->query("SELECT COUNT(*) FROM chalets WHERE status = 'Ativo'")->fetchColumn();
    $capacity = max(1, $activeChalets * $daysInMonth);

    $stmt = $pdo->prepare("
        SELECT chalet_id, checkin_date, checkout_date
        FROM reservations
        WHERE status IN ('Confirmada', 'Aguardando Pagamento')
          AND checkin_date < ?
          AND checkout_date > ?
    ");
    $stmt->execute([$monthEnd, $monthStart]);
    $occupiedSlots = 0;
    $startD = new DateTimeImmutable($monthStart);
    $endD = new DateTimeImmutable($monthEnd);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cin = new DateTimeImmutable((string) $row['checkin_date']);
        $cout = new DateTimeImmutable((string) $row['checkout_date']);
        $d = max($cin, $startD);
        while ($d < $cout && $d <= $endD) {
            $occupiedSlots++;
            $d = $d->modify('+1 day');
        }
    }
    $occupancyPct = round(min(100.0, ($occupiedSlots / $capacity) * 100.0), 1);

    jsonResponse([
        'revenue_confirmed' => round($revenue, 2),
        'balance_half_pending' => round($balanceHalf, 2),
        'occupancy_pct' => $occupancyPct,
        'month' => date('Y-m'),
        'capacity_room_nights' => $capacity,
        'occupied_room_nights' => $occupiedSlots,
    ]);
} catch (Throwable $e) {
    jsonResponse(['error' => 'Falha ao calcular estatísticas'], 500);
}
