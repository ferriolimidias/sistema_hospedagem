<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

be_require_internal_key($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

function parseDateOrNull(?string $raw): ?string
{
    $s = trim((string)$raw);
    if ($s === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
    return $s;
}

function monthLabelPtBr(string $yyyyMm): string
{
    $parts = explode('-', $yyyyMm);
    if (count($parts) !== 2) return $yyyyMm;
    $year = $parts[0];
    $month = (int)$parts[1];
    $labels = [
        1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr',
        5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago',
        9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez'
    ];
    $m = $labels[$month] ?? $parts[1];
    return $m . '/' . $year;
}

function reportCompanyName(PDO $pdo): string
{
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'company_name' LIMIT 1");
        $st->execute();
        $company = trim((string)$st->fetchColumn());
        if ($company !== '') return $company;
    } catch (Throwable $e) {
        // noop
    }
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'site_title' LIMIT 1");
        $st->execute();
        $site = trim((string)$st->fetchColumn());
        if ($site !== '') return $site;
    } catch (Throwable $e) {
        // noop
    }
    return 'Meu Estabelecimento';
}

function buildDateFilterSql(?string $startDate, ?string $endDate, array &$params): string
{
    $where = [];
    if ($startDate !== null) {
        $where[] = 'r.checkout_date >= ?';
        $params[] = $startDate;
    }
    if ($endDate !== null) {
        $where[] = 'r.checkout_date <= ?';
        $params[] = $endDate;
    }
    return count($where) ? (' AND ' . implode(' AND ', $where)) : '';
}

function fetchReportRows(PDO $pdo, ?string $startDate, ?string $endDate): array
{
    $params = [];
    $dateSql = buildDateFilterSql($startDate, $endDate, $params);
    $sql = "
        SELECT
            r.id,
            r.guest_name,
            r.checkin_date,
            r.checkout_date,
            r.total_amount,
            r.additional_value,
            (COALESCE(r.total_amount, 0) + COALESCE(r.additional_value, 0)) AS stay_total,
            COALESCE(SUM(rc.total_price), 0) AS consumption_total
        FROM reservations r
        LEFT JOIN reservation_consumptions rc ON rc.reservation_id = r.id
        WHERE r.status = 'Finalizada' {$dateSql}
        GROUP BY
            r.id, r.guest_name, r.checkin_date, r.checkout_date, r.total_amount, r.additional_value
        ORDER BY r.checkout_date DESC, r.id DESC
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function computeSummaryPayload(PDO $pdo, ?string $startDate, ?string $endDate): array
{
    $rows = fetchReportRows($pdo, $startDate, $endDate);
    $stayTotal = 0.0;
    $consumptionTotal = 0.0;
    $monthly = [];

    foreach ($rows as $row) {
        $stay = round((float)($row['stay_total'] ?? 0), 2);
        $cons = round((float)($row['consumption_total'] ?? 0), 2);
        $grand = round($stay + $cons, 2);

        // Audit check por linha (consistência interna do relatório).
        if (abs(($stay + $cons) - $grand) > 0.01) {
            error_log('[CRITICAL][reports_audit_row_mismatch] reservation_id=' . (int)($row['id'] ?? 0));
            throw new RuntimeException('Inconsistência financeira detectada no relatório.');
        }

        $stayTotal += $stay;
        $consumptionTotal += $cons;

        $ym = substr((string)($row['checkout_date'] ?? ''), 0, 7);
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            $ym = date('Y-m');
        }
        if (!isset($monthly[$ym])) {
            $monthly[$ym] = ['hospedagem' => 0.0, 'consumo' => 0.0];
        }
        $monthly[$ym]['hospedagem'] += $stay;
        $monthly[$ym]['consumo'] += $cons;
    }

    ksort($monthly);
    $dadosMensais = [];
    foreach ($monthly as $ym => $vals) {
        $dadosMensais[] = [
            'month' => monthLabelPtBr($ym),
            'hospedagem' => round((float)$vals['hospedagem'], 2),
            'consumo' => round((float)$vals['consumo'], 2),
        ];
    }

    return [
        'hospedagem_total' => round($stayTotal, 2),
        'consumo_total' => round($consumptionTotal, 2),
        'geral_total' => round($stayTotal + $consumptionTotal, 2),
        'dados_mensais' => $dadosMensais,
        'rows_count' => count($rows),
    ];
}

$action = strtolower(trim((string)($_GET['action'] ?? 'summary')));
$startDate = parseDateOrNull($_GET['start_date'] ?? null);
$endDate = parseDateOrNull($_GET['end_date'] ?? null);

if ($action === 'summary') {
    try {
        $summary = computeSummaryPayload($pdo, $startDate, $endDate);
        jsonResponse([
            'ok' => true,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'company_name' => reportCompanyName($pdo),
        ] + $summary);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($action === 'export') {
    try {
        $rows = fetchReportRows($pdo, $startDate, $endDate);
        $company = reportCompanyName($pdo);
        $filename = 'relatorio_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($company)) . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            throw new RuntimeException('Falha ao gerar arquivo CSV.');
        }
        // BOM para Excel abrir UTF-8 corretamente.
        echo "\xEF\xBB\xBF";
        fputcsv($out, ['Empresa', $company]);
        fputcsv($out, ['Periodo', ($startDate ?? 'Inicio') . ' ate ' . ($endDate ?? 'Hoje')]);
        fputcsv($out, []);
        fputcsv($out, ['ID Reserva', 'Hospede', 'Check-in', 'Check-out', 'Valor Hospedagem', 'Valor Consumo', 'Total Geral']);

        foreach ($rows as $row) {
            $stay = round((float)($row['stay_total'] ?? 0), 2);
            $cons = round((float)($row['consumption_total'] ?? 0), 2);
            $grand = round($stay + $cons, 2);
            fputcsv($out, [
                (int)($row['id'] ?? 0),
                (string)($row['guest_name'] ?? ''),
                (string)($row['checkin_date'] ?? ''),
                (string)($row['checkout_date'] ?? ''),
                number_format($stay, 2, '.', ''),
                number_format($cons, 2, '.', ''),
                number_format($grand, 2, '.', ''),
            ]);
        }

        fclose($out);
        exit;
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

jsonResponse(['error' => 'Ação inválida'], 400);

