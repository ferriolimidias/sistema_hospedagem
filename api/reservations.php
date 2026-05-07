<?php
require_once 'db.php';
require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/booking_extras.php';
require_once __DIR__ . '/evolution_service.php';

$method = $_SERVER['REQUEST_METHOD'];
// Fluxo público permitido: criação de reserva via POST (site público).
// Demais operações continuam restritas ao painel administrativo.
if ($method !== 'POST') {
    be_require_internal_key($pdo);
}

function loadPaymentPoliciesReservation(PDO $pdo): array
{
    $fallback = [
        ['code' => 'half', 'label' => 'Sinal de 50% para reserva', 'percent_now' => 50.0],
        ['code' => 'full', 'label' => 'Pagamento 100% Antecipado', 'percent_now' => 100.0],
    ];
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
            $label = trim((string)($item['label'] ?? ''));
            if ($code === '' || $pct <= 0) continue;
            $clean[] = ['code' => $code, 'label' => $label, 'percent_now' => max(0.0, min(100.0, $pct))];
        }
        return count($clean) ? $clean : $fallback;
    } catch (Throwable $e) {
        return $fallback;
    }
}

function findPaymentPolicyReservation(array $policies, string $code): array
{
    foreach ($policies as $policy) {
        if (strtolower((string)($policy['code'] ?? '')) === strtolower($code)) return $policy;
    }
    return strtolower($code) === 'half'
        ? ['code' => 'half', 'label' => 'Sinal de 50% para reserva', 'percent_now' => 50.0]
        : ['code' => 'full', 'label' => 'Pagamento 100% Antecipado', 'percent_now' => 100.0];
}

function assertNoReservationConflict(PDO $pdo, int $chaletId, string $checkin, string $checkout, ?int $ignoreReservationId = null): void
{
    try {
        cleanupExpiredPendingReservations($pdo);
    } catch (Throwable $e) {
        // Não impede a validação principal; apenas evita holds vencidos bloquearem o calendário.
    }

    $sql = "
        SELECT id
        FROM reservations
        WHERE chalet_id = ?
          AND checkin_date < ?
          AND checkout_date > ?
          AND status NOT IN ('Cancelada', 'Recusada', 'Expirada', 'Finalizada')
          AND NOT (status = 'Aguardando Pagamento' AND expires_at IS NOT NULL AND expires_at <= NOW())
    ";
    $params = [$chaletId, $checkout, $checkin];

    if ($ignoreReservationId !== null && $ignoreReservationId > 0) {
        $sql .= " AND id != ?";
        $params[] = $ignoreReservationId;
    }

    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetch()) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['error' => 'Erro: Este chalé já possui uma reserva ativa para as datas selecionadas.'], 409);
    }
}

function reservationParseYmdDate(string $dateYmd): ?DateTimeImmutable
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateYmd, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))) {
        return null;
    }
    return $date;
}

function nightsBetweenReservation(string $checkin, string $checkout): int
{
    $cin = reservationParseYmdDate($checkin);
    $cout = reservationParseYmdDate($checkout);
    if (!$cin || !$cout || $cout <= $cin) {
        return 0;
    }
    return (int)$cin->diff($cout)->days;
}

function reservationCheckinDow(string $checkin): int
{
    $date = reservationParseYmdDate($checkin);
    return $date ? (int)$date->format('w') : 0;
}

function chaletReservationMaxGuests(array $chaletRow): int
{
    $raw = $chaletRow['max_guests'] ?? ($chaletRow['capacity'] ?? 4);
    return max(1, (int)$raw);
}

function assertReservationGuestCapacity(array $chaletRow, int $guestsAdults, int $guestsChildren): void
{
    $totalGuests = max(0, $guestsAdults) + max(0, $guestsChildren);
    $maxGuests = chaletReservationMaxGuests($chaletRow);
    if ($totalGuests > $maxGuests) {
        jsonResponse([
            'error' => 'A quantidade de hóspedes excede a capacidade máxima deste chalé.',
            'max_guests' => $maxGuests,
            'requested_guests' => $totalGuests
        ], 422);
    }
}

function reservationSettingFloat(PDO $pdo, string $key, float $default = 0.0): float
{
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null || $raw === '') return $default;
        return max(0.0, (float)str_replace(',', '.', (string)$raw));
    } catch (Throwable $e) {
        return $default;
    }
}

