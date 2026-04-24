<?php
declare(strict_types=1);

/**
 * Serviço de integração com a FNRH Digital (Serpro / Ministério do Turismo).
 *
 * Uso como biblioteca:
 *     require_once __DIR__ . '/fnrh_service.php';
 *     $result = fnrh_send_reservation($pdo, (int)$reservationId);
 *
 * Uso como endpoint REST (POST):
 *     POST /api/fnrh_service.php?id=123
 *     Header: X-Internal-Key: <chave interna admin>
 *
 * Comportamento:
 *  - Se settings.fnrh_active == 0, NÃO chama a API externa. Apenas grava
 *    fnrh_status = 'nao_enviado' e devolve success=true (modo "desligado").
 *  - Se ativo e fnrh_api_key preenchido, dispara cURL para a API do governo.
 *    Grava fnrh_status = 'enviado' em caso de sucesso, 'erro' em caso contrário.
 *  - Nunca altera o status da reserva (quem decide 'Hospedado' é o admin).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking_extras.php';

/**
 * Endpoint de referência (FNRH Digital - Serpro).
 * Em produção, este valor deve vir das configurações do cliente ou de uma
 * constante/ambiente, pois pode mudar entre sandbox e produção.
 */
if (!defined('FNRH_API_ENDPOINT')) {
    define('FNRH_API_ENDPOINT', 'https://api.serpro.gov.br/fnrh/v1/hospedes');
}

/**
 * Lê uma chave de settings de forma tolerante (JSON ou string crua).
 */
function fnrh_setting(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        if (!is_string($v) || $v === '') return $default;
        $dec = json_decode($v, true);
        if (json_last_error() === JSON_ERROR_NONE && is_string($dec)) return $dec;
        return $v;
    } catch (Throwable $e) {
        return $default;
    }
}

function fnrh_is_active(PDO $pdo): bool
{
    $v = trim(fnrh_setting($pdo, 'fnrh_active', '0'));
    return $v === '1' || strtolower($v) === 'true';
}

/**
 * Marca o resultado no banco de forma idempotente.
 */
function fnrh_persist_status(PDO $pdo, int $reservationId, string $status, ?string $response = null): void
{
    try {
        $sql = 'UPDATE reservations SET fnrh_status = ?';
        $params = [$status];
        if ($response !== null) {
            $sql .= ', fnrh_last_response = ?';
            $params[] = mb_substr($response, 0, 8000);
        }
        if ($status === 'enviado') {
            $sql .= ', fnrh_submitted_at = NOW()';
        }
        $sql .= ' WHERE id = ?';
        $params[] = $reservationId;
        $pdo->prepare($sql)->execute($params);
    } catch (Throwable $e) {
        error_log('[fnrh] persist_status failed: ' . $e->getMessage());
    }
}

/**
 * Monta o payload enviado à API do governo.
 * Estrutura conservadora; ajuste conforme o contrato real da FNRH quando
 * o cliente tiver a credencial do Serpro.
 */
function fnrh_build_payload(array $reservation): array
{
    $cpf = preg_replace('/\D/', '', (string) ($reservation['guest_cpf'] ?? '')) ?? '';
    return [
        'meio_hospedagem_cnpj' => (string) ($reservation['meio_hospedagem_cnpj'] ?? ''),
        'hospede' => [
            'nome' => (string) ($reservation['guest_name'] ?? ''),
            'cpf' => $cpf,
            'telefone' => (string) ($reservation['guest_phone'] ?? ''),
            'email' => (string) ($reservation['guest_email'] ?? ''),
            'endereco' => (string) ($reservation['guest_address'] ?? ''),
            'placa_veiculo' => (string) ($reservation['guest_car_plate'] ?? ''),
            'acompanhantes' => (string) ($reservation['guest_companion_names'] ?? ''),
        ],
        'estadia' => [
            'checkin' => (string) ($reservation['checkin_date'] ?? ''),
            'checkout' => (string) ($reservation['checkout_date'] ?? ''),
            'adultos' => (int) ($reservation['guests_adults'] ?? 0),
            'criancas' => (int) ($reservation['guests_children'] ?? 0),
        ],
        'reserva_id' => (int) ($reservation['id'] ?? 0),
    ];
}

/**
 * Envio real (cURL) à API FNRH. Isolado para facilitar testes/mocks.
 * Retorna ['ok' => bool, 'http_code' => int, 'body' => string, 'error' => string].
 */
