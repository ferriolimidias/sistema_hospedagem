<?php
/**
 * Opções públicas para o fluxo de reserva (cupons ativos existem? serviços extras ativos?).
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

try {
    $nCoupons = (int) $pdo->query('SELECT COUNT(*) FROM coupons WHERE active = 1 AND (expiry_date IS NULL OR expiry_date >= CURDATE())')->fetchColumn();
    $services = [];
    $stmt = $pdo->query('SELECT id, name, price, description FROM extra_services WHERE active = 1 ORDER BY name ASC');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $services[] = [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'price' => (float) $r['price'],
            'description' => (string) ($r['description'] ?? ''),
        ];
    }
    jsonResponse([
        'show_coupon_field' => $nCoupons > 0,
        'show_extras_section' => count($services) > 0,
        'extra_services' => $services,
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'show_coupon_field' => false,
        'show_extras_section' => false,
        'extra_services' => [],
    ]);
}