function reservationLoadStayDiscounts(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("SELECT min_nights, discount_percentage FROM stay_discounts ORDER BY min_nights ASC, discount_percentage DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function reservationNormalizeChildrenAges($raw, int $childrenCount): ?string
{
    if ($childrenCount <= 0) return null;
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $raw = $decoded;
        } else {
            $raw = preg_split('/\s*,\s*/', trim($raw));
        }
    }
    if (!is_array($raw)) return null;
    $ages = [];
    foreach ($raw as $age) {
        if (count($ages) >= $childrenCount) break;
        $n = (int)$age;
        if ($n >= 0 && $n <= 17) $ages[] = $n;
    }
    return $ages ? json_encode($ages, JSON_UNESCAPED_UNICODE) : null;
}

function reservationCommercialTotals(PDO $pdo, array $chaletRow, string $checkin, string $checkout, int $guestsAdults, int $guestsChildren, bool $bringsPet, float $additionalValue, float $extrasTotal): array
{
    $nights = pricing_count_nights($checkin, $checkout);
    $lodgingSubtotal = pricing_nightly_subtotal($chaletRow, $checkin, $checkout);
    $stayDiscountRule = pricing_best_stay_discount(reservationLoadStayDiscounts($pdo), $nights);
    $stayDiscountAmount = pricing_apply_percentage_discount($lodgingSubtotal, (float)$stayDiscountRule['discount_percentage']);
    $lodgingAfterDiscount = max(0.0, round($lodgingSubtotal - $stayDiscountAmount, 2));
    $extraGuestTotal = pricing_extra_guest_subtotal($chaletRow, $guestsAdults, $guestsChildren, $nights);
    $cleaningFee = reservationSettingFloat($pdo, 'cleaning_fee', 0.0);
    $petFee = $bringsPet ? reservationSettingFloat($pdo, 'pet_fee', 0.0) : 0.0;
    $total = max(0.0, round($lodgingAfterDiscount + $extraGuestTotal + $additionalValue + $extrasTotal + $cleaningFee + $petFee, 2));
    return [
        'nights' => $nights,
        'lodging_subtotal' => $lodgingSubtotal,
        'stay_discount_percentage' => (float)$stayDiscountRule['discount_percentage'],
        'stay_discount_amount' => $stayDiscountAmount,
        'extra_guest_total' => $extraGuestTotal,
        'cleaning_fee' => $cleaningFee,
        'pet_fee' => $petFee,
        'total' => $total,
    ];
}

function validateSeasonalMinNights(PDO $pdo, int $chaletId, string $checkin, string $checkout): ?array
{
    $nights = nightsBetweenReservation($checkin, $checkout);
    if ($nights <= 0) {
        return [
            'error' => 'Período inválido para cálculo de diárias.',
            'required_min_nights' => 1,
            'selected_nights' => 0,
            'rule' => null,
        ];
    }

    $checkinDow = reservationCheckinDow($checkin);

    $sql = "SELECT id, rule_name, rule_type, start_date, end_date, recurring_days, min_nights, chalet_id
            FROM seasonal_rules
            WHERE (
                    (rule_type = 'period' AND start_date IS NOT NULL AND end_date IS NOT NULL AND start_date <= DATE_SUB(?, INTERVAL 1 DAY) AND end_date >= ?)
                    OR
                    (rule_type = 'recurring' AND recurring_days IS NOT NULL AND JSON_CONTAINS(recurring_days, CAST(? AS JSON), '$'))
                  )
              AND (chalet_id IS NULL OR chalet_id = ?)
            ORDER BY min_nights DESC, id ASC
            LIMIT 1";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$checkout, $checkin, (string)$checkinDow, $chaletId]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    if (!$rule) {
        return null;
    }

    $required = max(1, (int)($rule['min_nights'] ?? 1));
    if ($nights >= $required) {
        return null;
    }

    return [
        'error' => "Período selecionado exige mínimo de {$required} diárias pela regra \"{$rule['rule_name']}\".",
        'required_min_nights' => $required,
        'selected_nights' => $nights,
        'rule' => [
            'id' => (int)$rule['id'],
            'rule_name' => (string)$rule['rule_name'],
            'rule_type' => (string)($rule['rule_type'] ?? 'period'),
            'start_date' => isset($rule['start_date']) && $rule['start_date'] !== null ? (string)$rule['start_date'] : null,
            'end_date' => isset($rule['end_date']) && $rule['end_date'] !== null ? (string)$rule['end_date'] : null,
            'recurring_days' => isset($rule['recurring_days']) && $rule['recurring_days'] !== null ? json_decode((string)$rule['recurring_days'], true) : null,
            'min_nights' => $required,
            'chalet_id' => isset($rule['chalet_id']) ? (is_null($rule['chalet_id']) ? null : (int)$rule['chalet_id']) : null,
        ],
    ];
}

