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

    // Leitura de configurações híbridas de pagamento.
    $readSetting = static function (PDO $pdo, string $key, string $default = ''): string {
        try {
            $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
            $st->execute([$key]);
            $val = $st->fetchColumn();
            if (!is_string($val) || $val === '') return $default;
            $decoded = json_decode($val, true);
            if (json_last_error() === JSON_ERROR_NONE && is_string($decoded)) return $decoded;
            return $val;
        } catch (Throwable $e) {
            return $default;
        }
    };
    $asBool = static fn($v) => in_array(trim((string)$v), ['1', 'true', 'on', 'yes'], true);

    $mpActiveRaw = $readSetting($pdo, 'payment_mercadopago_active', '1');
    $manualActiveRaw = $readSetting($pdo, 'payment_manual_pix_active', '0');

    // Valida se Mercado Pago tem Access Token configurado — se o admin marcou "ativo"
    // mas o token não foi gravado, desliga silenciosamente para não quebrar o checkout.
    $mpConfigured = false;
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'mercadoPagoSettings' LIMIT 1");
        $st->execute();
        $raw = $st->fetchColumn();
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $mpConfigured = is_array($decoded) && !empty($decoded['accessToken']);
    } catch (Throwable $e) { /* noop */ }

    $mpActive = $asBool($mpActiveRaw) && $mpConfigured;
    $manualActive = $asBool($manualActiveRaw);

    // Dados para o fluxo manual. Número do WhatsApp vem de personalizacao.wa_numero (única fonte).
    $manualPixKey = $readSetting($pdo, 'manual_pix_key', '');
    $manualInstructions = $readSetting($pdo, 'manual_pix_instructions', '');
    $waNumber = '';
    try {
        $st = $pdo->query("SELECT wa_numero FROM personalizacao ORDER BY id DESC LIMIT 1");
        $waNumber = (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) { /* tabela pode não existir */ }

    // Fallback defensivo: se nenhum dos dois for válido, liga MP (evita UI sem opções).
    if (!$mpActive && !$manualActive) {
        $mpActive = $mpConfigured;
    }

    jsonResponse([
        'show_coupon_field' => $nCoupons > 0,
        'show_extras_section' => count($services) > 0,
        'extra_services' => $services,
        'payment_policies' => $paymentPolicies,
        'payment_methods' => [
            'mercadopago_active' => $mpActive,
            'manual_pix_active' => $manualActive,
            'mp_configured' => $mpConfigured,
            'manual_pix_key' => $manualPixKey,
            'manual_instructions' => $manualInstructions,
            'wa_number' => $waNumber,
        ],
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
        'payment_methods' => [
            'mercadopago_active' => true,
            'manual_pix_active' => false,
            'mp_configured' => false,
            'manual_pix_key' => '',
            'manual_instructions' => '',
            'wa_number' => '',
        ],
    ]);
}
