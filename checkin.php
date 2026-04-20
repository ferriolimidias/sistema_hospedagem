<?php
declare(strict_types=1);

/**
 * Check-in online (FNRH): página pública por token em reservations.fnrh_access_token.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/api/db.php';

header('Content-Type: text/html; charset=utf-8');

$token = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = trim((string) ($_POST['token'] ?? ''));
} else {
    $token = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
}
if ($token === '' || strlen($token) > 64) {
    http_response_code(400);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Check-in</title></head><body><p>Link inválido.</p></body></html>';
    exit;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

try {
    $stmt = $pdo->prepare('SELECT id, guest_name, checkin_date, checkout_date, fnrh_data FROM reservations WHERE fnrh_access_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Check-in</title></head><body><p>Não foi possível carregar os dados. Tente mais tarde.</p></body></html>';
    exit;
}

if (!$row) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Check-in</title></head><body><p>Reserva não encontrada ou link expirado.</p></body></html>';
    exit;
}

$existing = null;
if (!empty($row['fnrh_data'])) {
    $existing = json_decode((string) $row['fnrh_data'], true);
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (is_array($existing) && !empty($existing['submitted_at'])) {
        $error = 'Este check-in já foi enviado.';
    } else {
        $cpf = preg_replace('/\D/', '', (string) ($_POST['cpf'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $companions = trim((string) ($_POST['companions'] ?? ''));

        if (strlen($cpf) < 11 || strlen($address) < 5) {
            $error = 'Informe CPF (11 dígitos) e endereço completo.';
        } else {
            $payload = [
                'cpf' => $cpf,
                'address' => $address,
                'companions' => $companions,
                'submitted_at' => date('c'),
            ];
            try {
                $up = $pdo->prepare('UPDATE reservations SET fnrh_data = ? WHERE fnrh_access_token = ? AND (fnrh_data IS NULL OR fnrh_data = \'\')');
                $up->execute([json_encode($payload, JSON_UNESCAPED_UNICODE), $token]);
                if ($up->rowCount() < 1) {
                    $error = 'Não foi possível salvar (dados já registrados?).';
                } else {
                    $message = 'Obrigado! Seus dados foram registrados com sucesso.';
                    $existing = $payload;
                }
            } catch (Throwable $e) {
                $error = 'Erro ao salvar. Tente novamente.';
            }
        }
    }
}

$guest = (string) ($row['guest_name'] ?? '');
$cin = (string) ($row['checkin_date'] ?? '');
$cout = (string) ($row['checkout_date'] ?? '');
$done = is_array($existing) && !empty($existing['submitted_at']);

// Nome da marca: lê dinamicamente de settings.site_title (fallback seguro se falhar).
$brandName = 'Pousada';
try {
    $stBrand = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'site_title' LIMIT 1");
    $stBrand->execute();
    $bv = $stBrand->fetchColumn();
    if (is_string($bv) && $bv !== '') {
        $decoded = json_decode($bv, true);
        $final = (json_last_error() === JSON_ERROR_NONE && is_string($decoded)) ? $decoded : $bv;
        if (trim($final) !== '') $brandName = trim($final);
    }
} catch (Exception $e) { /* usa fallback */ }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check-in online | <?= h($brandName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: Inter, system-ui, sans-serif; background: #f6f3ef; margin: 0; padding: 1.5rem; color: #2b2419; }
        .card { max-width: 520px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 1.75rem; box-shadow: 0 8px 32px rgba(0,0,0,.08); }
        h1 { font-size: 1.35rem; margin: 0 0 0.5rem; }
        .meta { font-size: 0.9rem; color: #666; margin-bottom: 1.25rem; }
        label { display: block; font-weight: 500; margin: 0.75rem 0 0.35rem; }
        input, textarea { width: 100%; box-sizing: border-box; padding: 0.65rem 0.75rem; border: 1px solid #ddd; border-radius: 8px; font-size: 1rem; }
        textarea { min-height: 100px; resize: vertical; }
        button { margin-top: 1.25rem; width: 100%; padding: 0.85rem; background: #c96621; color: #fff; border: none; border-radius: 8px; font-weight: 600; font-size: 1rem; cursor: pointer; }
        button:hover { background: #a8551b; }
        .ok { background: #e8f5e9; color: #1b5e20; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem; }
        .err { background: #ffebee; color: #b71c1c; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem; }
        .readonly { background: #f5f5f5; color: #444; white-space: pre-wrap; font-size: 0.95rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Check-in online (FNRH)</h1>
        <p class="meta">Olá, <strong><?= h($guest) ?></strong><br>
        Estadia: <?= h($cin) ?> — <?= h($cout) ?></p>

        <?php if ($message !== ''): ?><div class="ok"><?= h($message) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="err"><?= h($error) ?></div><?php endif; ?>

        <?php if ($done): ?>
            <p class="readonly"><?= h(json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></p>
        <?php else: ?>
            <form method="post" action="checkin.php">
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <label for="cpf">CPF (somente números)</label>
                <input id="cpf" name="cpf" type="text" inputmode="numeric" maxlength="14" required placeholder="00000000000" value="<?= h((string) ($_POST['cpf'] ?? '')) ?>">

                <label for="address">Endereço completo</label>
                <textarea id="address" name="address" required placeholder="Rua, número, bairro, cidade, UF, CEP"><?= h((string) ($_POST['address'] ?? '')) ?></textarea>

                <label for="companions">Acompanhantes (nomes e documentos, se houver)</label>
                <textarea id="companions" name="companions" placeholder="Opcional"><?= h((string) ($_POST['companions'] ?? '')) ?></textarea>

                <button type="submit">Enviar dados</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