function buildCheckoutSummaryForNotification(PDO $pdo, int $reservationId, array $reservationRow, ?array $explicitSummary = null): array
{
    $totalAmount = round((float)($reservationRow['total_amount'] ?? 0), 2);
    $policyCode = (string)($reservationRow['payment_rule'] ?? 'full');
    $policies = loadPaymentPoliciesReservation($pdo);
    $policy = findPaymentPolicyReservation($policies, $policyCode);
    $percentNow = max(0.0, min(100.0, (float)($policy['percent_now'] ?? 100.0)));
    $percentBal = max(0.0, 100.0 - $percentNow);
    $saldo = round(($totalAmount * $percentBal) / 100.0, 2);
    $saldoPendente = ((int)($reservationRow['balance_paid'] ?? 0) === 1) ? 0.0 : $saldo;

    $stc = $pdo->prepare("SELECT COALESCE(SUM(total_price),0) FROM reservation_consumptions WHERE reservation_id = ?");
    $stc->execute([$reservationId]);
    $consumption = round((float)$stc->fetchColumn(), 2);
    $grand = round($saldoPendente + $consumption, 2);

    $audited = [
        'stay_total' => $saldoPendente,
        'consumption_total' => $consumption,
        'grand_total' => $grand,
        'stay_full_total' => $totalAmount,
    ];

    // Audit Check: compara valor vindo do frontend com cálculo autoritativo do backend.
    if (is_array($explicitSummary)) {
        $feStay = round((float)($explicitSummary['stay_total'] ?? 0), 2);
        $feConsumption = round((float)($explicitSummary['consumption_total'] ?? 0), 2);
        $feGrand = round((float)($explicitSummary['grand_total'] ?? ($feStay + $feConsumption)), 2);

        $diffStay = abs($audited['stay_total'] - $feStay);
        $diffConsumption = abs($audited['consumption_total'] - $feConsumption);
        $diffGrand = abs($audited['grand_total'] - $feGrand);

        $divergences = [];
        if ($diffStay > 0.01) {
            $divergences[] = 'Divergência isolada em Hospedagem: Front '
                . number_format($feStay, 2, '.', '')
                . ' vs Back ' . number_format($audited['stay_total'], 2, '.', '');
        }
        if ($diffConsumption > 0.01) {
            $divergences[] = 'Divergência isolada em Consumo: Front '
                . number_format($feConsumption, 2, '.', '')
                . ' vs Back ' . number_format($audited['consumption_total'], 2, '.', '');
        }
        if ($diffGrand > 0.01) {
            $divergences[] = 'Divergência isolada em Total: Front '
                . number_format($feGrand, 2, '.', '')
                . ' vs Back ' . number_format($audited['grand_total'], 2, '.', '');
        }

        if (count($divergences) > 0) {
            error_log('[CRITICAL][checkout_audit_mismatch] reservation_id=' . $reservationId
                . ' frontend_grand=' . number_format($feGrand, 2, '.', '')
                . ' backend_grand=' . number_format($audited['grand_total'], 2, '.', '')
                . ' frontend_stay=' . number_format($feStay, 2, '.', '')
                . ' backend_stay=' . number_format($audited['stay_total'], 2, '.', '')
                . ' frontend_consumption=' . number_format($feConsumption, 2, '.', '')
                . ' backend_consumption=' . number_format($audited['consumption_total'], 2, '.', '')
                . ' | details=' . implode(' || ', $divergences));
            throw new RuntimeException('Divergência financeira detectada no fechamento. Atualize a página e tente novamente.');
        }
    }

    return $audited;
}

