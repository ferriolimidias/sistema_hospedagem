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

    $customPrices = [];
    try {
        $stmtCustomPrices = $pdo->query('SELECT chalet_id, custom_date, price, description FROM chalet_custom_prices ORDER BY chalet_id ASC, custom_date ASC');
        foreach ($stmtCustomPrices->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cid = (string)(int)$r['chalet_id'];
            $date = (string)$r['custom_date'];
            if ($cid === '0' || $date === '') continue;
            if (!isset($customPrices[$cid])) $customPrices[$cid] = [];
            $customPrices[$cid][$date] = [
                'price' => (float)$r['price'],
                'description' => (string)($r['description'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        $customPrices = [];
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
    $cancellationPolicy = $readSetting($pdo, 'cancellation_policy', '');
    $cleaningFee = max(0.0, (float)str_replace(',', '.', $readSetting($pdo, 'cleaning_fee', '0')));
    $petFee = max(0.0, (float)str_replace(',', '.', $readSetting($pdo, 'pet_fee', '0')));
    $calendarMaxMonths = max(1, min(24, (int)$readSetting($pdo, 'calendar_max_months', '6')));

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

    $seasonalRules = [];
    try {
        $stmtSeasonal = $pdo->query("SELECT id, rule_name, rule_type, start_date, end_date, recurring_days, min_nights, chalet_id FROM seasonal_rules ORDER BY start_date ASC, end_date ASC, id ASC");
        foreach ($stmtSeasonal->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $recurringDays = null;
            if (isset($r['recurring_days']) && $r['recurring_days'] !== null && $r['recurring_days'] !== '') {
                $decodedDays = json_decode((string)$r['recurring_days'], true);
                if (is_array($decodedDays)) {
                    $recurringDays = array_values(array_filter(array_map('intval', $decodedDays), static fn($d) => $d >= 0 && $d <= 6));
                }
            }
            $seasonalRules[] = [
                'id' => (int)$r['id'],
                'rule_name' => (string)$r['rule_name'],
                'rule_type' => (string)($r['rule_type'] ?? 'period'),
                'start_date' => isset($r['start_date']) && $r['start_date'] !== null ? (string)$r['start_date'] : null,
                'end_date' => isset($r['end_date']) && $r['end_date'] !== null ? (string)$r['end_date'] : null,
                'recurring_days' => $recurringDays,
                'min_nights' => (int)$r['min_nights'],
                'chalet_id' => isset($r['chalet_id']) ? (is_null($r['chalet_id']) ? null : (int)$r['chalet_id']) : null,
            ];
        }
    } catch (Throwable $e) {
        $seasonalRules = [];
    }

    $stayDiscounts = [];
    try {
        $stmtDiscounts = $pdo->query('SELECT id, min_nights, discount_percentage FROM stay_discounts ORDER BY min_nights ASC, discount_percentage DESC');
        foreach ($stmtDiscounts->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $stayDiscounts[] = [
                'id' => (int)$r['id'],
                'min_nights' => (int)$r['min_nights'],
                'discount_percentage' => (float)$r['discount_percentage'],
            ];
        }
    } catch (Throwable $e) {
        $stayDiscounts = [];
    }

    jsonResponse([
        'show_coupon_field' => $nCoupons > 0,
        'show_extras_section' => count($services) > 0,
        'extra_services' => $services,
        'custom_prices' => $customPrices,
        'payment_policies' => $paymentPolicies,
        'cancellation_policy' => $cancellationPolicy,
        'cleaning_fee' => $cleaningFee,
        'pet_fee' => $petFee,
        'calendar_max_months' => $calendarMaxMonths,
        'stay_discounts' => $stayDiscounts,
        'seasonal_rules' => $seasonalRules,
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
        'custom_prices' => [],
        'payment_policies' => [
            ['code' => 'half', 'label' => 'Sinal de 50% para reserva', 'percent_now' => 50],
            ['code' => 'full', 'label' => 'Pagamento 100% Antecipado', 'percent_now' => 100],
        ],
        'cancellation_policy' => '',
        'cleaning_fee' => 0,
        'pet_fee' => 0,
        'calendar_max_months' => 6,
        'stay_discounts' => [],
        'seasonal_rules' => [],
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
