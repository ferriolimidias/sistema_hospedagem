<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/contract_access.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

$reservationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($reservationId <= 0) {
    jsonResponse(['error' => 'id da reserva é obrigatório'], 400);
}

$disposition = (isset($_GET['download']) && $_GET['download'] === '1') ? 'attachment' : 'inline';

$headers = [];
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0) {
        $name = strtolower(str_replace('_', '-', substr($k, 5)));
        $headers[$name] = (string)$v;
    }
}

try {
    $stmt = $pdo->prepare("
        SELECT id, guest_email, checkout_date, contract_filename
        FROM reservations
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$reservationId]);
    $res = $stmt->fetch();
    if (!$res) {
        jsonResponse(['error' => 'Reserva não encontrada'], 404);
    }

    $filename = trim((string)($res['contract_filename'] ?? ''));
    if ($filename === '') {
        jsonResponse(['error' => 'Contrato ainda não foi gerado para esta reserva'], 404);
    }

    $contractsDir = realpath(__DIR__ . '/../storage/contracts');
    if (!$contractsDir) {
        jsonResponse(['error' => 'Diretório de contratos indisponível'], 500);
    }

    $safeFilename = basename($filename);
    $filePath = $contractsDir . DIRECTORY_SEPARATOR . $safeFilename;
    if (!is_file($filePath)) {
        jsonResponse(['error' => 'Arquivo de contrato não encontrado'], 404);
    }

    $internalKeyProvided = trim((string)($headers['x-internal-key'] ?? ''));
    $internalKeyStored = getOrCreateInternalApiKey($pdo);
    $isAdmin = ($internalKeyProvided !== '' && hash_equals($internalKeyStored, $internalKeyProvided));

    $token = trim((string)($_GET['token'] ?? ''));
    $email = trim((string)($_GET['email'] ?? ''));
    if ($email === '') {
        $email = (string)($res['guest_email'] ?? '');
    }
    $isPublicAuthorized = validateContractAccessToken(
        (int)$res['id'],
        $safeFilename,
        $email,
        (string)($res['checkout_date'] ?? ''),
        $token,
        $internalKeyStored
    );

    if (!$isAdmin && !$isPublicAuthorized) {
        jsonResponse(['error' => 'Acesso não autorizado ao contrato'], 403);
    }

    header('Content-Type: application/pdf');
    header('Content-Length: ' . (string)filesize($filePath));
    header('Content-Disposition: ' . $disposition . '; filename="' . $safeFilename . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
    exit;
} catch (Throwable $e) {
    jsonResponse(['error' => 'Falha ao processar download do contrato', 'details' => $e->getMessage()], 500);
}