switch ($method) {
    case 'GET':
        // Rotina autônoma: expira holds de pagamento vencidos.
        try {
            cleanupExpiredPendingReservations($pdo);
        }
        catch (Exception $e) {
            // Silencioso: não derrubar o GET se a rotina falhar.
        }



        // Busca reservas
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT r.*, c.name as chalet_name,
                COALESCE((SELECT SUM(rc.total_price) FROM reservation_consumptions rc WHERE rc.reservation_id = r.id), 0) AS total_consumed
                FROM reservations r
                LEFT JOIN chalets c ON r.chalet_id = c.id
                WHERE r.id = ?");
            $stmt->execute([$_GET['id']]);
            $reservation = $stmt->fetch();
            if ($reservation) {
                jsonResponse($reservation);
            }
            else {
                jsonResponse(['error' => 'Reserva não encontrada'], 404);
            }
        }
        else {
            $stmt = $pdo->query("SELECT r.*, c.name as chalet_name,
                COALESCE((SELECT SUM(rc.total_price) FROM reservation_consumptions rc WHERE rc.reservation_id = r.id), 0) AS total_consumed
                FROM reservations r
                LEFT JOIN chalets c ON r.chalet_id = c.id
                ORDER BY r.created_at DESC");
            $reservations = $stmt->fetchAll();
            jsonResponse($reservations);
        }
        break;

    case 'POST':
        // Cria nova reserva (Vindo do site Frontend)
        $data = json_decode(file_get_contents("php://input"), true);
        if (!is_array($data)) {
            jsonResponse(['error' => 'JSON inválido.'], 400);
        }

        // Validação básica
        $required_fields = ['guest_name', 'checkin_date', 'checkout_date'];
        $missing = [];
        foreach ($required_fields as $field) {
            if (!isset($data[$field])) {
                $missing[] = $field;
            }
        }

        if (!$data || !empty($missing)) {
            $err_msg = 'Dados obrigatórios ausentes: ' . implode(', ', $missing);
            jsonResponse(['error' => $err_msg], 400);
        }

        $chalet_id = null;

        // Se vier chalet_id direto (Admin)
        if (isset($data['chalet_id'])) {
            $chalet_id = $data['chalet_id'];
        }
        // Se vier chalet_name (Frontend Legado)
        elseif (isset($data['chalet_name'])) {
            $stmt_chale = $pdo->prepare("SELECT id FROM chalets WHERE name = ? LIMIT 1");
            $stmt_chale->execute([$data['chalet_name']]);
            $chalet = $stmt_chale->fetch();
            if ($chalet) {
                $chalet_id = $chalet['id'];
            }
        }

        if (!$chalet_id) {
            jsonResponse(['error' => 'Chalé não identificado (id ou name ausente/inválido)'], 400);
        }

        // Conversão das datas DD/MM/YYYY para YYYY-MM-DD
        function convertDate($dateStr)
        {
            $parts = explode('/', $dateStr);
            if (count($parts) == 3) {
                return $parts[2] . '-' . $parts[1] . '-' . $parts[0];
            }
            return $dateStr; // fallback caso já venha ISO
        }

        $checkin = convertDate($data['checkin_date']);
        $checkout = convertDate($data['checkout_date']);
        $requestedStatus = isset($data['status']) && trim((string)$data['status']) !== ''
            ? trim((string)$data['status'])
            : '';
        $isBlockingReservation = $requestedStatus === 'Bloqueado';

        if (!$isBlockingReservation) {
            $seasonalValidation = validateSeasonalMinNights($pdo, (int)$chalet_id, $checkin, $checkout);
            if ($seasonalValidation !== null) {
                jsonResponse($seasonalValidation, 422);
            }
        }

        assertNoReservationConflict($pdo, (int)$chalet_id, $checkin, $checkout);

        $stmtChalet = $pdo->prepare('SELECT * FROM chalets WHERE id = ? LIMIT 1');
        $stmtChalet->execute([$chalet_id]);
        $chaletRow = $stmtChalet->fetch(PDO::FETCH_ASSOC);
        if (!$chaletRow) {
            jsonResponse(['error' => 'Chalé não encontrado'], 400);
        }
        $stmtHol = $pdo->prepare('SELECT custom_date AS date, price, description AS descr FROM chalet_custom_prices WHERE chalet_id = ?');
        $stmtHol->execute([$chalet_id]);
        $chaletRow['holidays'] = $stmtHol->fetchAll(PDO::FETCH_ASSOC);

        $guestsAdults = isset($data['guests_adults']) ? (int) $data['guests_adults'] : 2;
        $guestsChildren = isset($data['guests_children']) ? (int) $data['guests_children'] : 0;
        if ($guestsAdults < 0) {
            $guestsAdults = 0;
        }
        if ($guestsChildren < 0) {
            $guestsChildren = 0;
        }
        if (!$isBlockingReservation) {
            assertReservationGuestCapacity($chaletRow, $guestsAdults, $guestsChildren);
        }
        $childrenAgesJson = reservationNormalizeChildrenAges($data['children_ages'] ?? null, $guestsChildren);
        $bringsPet = !empty($data['brings_pet']) || !empty($data['has_pet']);

        $additional_value = isset($data['additional_value']) ? (float) $data['additional_value'] : 0;
        $extraIds = be_parse_extra_service_ids_from_payload($data);
        $extraPack = be_extra_services_from_ids($pdo, $extraIds);
        $extrasTotal = $extraPack['total'];
        $extrasJson = json_encode($extraPack['lines'], JSON_UNESCAPED_UNICODE);
        $commercialTotals = $isBlockingReservation
            ? ['total' => 0.0, 'stay_discount_amount' => 0.0]
            : reservationCommercialTotals($pdo, $chaletRow, $checkin, $checkout, $guestsAdults, $guestsChildren, $bringsPet, $additional_value, $extrasTotal);

        $preCoupon = round((float)$commercialTotals['total'], 2);
        $couponInput = trim((string) ($data['coupon_code'] ?? ''));
        $couponRow = (!$isBlockingReservation && $couponInput !== '') ? be_find_active_coupon($pdo, $couponInput) : null;
        if (!$isBlockingReservation && $couponInput !== '' && !$couponRow) {
            jsonResponse(['error' => 'Cupom inválido ou expirado.'], 400);
        }
        $discountAmount = $couponRow ? be_compute_discount($preCoupon, $couponRow) : 0.0;
        $couponStored = $couponRow ? be_normalize_coupon_code($couponInput) : null;
        $total = max(0.0, round($preCoupon - $discountAmount, 2));

        $fnrhToken = bin2hex(random_bytes(16));

        $stmt = $pdo->prepare('INSERT INTO reservations (
            guest_name, guest_email, guest_phone, guests_adults, guests_children, children_ages, brings_pet, chalet_id, checkin_date, checkout_date,
            total_amount, additional_value, payment_rule, payment_method, status, balance_paid, balance_paid_at,
            coupon_code, discount_amount, extras_json, extras_total, fnrh_access_token, fnrh_data
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)');

        $email = $data['guest_email'] ?? null;
        $phone = $data['guest_phone'] ?? null;
        $payment_rule = $data['payment_rule'] ?? 'full';

        // Método de pagamento: apenas dois valores válidos. Padrão 'mercadopago' para retro-compatibilidade.
        $paymentMethodRaw = strtolower(trim((string)($data['payment_method'] ?? 'mercadopago')));
        $paymentMethod = $paymentMethodRaw === 'manual' ? 'manual' : 'mercadopago';

        // Status inicial depende do método:
        // - MP: 'Aguardando Pagamento' (entra hold com expires_at quando create_preference gera link)
        // - Manual: 'Pendente' (admin valida o comprovante manualmente)
        // Admin pode sempre sobrescrever passando 'status' no payload.
        if ($requestedStatus !== '') {
            $status = $requestedStatus;
        } else {
            $status = $paymentMethod === 'manual' ? 'Pendente' : 'Aguardando Pagamento';
        }

        $balancePaid = isset($data['balance_paid'])
            ? (int)((bool)$data['balance_paid'])
            : (($payment_rule === 'full' && $status === 'Confirmada') ? 1 : 0);
        $balancePaidAt = null;
        if ($balancePaid && $payment_rule === 'full' && $status === 'Confirmada') {
            $balancePaidAt = date('Y-m-d H:i:s');
        }

        try {
            $pdo->beginTransaction();
            $lockChalet = $pdo->prepare('SELECT id FROM chalets WHERE id = ? FOR UPDATE');
            $lockChalet->execute([$chalet_id]);
            assertNoReservationConflict($pdo, (int)$chalet_id, $checkin, $checkout);
            $ok = $stmt->execute([
                $data['guest_name'],
                $email,
                $phone,
                $guestsAdults,
                $guestsChildren,
                $childrenAgesJson,
                $bringsPet ? 1 : 0,
                $chalet_id,
                $checkin,
                $checkout,
                $total,
                $additional_value,
                $payment_rule,
                $paymentMethod,
                $status,
                $balancePaid,
                $balancePaidAt,
                $couponStored,
                $discountAmount,
                $extrasJson,
                $extrasTotal,
                $fnrhToken,
            ]);
            if (!$ok) {
                throw new RuntimeException('Falha ao salvar reserva');
            }
            $newId = (int)$pdo->lastInsertId();
            $pdo->commit();

            try {
                $eventRes = [
                    'id' => $newId,
                    'guest_name' => (string) ($data['guest_name'] ?? ''),
                    'guest_phone' => (string) ($phone ?? ''),
                    'chalet_name' => (string) ($chaletRow['name'] ?? ''),
                    'checkin_date' => (string) $checkin,
                    'checkout_date' => (string) $checkout,
                    'total_amount' => (float) $total,
                    'payment_method' => (string) $paymentMethod,
                    'payment_rule' => (string) $payment_rule,
                    'fnrh_access_token' => (string) $fnrhToken,
                ];
                if ($status !== 'Bloqueado') {
                    $notifyEvent = $paymentMethod === 'manual' ? 'reserva_pendente' : 'reserva';
                    evo_notify_event($pdo, $eventRes, $notifyEvent);
                }
            } catch (Throwable $e) {
                error_log('[reservations] evo reserva notify fail: ' . $e->getMessage());
            }
            jsonResponse([
                'status' => 'success',
                'message' => 'Reserva criada com sucesso',
                'id' => $newId
            ], 201);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['error' => 'Falha ao salvar reserva'], 500);
        }
        break;

    case 'PUT':
        // Atualiza reserva (Admin)
        $data = json_decode(file_get_contents("php://input"), true);
        if (!is_array($data)) {
            jsonResponse(['error' => 'JSON inválido.'], 400);
        }
        if (!isset($_GET['id'])) {
            jsonResponse(['error' => 'ID é obrigatório'], 400);
        }

        if (isset($data['guest_name']) && isset($data['chalet_id'])) {
            // Edição completa
            $reservationId = (int)$_GET['id'];
            $st = $data['status'] ?? 'Confirmada';
            $isBlockingReservation = $st === 'Bloqueado';
            if (!$isBlockingReservation) {
                $seasonalValidation = validateSeasonalMinNights($pdo, (int)$data['chalet_id'], (string)$data['checkin_date'], (string)$data['checkout_date']);
                if ($seasonalValidation !== null) {
                    jsonResponse($seasonalValidation, 422);
                }
            }
            assertNoReservationConflict($pdo, (int)$data['chalet_id'], (string)$data['checkin_date'], (string)$data['checkout_date'], $reservationId);
            $guestsAdults = isset($data['guests_adults']) ? (int)$data['guests_adults'] : 2;
            $guestsChildren = isset($data['guests_children']) ? (int)$data['guests_children'] : 0;
            if ($guestsAdults < 0) $guestsAdults = 0;
            if ($guestsChildren < 0) $guestsChildren = 0;
            $stmtCap = $pdo->prepare("SELECT * FROM chalets WHERE id = ? LIMIT 1");
            $stmtCap->execute([$data['chalet_id']]);
            $chaletCapRow = $stmtCap->fetch(PDO::FETCH_ASSOC);
            if (!$chaletCapRow) {
                jsonResponse(['error' => 'Chalé não encontrado'], 400);
            }
            if (!$isBlockingReservation) {
                assertReservationGuestCapacity($chaletCapRow, $guestsAdults, $guestsChildren);
            }
            $childrenAgesJson = reservationNormalizeChildrenAges($data['children_ages'] ?? null, $guestsChildren);
            $bringsPet = !empty($data['brings_pet']) || !empty($data['has_pet']);
            $stmtChaletPrice = $pdo->prepare('SELECT * FROM chalets WHERE id = ? LIMIT 1');
            $stmtChaletPrice->execute([$data['chalet_id']]);
            $chaletPriceRow = $stmtChaletPrice->fetch(PDO::FETCH_ASSOC);
            if (!$chaletPriceRow) {
                jsonResponse(['error' => 'Chalé não encontrado'], 400);
            }
            $stmtHol = $pdo->prepare('SELECT custom_date AS date, price, description AS descr FROM chalet_custom_prices WHERE chalet_id = ?');
            $stmtHol->execute([$data['chalet_id']]);
            $chaletPriceRow['holidays'] = $stmtHol->fetchAll(PDO::FETCH_ASSOC);
            $additional_value = isset($data['additional_value']) ? (float) $data['additional_value'] : 0;
            $authoritativeTotal = $isBlockingReservation
                ? 0.0
                : (float)reservationCommercialTotals(
                    $pdo,
                    $chaletPriceRow,
                    (string)$data['checkin_date'],
                    (string)$data['checkout_date'],
                    $guestsAdults,
                    $guestsChildren,
                    $bringsPet,
                    $additional_value,
                    (float)($data['extras_total'] ?? 0)
                )['total'];
            $stmt = $pdo->prepare("UPDATE reservations SET guest_name = ?, guest_email = ?, guest_phone = ?, guest_cpf = ?, guest_address = ?, guest_car_plate = ?, guest_companion_names = ?, guests_adults = ?, guests_children = ?, children_ages = ?, brings_pet = ?, chalet_id = ?, checkin_date = ?, checkout_date = ?, total_amount = ?, additional_value = ?, payment_rule = ?, status = ?, balance_paid = ?, balance_paid_at = ? WHERE id = ?");
            $pr = $data['payment_rule'] ?? 'full';

            // Lê estado anterior para decidir transição do saldo.
            $stmtPrev = $pdo->prepare("SELECT balance_paid, balance_paid_at, status, guest_phone, guest_name, checkin_date, checkout_date, fnrh_access_token FROM reservations WHERE id = ? LIMIT 1");
            $stmtPrev->execute([$_GET['id']]);
            $prevRow = $stmtPrev->fetch();
            $prevBalancePaid = (int)($prevRow['balance_paid'] ?? 0);
            $prevAt = $prevRow['balance_paid_at'] ?? null;
            $prevStatus = (string)($prevRow['status'] ?? '');

            // Se o frontend enviou balance_paid explicitamente, respeita-o.
            // Caso contrário, mantém o estado anterior (evita zerar por omissão).
            if (array_key_exists('balance_paid', $data)) {
                $balancePaid = (int)((bool)$data['balance_paid']);
            } else {
                $balancePaid = $prevBalancePaid;
            }

            // Regra independente da política: qualquer transição 0→1 grava NOW.
            // Permanência em 1 preserva o timestamp original. 1→0 limpa.
            if ($balancePaid === 1) {
                $balancePaidAt = $prevBalancePaid === 0 ? date('Y-m-d H:i:s') : ($prevAt ?: date('Y-m-d H:i:s'));
            } else {
                $balancePaidAt = null;
            }
            if ($stmt->execute([
            $data['guest_name'],
            $data['guest_email'] ?? null,
            $data['guest_phone'] ?? null,
            isset($data['guest_cpf']) ? preg_replace('/\D/', '', (string) $data['guest_cpf']) : null,
            $data['guest_address'] ?? null,
            $data['guest_car_plate'] ?? null,
            $data['guest_companion_names'] ?? null,
            $guestsAdults,
            $guestsChildren,
            $childrenAgesJson,
            $bringsPet ? 1 : 0,
            $data['chalet_id'],
            $data['checkin_date'],
            $data['checkout_date'],
            $authoritativeTotal,
            $additional_value,
            $pr,
            $st,
            $balancePaid,
            $balancePaidAt,
            $_GET['id']
            ])) {
                try {
                    $newStatus = (string) $st;
                    if ($newStatus !== $prevStatus && in_array($newStatus, ['Hospedado', 'Finalizada'], true)) {
                        $evt = $newStatus === 'Hospedado' ? 'checkin' : 'checkout';
                        $checkoutSummary = $evt === 'checkout'
                            ? buildCheckoutSummaryForNotification($pdo, (int)$_GET['id'], [
                                'total_amount' => $data['total_amount'] ?? 0,
                                'payment_rule' => $data['payment_rule'] ?? ($prevRow['payment_rule'] ?? 'full'),
                                'balance_paid' => $prevBalancePaid
                            ], $data['checkout_summary'] ?? null)
                            : null;
                        if ($newStatus === 'Finalizada' && $balancePaid !== 1) {
                            $balancePaid = 1;
                            $balancePaidAt = $balancePaidAt ?: date('Y-m-d H:i:s');
                            $forcePaidStmt = $pdo->prepare("UPDATE reservations SET balance_paid = 1, balance_paid_at = COALESCE(balance_paid_at, NOW()) WHERE id = ?");
                            $forcePaidStmt->execute([$_GET['id']]);
                        }
                        $eventRes = [
                            'id' => (int) $_GET['id'],
                            'guest_name' => (string) ($data['guest_name'] ?? ($prevRow['guest_name'] ?? '')),
                            'guest_phone' => (string) ($data['guest_phone'] ?? ($prevRow['guest_phone'] ?? '')),
                            'checkin_date' => (string) ($data['checkin_date'] ?? ($prevRow['checkin_date'] ?? '')),
                            'checkout_date' => (string) ($data['checkout_date'] ?? ($prevRow['checkout_date'] ?? '')),
                            'fnrh_access_token' => (string) ($prevRow['fnrh_access_token'] ?? ''),
                        ];
                        if (is_array($checkoutSummary)) {
                            $eventRes['stay_total'] = $checkoutSummary['stay_total'];
                            $eventRes['consumption_total'] = $checkoutSummary['consumption_total'];
                            $eventRes['grand_total'] = $checkoutSummary['grand_total'];
                            $eventRes['stay_full_total'] = $checkoutSummary['stay_full_total'];
                        }
                        evo_notify_event($pdo, $eventRes, $evt);
                    }
                } catch (Throwable $e) {
                    if (stripos((string)$e->getMessage(), 'Divergência financeira detectada no fechamento') !== false) {
                        jsonResponse(['error' => $e->getMessage()], 409);
                    }
                    error_log('[reservations] evo status notify fail: ' . $e->getMessage());
                }
                jsonResponse(['status' => 'success']);
            }
        }
        elseif (
            array_key_exists('guest_cpf', $data) ||
            array_key_exists('guest_address', $data) ||
            array_key_exists('guest_car_plate', $data) ||
            array_key_exists('guest_companion_names', $data) ||
            array_key_exists('fnrh_status', $data) ||
            (array_key_exists('guest_phone', $data) && !isset($data['guest_name']))
        ) {
            // Atualização parcial de dados de check-in/FNRH (Fase 2).
            $allowed = [
                'guest_phone', 'guest_cpf', 'guest_address', 'guest_car_plate',
                'guest_companion_names', 'fnrh_status', 'fnrh_submitted_at',
                'fnrh_last_response', 'status'
            ];
            $fields = [];
            $params = [];
            foreach ($allowed as $col) {
                if (array_key_exists($col, $data)) {
                    $fields[] = "$col = ?";
                    $v = $data[$col];
                    if ($col === 'guest_cpf' && is_string($v)) {
                        $v = preg_replace('/\D/', '', $v);
                    }
                    $params[] = is_string($v) ? trim($v) : $v;
                }
            }
            if (empty($fields)) {
                jsonResponse(['error' => 'Nenhum campo válido para atualizar'], 400);
            }
            $params[] = $_GET['id'];
            $stmt = $pdo->prepare("UPDATE reservations SET " . implode(', ', $fields) . " WHERE id = ?");
            if ($stmt->execute($params)) {
                jsonResponse(['status' => 'success']);
            }
            jsonResponse(['error' => 'Falha ao atualizar check-in'], 500);
        }
        elseif (isset($data['status'])) {
            // Edição só de status (via select rápido da tabela)
            $stPrev = $pdo->prepare("SELECT id, status, guest_name, guest_phone, checkin_date, checkout_date, fnrh_access_token, payment_rule, total_amount, balance_paid FROM reservations WHERE id = ? LIMIT 1");
            $stPrev->execute([$_GET['id']]);
            $prev = $stPrev->fetch();
            $stmt = $pdo->prepare("UPDATE reservations SET status = ? WHERE id = ?");
            if ($stmt->execute([$data['status'], $_GET['id']])) {
                try {
                    $newStatus = (string) $data['status'];
                    $oldStatus = (string) ($prev['status'] ?? '');
                    if ($newStatus !== $oldStatus && in_array($newStatus, ['Hospedado', 'Finalizada'], true)) {
                        $evt = $newStatus === 'Hospedado' ? 'checkin' : 'checkout';
                        $checkoutSummary = $evt === 'checkout'
                            ? buildCheckoutSummaryForNotification($pdo, (int)($_GET['id'] ?? 0), $prev ?: [], $data['checkout_summary'] ?? null)
                            : null;
                        if ($newStatus === 'Finalizada') {
                            $pdo->prepare("UPDATE reservations SET balance_paid = 1, balance_paid_at = COALESCE(balance_paid_at, NOW()) WHERE id = ?")
                                ->execute([$_GET['id']]);
                        }
                        evo_notify_event($pdo, [
                            'id' => (int) ($_GET['id'] ?? 0),
                            'guest_name' => (string) ($prev['guest_name'] ?? ''),
                            'guest_phone' => (string) ($prev['guest_phone'] ?? ''),
                            'checkin_date' => (string) ($prev['checkin_date'] ?? ''),
                            'checkout_date' => (string) ($prev['checkout_date'] ?? ''),
                            'fnrh_access_token' => (string) ($prev['fnrh_access_token'] ?? ''),
                            'stay_total' => is_array($checkoutSummary) ? $checkoutSummary['stay_total'] : null,
                            'consumption_total' => is_array($checkoutSummary) ? $checkoutSummary['consumption_total'] : null,
                            'grand_total' => is_array($checkoutSummary) ? $checkoutSummary['grand_total'] : null,
                            'stay_full_total' => is_array($checkoutSummary) ? $checkoutSummary['stay_full_total'] : null,
                        ], $evt);
                    }
                } catch (Throwable $e) {
                    if (stripos((string)$e->getMessage(), 'Divergência financeira detectada no fechamento') !== false) {
                        jsonResponse(['error' => $e->getMessage()], 409);
                    }
                    error_log('[reservations] evo status-only notify fail: ' . $e->getMessage());
                }
                jsonResponse(['status' => 'success']);
            }
        }

        jsonResponse(['error' => 'Erro ao atualizar dados ou payload inválido'], 500);
        break;


    case 'DELETE':
        // Exclui uma reserva
        $id = isset($_GET['id']) ? intval($_GET['id']) : null;
        if (!$id) {
            jsonResponse(['error' => 'ID da reserva não fornecido'], 400);
        }

        $stmt = $pdo->prepare("DELETE FROM reservations WHERE id = ?");
        if ($stmt->execute([$id])) {
            jsonResponse(['status' => 'success', 'message' => 'Reserva excluída com sucesso']);
        }
        else {
            jsonResponse(['error' => 'Erro ao excluir reserva'], 500);
        }
        break;

    default:
        jsonResponse(['error' => 'Método não permitido'], 405);
        break;
}
?>
