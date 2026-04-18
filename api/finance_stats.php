<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking_extras.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

be_require_internal_key($pdo);

function fs_load_payment_policies(PDO $pdo): array
{
    $fallback = ['half' => 50.0, 'full' => 100.0];
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'payment_policies' LIMIT 1");
        $stmt->execute();
        $raw = $stmt->fetchColumn();
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded) || count($decoded) === 0) return $fallback;
        $clean = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) continue;
            $code = strtolower(trim((string)($item['code'] ?? '')));
            $pct = isset($item['percent_now']) ? (float)$item['percent_now'] : -1;
            if ($code === '' || $pct <= 0) continue;
            $clean[$code] = max(0.0, min(100.0, $pct));
        }
        if (!count($clean)) return $fallback;
        return $clean;
    } catch (Throwable $e) {
        return $fallback;
    }
}

try {
    $revenue = (float) $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM reservations WHERE status = 'Confirmada'")->fetchColumn();

    $policies = fs_load_payment_policies($pdo);
    $stmtBal = $pdo->prepare("
        SELECT total_amount, payment_rule
        FROM reservations
        WHERE status = 'Confirmada'
          AND balance_paid = 0
    ");
    $stmtBal->execute();
    $balanceHalf = 0.0;
    foreach ($stmtBal->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rule = strtolower((string)($row['payment_rule'] ?? 'full'));
        $pct = isset($policies[$rule]) ? (float)$policies[$rule] : ($rule === 'half' ? 50.0 : 100.0);
        if ($pct >= 100.0) continue;
        $total = (float)($row['total_amount'] ?? 0);
        $remaining = $total * ((100.0 - $pct) / 100.0);
        $balanceHalf += $remaining;
    }

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
