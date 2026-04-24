<?php
require_once 'db.php';
require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/booking_extras.php';
require_once __DIR__ . '/evolution_service.php';

$method = $_SERVER['REQUEST_METHOD'];

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

        // Verifica sobreposição com reservas confirmadas ou com hold ativo.
        $stmt_conflict = $pdo->prepare("
            SELECT id
            FROM reservations
            WHERE chalet_id = ?
              AND checkin_date < ?
              AND checkout_date > ?
              AND (
                    status = 'Confirmada'
                    OR (status = 'Aguardando Pagamento' AND expires_at IS NOT NULL AND expires_at > NOW())
                  )
            LIMIT 1
        ");
        $stmt_conflict->execute([$chalet_id, $checkout, $checkin]);
        if ($stmt_conflict->fetch()) {
            jsonResponse(['error' => 'Período indisponível para este chalé (reserva já confirmada ou em hold).'], 409);
        }

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
        $totalGuests = $guestsAdults + $guestsChildren;
        $maxGuests = isset($chaletRow['max_guests']) ? (int) $chaletRow['max_guests'] : 4;
        if ($maxGuests < 1) {
            $maxGuests = 1;
        }
        if ($totalGuests > $maxGuests) {
            jsonResponse([
                'error' => 'Quantidade de hóspedes excede a capacidade máxima do chalé.',
                'max_guests' => $maxGuests,
                'requested_guests' => $totalGuests
            ], 400);
        }

        $lodgingTotal = pricing_reservation_total($chaletRow, $checkin, $checkout, $guestsAdults, $guestsChildren);
        $extraIds = be_parse_extra_service_ids_from_payload($data);
        $extraPack = be_extra_services_from_ids($pdo, $extraIds);
        $extrasTotal = $extraPack['total'];
        $extrasJson = json_encode($extraPack['lines'], JSON_UNESCAPED_UNICODE);

        $preCoupon = round($lodgingTotal + $extrasTotal, 2);
        $couponInput = trim((string) ($data['coupon_code'] ?? ''));
        $couponRow = $couponInput !== '' ? be_find_active_coupon($pdo, $couponInput) : null;
        if ($couponInput !== '' && !$couponRow) {
            jsonResponse(['error' => 'Cupom inválido ou expirado.'], 400);
        }
        $discountAmount = $couponRow ? be_compute_discount($preCoupon, $couponRow) : 0.0;
        $couponStored = $couponRow ? be_normalize_coupon_code($couponInput) : null;
        $total = max(0.0, round($preCoupon - $discountAmount, 2));

        $fnrhToken = bin2hex(random_bytes(16));

        $stmt = $pdo->prepare('INSERT INTO reservations (
            guest_name, guest_email, guest_phone, guests_adults, guests_children, chalet_id, checkin_date, checkout_date,
            total_amount, additional_value, payment_rule, payment_method, status, balance_paid, balance_paid_at,
            coupon_code, discount_amount, extras_json, extras_total, fnrh_access_token, fnrh_data
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)');

        $email = $data['guest_email'] ?? null;
        $phone = $data['guest_phone'] ?? null;
        $payment_rule = $data['payment_rule'] ?? 'full';
        $additional_value = isset($data['additional_value']) ? (float) $data['additional_value'] : 0;

        // Método de pagamento: apenas dois valores válidos. Padrão 'mercadopago' para retro-compatibilidade.
        $paymentMethodRaw = strtolower(trim((string)($data['payment_method'] ?? 'mercadopago')));
        $paymentMethod = $paymentMethodRaw === 'manual' ? 'manual' : 'mercadopago';

        // Status inicial depende do método:
        // - MP: 'Aguardando Pagamento' (entra hold com expires_at quando create_preference gera link)
        // - Manual: 'Pendente' (admin valida o comprovante manualmente)
        // Admin pode sempre sobrescrever passando 'status' no payload.
        if (isset($data['status']) && trim((string)$data['status']) !== '') {
            $status = (string)$data['status'];
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

        if ($stmt->execute([
            $data['guest_name'],
            $email,
            $phone,
            $guestsAdults,
            $guestsChildren,
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
        ])) {
            $newId = (int) $pdo->lastInsertId();
            try {
                $eventRes = [
                    'id' => $newId,
                    'guest_name' => (string) ($data['guest_name'] ?? ''),
                    'guest_phone' => (string) ($phone ?? ''),
                    'checkin_date' => (string) $checkin,
                    'checkout_date' => (string) $checkout,
                    'fnrh_access_token' => (string) $fnrhToken,
                ];
                evo_notify_event($pdo, $eventRes, 'reserva');
            } catch (Throwable $e) {
                error_log('[reservations] evo reserva notify fail: ' . $e->getMessage());
            }
            jsonResponse([
                'status' => 'success',
                'message' => 'Reserva criada com sucesso',
                'id' => $newId
            ], 201);
        }
        else {
            jsonResponse(['error' => 'Falha ao salvar reserva'], 500);
        }
        break;

    case 'PUT':
        // Atualiza reserva (Admin)
        $data = json_decode(file_get_contents("php://input"), true);
        if (!isset($_GET['id'])) {
            jsonResponse(['error' => 'ID é obrigatório'], 400);
        }

        if (isset($data['guest_name']) && isset($data['chalet_id'])) {
            // Edição completa
            $guestsAdults = isset($data['guests_adults']) ? (int)$data['guests_adults'] : 2;
            $guestsChildren = isset($data['guests_children']) ? (int)$data['guests_children'] : 0;
            $totalGuests = max(0, $guestsAdults) + max(0, $guestsChildren);
            $stmtCap = $pdo->prepare("SELECT max_guests FROM chalets WHERE id = ? LIMIT 1");
            $stmtCap->execute([$data['chalet_id']]);
            $maxGuests = (int) ($stmtCap->fetchColumn() ?: 4);
            if ($maxGuests < 1) {
                $maxGuests = 1;
            }
            if ($totalGuests > $maxGuests) {
                jsonResponse([
                    'error' => 'Quantidade de hóspedes excede a capacidade máxima do chalé.',
                    'max_guests' => $maxGuests,
                    'requested_guests' => $totalGuests
                ], 400);
            }
            $stmt = $pdo->prepare("UPDATE reservations SET guest_name = ?, guest_email = ?, guest_phone = ?, guest_cpf = ?, guest_address = ?, guest_car_plate = ?, guest_companion_names = ?, guests_adults = ?, guests_children = ?, chalet_id = ?, checkin_date = ?, checkout_date = ?, total_amount = ?, additional_value = ?, payment_rule = ?, status = ?, balance_paid = ?, balance_paid_at = ? WHERE id = ?");
            $pr = $data['payment_rule'] ?? 'full';
            $st = $data['status'] ?? 'Confirmada';
            $additional_value = isset($data['additional_value']) ? (float) $data['additional_value'] : 0;

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
            $data['chalet_id'],
            $data['checkin_date'],
            $data['checkout_date'],
            $data['total_amount'],
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
                        $eventRes = [
                            'id' => (int) $_GET['id'],
                            'guest_name' => (string) ($data['guest_name'] ?? ($prevRow['guest_name'] ?? '')),
                            'guest_phone' => (string) ($data['guest_phone'] ?? ($prevRow['guest_phone'] ?? '')),
                            'checkin_date' => (string) ($data['checkin_date'] ?? ($prevRow['checkin_date'] ?? '')),
                            'checkout_date' => (string) ($data['checkout_date'] ?? ($prevRow['checkout_date'] ?? '')),
                            'fnrh_access_token' => (string) ($prevRow['fnrh_access_token'] ?? ''),
                        ];
                        evo_notify_event($pdo, $eventRes, $evt);
                    }
                } catch (Throwable $e) {
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
            $stPrev = $pdo->prepare("SELECT id, status, guest_name, guest_phone, checkin_date, checkout_date, fnrh_access_token FROM reservations WHERE id = ? LIMIT 1");
            $stPrev->execute([$_GET['id']]);
            $prev = $stPrev->fetch();
            $stmt = $pdo->prepare("UPDATE reservations SET status = ? WHERE id = ?");
            if ($stmt->execute([$data['status'], $_GET['id']])) {
                try {
                    $newStatus = (string) $data['status'];
                    $oldStatus = (string) ($prev['status'] ?? '');
                    if ($newStatus !== $oldStatus && in_array($newStatus, ['Hospedado', 'Finalizada'], true)) {
                        $evt = $newStatus === 'Hospedado' ? 'checkin' : 'checkout';
                        evo_notify_event($pdo, [
                            'id' => (int) ($_GET['id'] ?? 0),
                            'guest_name' => (string) ($prev['guest_name'] ?? ''),
                            'guest_phone' => (string) ($prev['guest_phone'] ?? ''),
                            'checkin_date' => (string) ($prev['checkin_date'] ?? ''),
                            'checkout_date' => (string) ($prev['checkout_date'] ?? ''),
                            'fnrh_access_token' => (string) ($prev['fnrh_access_token'] ?? ''),
                        ], $evt);
                    }
                } catch (Throwable $e) {
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
