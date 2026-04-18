<?php
declare(strict_types=1);

/**
 * Cupons e serviços extras para reservas (funções puras + PDO).
 */

function be_normalize_coupon_code(string $code): string
{
    return strtoupper(trim($code));
}

/**
 * @return array<string,mixed>|null
 */
function be_find_active_coupon(PDO $pdo, string $code): ?array
{
    $c = be_normalize_coupon_code($code);
    if ($c === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM coupons WHERE active = 1 AND UPPER(TRIM(code)) = ? AND (expiry_date IS NULL OR expiry_date >= CURDATE()) LIMIT 1'
    );
    $stmt->execute([$c]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @param list<int|string> $ids
 * @return array{total: float, lines: list<array{id:int,name:string,price:float}>}
 */
function be_extra_services_from_ids(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($v) => $v > 0)));
    if ($ids === []) {
        return ['total' => 0.0, 'lines' => []];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, name, price FROM extra_services WHERE active = 1 AND id IN ($placeholders)");
    $stmt->execute($ids);
    $lines = [];
    $total = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $p = (float) ($r['price'] ?? 0);
        if ($p < 0) {
            $p = 0.0;
        }
        $lines[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'price' => $p];
        $total += $p;
    }
    return ['total' => round($total, 2), 'lines' => $lines];
}

/**
 * Desconto a aplicar sobre o subtotal (hospedagem + extras, antes do cupom).
 *
 * @param array<string,mixed> $coupon Linha da tabela coupons
 */
function be_compute_discount(float $subtotal, array $coupon): float
{
    if ($subtotal <= 0) {
        return 0.0;
    }
    $type = strtolower((string) ($coupon['type'] ?? ''));
    $val = (float) ($coupon['value'] ?? 0);
    if ($val <= 0) {
        return 0.0;
    }
    if ($type === 'percent') {
        $pct = min(100.0, max(0.0, $val));
        return round($subtotal * ($pct / 100.0), 2);
    }
    return round(min($subtotal, $val), 2);
}

/**
 * @param array<int|string,mixed> $raw
 * @return list<int>
 */
function be_parse_extra_service_ids_from_payload(array $raw): array
{
    if (!empty($raw['extra_service_ids']) && is_array($raw['extra_service_ids'])) {
        return array_values(array_filter(array_map('intval', $raw['extra_service_ids']), static fn($v) => $v > 0));
    }
    if (!empty($raw['extra_service_ids']) && is_string($raw['extra_service_ids'])) {
        $d = json_decode($raw['extra_service_ids'], true);
        if (is_array($d)) {
            return array_values(array_filter(array_map('intval', $d), static fn($v) => $v > 0));
        }
    }
    return [];
}

function be_require_internal_key(PDO $pdo): void
{
    require_once __DIR__ . '/contract_access.php';
    $headers = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
        }
    }
    $provided = trim((string) ($headers['x-internal-key'] ?? ''));
    $expected = getOrCreateInternalApiKey($pdo);
    if ($provided === '' || !hash_equals($expected, $provided)) {
        jsonResponse(['error' => 'Não autorizado'], 403);
    }
}