function fnrh_http_dispatch(array $payload, string $apiKey): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'cURL indisponível no PHP.'];
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => FNRH_API_ENDPOINT,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'ok' => ($err === '' && $code >= 200 && $code < 300),
        'http_code' => $code,
        'body' => is_string($body) ? $body : '',
        'error' => $err,
    ];
}

/**
 * Fluxo principal. Decide entre modo desligado x envio real.
 *
 * @return array{success: bool, mode: string, status: string, message: string, http_code?: int}
 */
function fnrh_send_reservation(PDO $pdo, int $reservationId): array
{
    if ($reservationId <= 0) {
        return ['success' => false, 'mode' => 'error', 'status' => 'erro', 'message' => 'ID inválido.'];
    }

    try {
        $st = $pdo->prepare('SELECT * FROM reservations WHERE id = ? LIMIT 1');
        $st->execute([$reservationId]);
        $res = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[fnrh] select reservation failed: ' . $e->getMessage());
        return ['success' => false, 'mode' => 'error', 'status' => 'erro', 'message' => 'Erro de banco.'];
    }
    if (!$res) {
        return ['success' => false, 'mode' => 'error', 'status' => 'erro', 'message' => 'Reserva não encontrada.'];
    }

    // Modo desligado: sistema local apenas.
    if (!fnrh_is_active($pdo)) {
        fnrh_persist_status($pdo, $reservationId, 'nao_enviado', 'Integração FNRH desativada nas configurações.');
        return [
            'success' => true,
            'mode' => 'local',
            'status' => 'nao_enviado',
            'message' => 'Check-in registrado localmente. Integração FNRH está desligada.',
        ];
    }

    $apiKey = trim(fnrh_setting($pdo, 'fnrh_api_key', ''));
    if ($apiKey === '') {
        fnrh_persist_status($pdo, $reservationId, 'erro', 'Chave API FNRH não configurada.');
        return [
            'success' => false,
            'mode' => 'remote',
            'status' => 'erro',
            'message' => 'Chave API FNRH não configurada. Preencha em Configurações.',
        ];
    }

    // Pré-requisitos mínimos para envio.
    $cpf = preg_replace('/\D/', '', (string) ($res['guest_cpf'] ?? '')) ?? '';
    if (strlen($cpf) < 11) {
        fnrh_persist_status($pdo, $reservationId, 'erro', 'CPF do hóspede ausente ou inválido.');
        return ['success' => false, 'mode' => 'remote', 'status' => 'erro', 'message' => 'CPF do hóspede ausente ou inválido.'];
    }

    $payload = fnrh_build_payload($res);
    $r = fnrh_http_dispatch($payload, $apiKey);

    if ($r['ok']) {
        fnrh_persist_status($pdo, $reservationId, 'enviado', $r['body']);
        return [
            'success' => true,
            'mode' => 'remote',
            'status' => 'enviado',
            'message' => 'Enviado à FNRH com sucesso.',
            'http_code' => $r['http_code'],
        ];
    }

    $errMsg = $r['error'] !== '' ? $r['error'] : ('HTTP ' . $r['http_code']);
    fnrh_persist_status($pdo, $reservationId, 'erro', $errMsg . "\n" . $r['body']);
    error_log('[fnrh] send failed for reservation ' . $reservationId . ': ' . $errMsg);
    return [
        'success' => false,
        'mode' => 'remote',
        'status' => 'erro',
        'message' => 'Falha ao enviar à FNRH: ' . $errMsg,
        'http_code' => $r['http_code'],
    ];
}

/* ============================================================
 * Endpoint REST quando chamado diretamente via HTTP.
 * Requer chave interna admin (X-Internal-Key).
 * ============================================================ */
if (PHP_SAPI !== 'cli' && basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    be_require_internal_key($pdo);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'POST') {
        jsonResponse(['error' => 'Método não permitido'], 405);
    }

    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        $raw = json_decode((string) file_get_contents('php://input'), true);
        if (is_array($raw) && isset($raw['reservation_id'])) {
            $id = (int) $raw['reservation_id'];
        }
    }
    if ($id <= 0) {
        jsonResponse(['error' => 'reservation_id obrigatório'], 400);
    }

    $result = fnrh_send_reservation($pdo, $id);
    $code = $result['success'] ? 200 : ($result['mode'] === 'error' && ($result['message'] ?? '') === 'Reserva não encontrada.' ? 404 : 400);
    jsonResponse($result, $code);
}
