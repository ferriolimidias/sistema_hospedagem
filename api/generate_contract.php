<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/contract_service.php';
require_once __DIR__ . '/evolution_service.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

be_require_admin_auth($pdo);

$input = json_decode(file_get_contents('php://input'), true);
$reservationId = isset($input['reservation_id']) ? (int)$input['reservation_id'] : 0;
if ($reservationId <= 0) {
    jsonResponse(['error' => 'reservation_id é obrigatório'], 400);
}

try {
    $result = generateContractForReservation($pdo, $reservationId);
    $notify = ['attempted' => false, 'ok' => false];
    try {
        $stmt = $pdo->prepare('SELECT guest_phone, guest_name FROM reservations WHERE id = ? LIMIT 1');
        $stmt->execute([$reservationId]);
        $resv = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $guestPhone = trim((string)($resv['guest_phone'] ?? ''));
        $guestName = trim((string)($resv['guest_name'] ?? 'Hóspede'));
        $contractPath = (string)($result['path'] ?? '');
        if ($guestPhone !== '' && $contractPath !== '' && is_file($contractPath)) {
            $bytes = @file_get_contents($contractPath);
            if (is_string($bytes) && $bytes !== '') {
                $base64 = base64_encode($bytes);
                $fileName = (string)($result['filename'] ?? ('contrato_reserva_' . $reservationId . '.pdf'));
                $caption = 'Olá, ' . $guestName . '! Segue em anexo o seu contrato de hospedagem.';
                $mediaResult = evo_send_media($pdo, $guestPhone, $base64, $fileName, 'application/pdf', $caption);
                if (!empty($mediaResult['ok'])) {
                    $upd = $pdo->prepare('UPDATE reservations SET last_contract_sent_at = NOW() WHERE id = ?');
                    $upd->execute([$reservationId]);
                }
                $notify = ['attempted' => true, 'ok' => !empty($mediaResult['ok']), 'result' => $mediaResult];
            } else {
                $notify = ['attempted' => true, 'ok' => false, 'error' => 'Falha ao ler PDF do contrato para envio'];
            }
        }
    } catch (Throwable $notifyErr) {
        error_log('[generate_contract] falha envio WhatsApp contrato: ' . $notifyErr->getMessage());
        $notify = ['attempted' => true, 'ok' => false, 'error' => $notifyErr->getMessage()];
    }
    jsonResponse([
        'success' => true,
        'reservation_id' => $result['reservation_id'],
        'contract_filename' => $result['filename'],
        'whatsapp_send' => $notify
    ]);
} catch (Throwable $e) {
    jsonResponse(['error' => 'Falha ao gerar contrato', 'details' => $e->getMessage()], 500);
}

