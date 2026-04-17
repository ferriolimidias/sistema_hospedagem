<?php
require_once 'db.php';

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
            $stmt = $pdo->prepare("SELECT r.*, c.name as chalet_name FROM reservations r LEFT JOIN chalets c ON r.chalet_id = c.id WHERE r.id = ?");
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
            $stmt = $pdo->query("SELECT r.*, c.name as chalet_name FROM reservations r LEFT JOIN chalets c ON r.chalet_id = c.id ORDER BY r.created_at DESC");
            $reservations = $stmt->fetchAll();
            jsonResponse($reservations);
        }
        break;

    case 'POST':
        // Cria nova reserva (Vindo do site Frontend)
        $data = json_decode(file_get_contents("php://input"), true);

        // Validação básica
        $required_fields = ['guest_name', 'checkin_date', 'checkout_date', 'total_amount'];
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

        // Tratamento do valor (pode vir "R$ 800,00" ou 800.00 numérico)
        if (is_numeric($data['total_amount'])) {
            $total = floatval($data['total_amount']);
        }
        else {
            $totalText = str_replace(['R$', ' ', '.'], '', $data['total_amount']);
            $totalText = str_replace(',', '.', $totalText);
            $total = floatval($totalText);
        }

        $stmt = $pdo->prepare("INSERT INTO reservations (guest_name, guest_email, guest_phone, guests_adults, guests_children, chalet_id, checkin_date, checkout_date, total_amount, payment_rule, status, balance_paid, balance_paid_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $email = $data['guest_email'] ?? null;
        $phone = $data['guest_phone'] ?? null;
        $guestsAdults = isset($data['guests_adults']) ? (int)$data['guests_adults'] : 2;
        $guestsChildren = isset($data['guests_children']) ? (int)$data['guests_children'] : 0;
        $status = $data['status'] ?? 'Confirmada';
        $payment_rule = $data['payment_rule'] ?? 'full';
        $balancePaid = isset($data['balance_paid'])
            ? (int)((bool)$data['balance_paid'])
            : (($payment_rule === 'full' && $status === 'Confirmada') ? 1 : 0);
        $balancePaidAt = null;
        if ($balancePaid && $payment_rule === 'full' && $status === 'Confirmada') {
            $balancePaidAt = date('Y-m-d H:i:s');
        }

        if ($stmt->execute([$data['guest_name'], $email, $phone, $guestsAdults, $guestsChildren, $chalet_id, $checkin, $checkout, $total, $payment_rule, $status, $balancePaid, $balancePaidAt])) {
            jsonResponse([
                'status' => 'success',
                'message' => 'Reserva criada com sucesso',
                'id' => $pdo->lastInsertId()
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
            $stmt = $pdo->prepare("UPDATE reservations SET guest_name = ?, guest_email = ?, guest_phone = ?, guests_adults = ?, guests_children = ?, chalet_id = ?, checkin_date = ?, checkout_date = ?, total_amount = ?, payment_rule = ?, status = ?, balance_paid = ?, balance_paid_at = ? WHERE id = ?");
            $balancePaid = isset($data['balance_paid'])
                ? (int)((bool)$data['balance_paid'])
                : ((($data['payment_rule'] ?? 'full') === 'full' && ($data['status'] ?? 'Confirmada') === 'Confirmada') ? 1 : 0);
            $pr = $data['payment_rule'] ?? 'full';
            $st = $data['status'] ?? 'Confirmada';
            $stmtPrev = $pdo->prepare("SELECT balance_paid_at FROM reservations WHERE id = ? LIMIT 1");
            $stmtPrev->execute([$_GET['id']]);
            $prevRow = $stmtPrev->fetch();
            $prevAt = $prevRow['balance_paid_at'] ?? null;
            $balancePaidAt = $prevAt;
            if ($balancePaid && $pr === 'full' && $st === 'Confirmada') {
                $balancePaidAt = $prevAt ?: date('Y-m-d H:i:s');
            } elseif (!$balancePaid) {
                $balancePaidAt = null;
            }
            if ($stmt->execute([
            $data['guest_name'],
            $data['guest_email'] ?? null,
            $data['guest_phone'] ?? null,
            $guestsAdults,
            $guestsChildren,
            $data['chalet_id'],
            $data['checkin_date'],
            $data['checkout_date'],
            $data['total_amount'],
            $pr,
            $st,
            $balancePaid,
            $balancePaidAt,
            $_GET['id']
            ])) {
                jsonResponse(['status' => 'success']);
            }
        }
        elseif (isset($data['status'])) {
            // Edição só de status (via select rápido da tabela)
            $stmt = $pdo->prepare("UPDATE reservations SET status = ? WHERE id = ?");
            if ($stmt->execute([$data['status'], $_GET['id']])) {
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
