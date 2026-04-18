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
    $defaultPolicies = [
        ['code' => 'half', 'label' => 'Sinal de 50% para reserva', 'percent_now' => 50],
        ['code' => 'full', 'label' => 'Pagamento 100% Antecipado', 'percent_now' => 100],
    ];
    $paymentPolicies = $defaultPolicies;
    $stmtPolicies = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'payment_policies' LIMIT 1");
    $stmtPolicies->execute();
    $rawPolicies = $stmtPolicies->fetchColumn();
    if (is_string($rawPolicies) && trim($rawPolicies) !== '') {
        $decoded = json_decode($rawPolicies, true);
        if (is_array($decoded) && count($decoded) > 0) {
            $clean = [];
            foreach ($decoded as $item) {
                if (!is_array($item)) continue;
                $code = strtolower(trim((string)($item['code'] ?? '')));
                $label = trim((string)($item['label'] ?? ''));
                $percentNow = isset($item['percent_now']) ? (float)$item['percent_now'] : -1;
                if ($code === '' || $label === '' || $percentNow <= 0) continue;
                $clean[] = [
                    'code' => $code,
                    'label' => $label,
                    'percent_now' => max(0, min(100, $percentNow)),
                ];
            }
            if (count($clean) > 0) {
                $paymentPolicies = $clean;
            }
        }
    }

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
        'payment_policies' => $paymentPolicies,
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'show_coupon_field' => false,
        'show_extras_section' => false,
        'extra_services' => [],
        'payment_policies' => [
            ['code' => 'half', 'label' => 'Sinal de 50% para reserva', 'percent_now' => 50],
            ['code' => 'full', 'label' => 'Pagamento 100% Antecipado', 'percent_now' => 100],
        ],
    ]);
}
