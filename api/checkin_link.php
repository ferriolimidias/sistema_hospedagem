<?php
declare(strict_types=1);

/**
 * Endpoint admin-only que garante um token FNRH para a reserva.
 * Uso: GET /api/checkin_link.php?id=<reservation_id>
 * Header: X-Internal-Key: <chave admin>
 *
 * Devolve:
 *   {
 *     "id": 123,
 *     "token": "abc...",
 *     "url": "https://host/checkin.php?id=abc...",
 *     "guest_name": "Fulano",
 *     "checkin_date": "2026-04-20",
 *     "checkout_date": "2026-04-23"
 *   }
 *
 * Idempotente: se já existir token, apenas devolve. Caso contrário, gera
 * um novo token criptograficamente seguro e persiste.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking_extras.php';

be_require_internal_key($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    jsonResponse(['error' => 'id obrigatório'], 400);
}

try {
    $stmt = $pdo->prepare('SELECT id, guest_name, checkin_date, checkout_date, fnrh_access_token FROM reservations WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[checkin_link] select failed: ' . $e->getMessage());
    jsonResponse(['error' => 'Erro de banco'], 500);
}
if (!$res) {
    jsonResponse(['error' => 'Reserva não encontrada'], 404);
}

$token = (string) ($res['fnrh_access_token'] ?? '');
if ($token === '') {
    // Gera um token hex seguro (32 bytes → 64 chars). Caberá em VARCHAR(64).
    try {
        $token = bin2hex(random_bytes(24)); // 48 chars
    } catch (Throwable $e) {
        // Fallback extremamente raro (sem /dev/urandom).
        $token = bin2hex(openssl_random_pseudo_bytes(24));
    }
    try {
        // Resolve eventual colisão reintentando uma vez.
        $up = $pdo->prepare('UPDATE reservations SET fnrh_access_token = ? WHERE id = ? AND (fnrh_access_token IS NULL OR fnrh_access_token = \'\')');
        $up->execute([$token, $id]);
        if ($up->rowCount() < 1) {
            // Entre a leitura e o update outro processo pode ter gravado — relê.
            $st2 = $pdo->prepare('SELECT fnrh_access_token FROM reservations WHERE id = ? LIMIT 1');
            $st2->execute([$id]);
            $token = (string) $st2->fetchColumn();
        }
    } catch (PDOException $e) {
        // Possível colisão no índice único — tenta uma vez com novo token.
        try {
            $token = bin2hex(random_bytes(24));
            $up2 = $pdo->prepare('UPDATE reservations SET fnrh_access_token = ? WHERE id = ?');
            $up2->execute([$token, $id]);
        } catch (Throwable $e2) {
            error_log('[checkin_link] token persist failed: ' . $e2->getMessage());
            jsonResponse(['error' => 'Falha ao gerar token'], 500);
        }
    }
}

// Constrói URL absoluta. Respeita proxy reverso (HTTPS atrás de LB).
$scheme = 'http';
if (
    (isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) === 'on')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
) {
    $scheme = 'https';
}
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api/checkin_link.php'))), '/'); // .../api
$rootPath = preg_replace('#/api$#', '', $scriptDir) ?? '';
$url = $scheme . '://' . $host . $rootPath . '/checkin.php?id=' . urlencode($token);

jsonResponse([
    'id' => (int) $res['id'],
    'token' => $token,
    'url' => $url,
    'guest_name' => (string) $res['guest_name'],
    'checkin_date' => (string) $res['checkin_date'],
    'checkout_date' => (string) $res['checkout_date'],
]);
