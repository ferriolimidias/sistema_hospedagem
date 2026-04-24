<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/api/db.php';
header('Content-Type: text/html; charset=utf-8');
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmt_br_date(string $d): string { $p = explode('-', $d); return count($p) === 3 ? ($p[2] . '/' . $p[1] . '/' . $p[0]) : $d; }
function fmt_money(float $n): string { return 'R$ ' . number_format($n, 2, ',', '.'); }
function setting_val(PDO $pdo, string $k, string $d = ''): string {
    try { $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1'); $st->execute([$k]); $v = $st->fetchColumn(); if (!is_string($v)) return $d; $x = json_decode($v, true); return (json_last_error() === JSON_ERROR_NONE && is_string($x)) ? $x : $v; } catch (Throwable $e) { return $d; }
}
$token = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? trim((string) ($_POST['token'] ?? '')) : trim((string) ($_GET['id'] ?? ''));
$brandName = trim(setting_val($pdo, 'site_title', 'Hospedagem')) ?: 'Hospedagem';
$primaryColor = trim(setting_val($pdo, 'primary_color', '#c96621')) ?: '#c96621';
$secondaryColor = trim(setting_val($pdo, 'secondary_color', '#1e293b')) ?: '#1e293b';
if ($token === '' || strlen($token) > 64) { http_response_code(400); echo 'Link inválido.'; exit; }

try {
    $stmt = $pdo->prepare("SELECT r.*, c.name AS chalet_name,
        COALESCE((SELECT SUM(rc.total_price) FROM reservation_consumptions rc WHERE rc.reservation_id = r.id), 0) AS total_consumed
        FROM reservations r
        LEFT JOIN chalets c ON c.id = r.chalet_id
        WHERE r.fnrh_access_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[checkin] load failed: ' . $e->getMessage());
    http_response_code(500); echo 'Falha temporária.'; exit;
}
if (!$row) { http_response_code(404); echo 'Reserva não encontrada.'; exit; }

// Endpoint AJAX para consumo em tempo real no status Hospedado
if (isset($_GET['ajax']) && $_GET['ajax'] === 'folio') {
    header('Content-Type: application/json; charset=utf-8');
    $stC = $pdo->prepare("SELECT description, quantity, unit_price, total_price, created_at FROM reservation_consumptions WHERE reservation_id = ? ORDER BY created_at DESC, id DESC");
    $stC->execute([(int) $row['id']]);
    echo json_encode([
        'items' => $stC->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'total_consumed' => (float) ($row['total_consumed'] ?? 0),
        'total_amount' => (float) ($row['total_amount'] ?? 0),
        'status' => (string) ($row['status'] ?? '')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$message = '';
$error = '';
$status = (string) ($row['status'] ?? 'Pendente');
$hasCpf = trim((string) ($row['guest_cpf'] ?? '')) !== '';
$canSubmitFnrh = in_array($status, ['Confirmada', 'Pendente'], true);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $canSubmitFnrh) {
    if ($hasCpf) {
        $error = 'Os dados já foram enviados anteriormente.';
    } else {
        $cpf = preg_replace('/\D/', '', (string) ($_POST['cpf'] ?? '')) ?? '';
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $carPlate = strtoupper(trim((string) ($_POST['car_plate'] ?? '')));
        $companions = trim((string) ($_POST['companions'] ?? ''));
        if (strlen($cpf) < 11) $error = 'Informe CPF válido.';
        if ($error === '' && strlen($address) < 8) $error = 'Informe o endereço completo.';
        if ($error === '') {
            try {
                $backup = json_encode(['cpf' => $cpf, 'phone' => $phone, 'address' => $address, 'car_plate' => $carPlate, 'companions' => $companions, 'submitted_at' => date('c')], JSON_UNESCAPED_UNICODE);
                $up = $pdo->prepare("UPDATE reservations SET guest_cpf=?, guest_phone=COALESCE(NULLIF(?, ''), guest_phone), guest_address=?, guest_car_plate=?, guest_companion_names=?, fnrh_data=?, fnrh_submitted_at=NOW() WHERE id=? AND (guest_cpf IS NULL OR guest_cpf='')");
                $ok = $up->execute([$cpf, $phone, $address, $carPlate, $companions, $backup, (int) $row['id']]);
                if ($ok && $up->rowCount() > 0) {
                    $message = 'Dados recebidos! Apresente-se na receção para o acerto do saldo e entrega das chaves.';
                    $row['guest_cpf'] = $cpf; $row['guest_phone'] = $phone ?: (string) ($row['guest_phone'] ?? ''); $row['guest_address'] = $address; $row['guest_car_plate'] = $carPlate; $row['guest_companion_names'] = $companions;
                    $hasCpf = true;
                } else {
                    $error = 'Dados já registrados anteriormente.';
                }
            } catch (Throwable $e) {
                error_log('[checkin] save failed: ' . $e->getMessage());
                $error = 'Falha ao salvar os dados.';
            }
        }
    }
}

$totalAmount = (float) ($row['total_amount'] ?? 0);
$totalConsumed = (float) ($row['total_consumed'] ?? 0);
$paymentRule = (string) ($row['payment_rule'] ?? 'full');
$percentNow = $paymentRule === 'half' ? 50 : 100;
try {
    $polRaw = setting_val($pdo, 'payment_policies', '');
    $pol = json_decode($polRaw, true);
    if (is_array($pol)) {
        foreach ($pol as $p) {
            if (!is_array($p)) continue;
            if (strtolower((string) ($p['code'] ?? '')) === strtolower($paymentRule)) {
                $percentNow = max(0, min(100, (float) ($p['percent_now'] ?? $percentNow)));
                break;
            }
        }
    }
} catch (Throwable $e) { /* fallback */ }
$deposit = round($totalAmount * ($percentNow / 100), 2);
$balance = max(0, round($totalAmount - $deposit, 2));
$balancePaid = (int) ($row['balance_paid'] ?? 0) === 1;
$nowDue = ($balancePaid ? 0.0 : $balance) + ($status === 'Hospedado' ? $totalConsumed : 0.0);
$portalFinal = ($status === 'Finalizada') ? max(0.0, $totalConsumed) : $nowDue;
$mpLink = trim((string) ($row['mp_init_point'] ?? ''));
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Portal do Hóspede | <?= h($brandName) ?></title>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        :root{--p:<?= h($primaryColor) ?>;--s:<?= h($secondaryColor) ?>;--b:#e5e7eb;--m:#6b7280;--bg:#f7f5f2}*{box-sizing:border-box}body{margin:0;font-family:Inter,system-ui;background:var(--bg);color:#111} .wrap{max-width:560px;margin:0 auto;padding:16px} .card{background:#fff;border:1px solid var(--b);border-radius:14px;padding:16px;box-shadow:0 8px 24px -16px rgba(0,0,0,.25)} .head{display:flex;align-items:center;gap:8px;color:var(--s);font-weight:700;margin-bottom:10px}.head i{color:var(--p)} .pill{display:inline-flex;padding:4px 10px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:12px;font-weight:600} .mut{color:var(--m)} .grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:10px 0}.box{border:1px solid var(--b);border-radius:10px;padding:10px;background:#fafafa}.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;width:100%;border:0;border-radius:10px;padding:12px 14px;background:var(--p);color:#fff;font-weight:700;cursor:pointer;text-decoration:none}.btn.alt{background:#fff;color:var(--p);border:1px solid var(--p)}input,textarea{width:100%;padding:10px;border:1px solid var(--b);border-radius:10px}.f{margin:8px 0}.tb{width:100%;border-collapse:collapse;font-size:.9rem}.tb td,.tb th{padding:8px;border-bottom:1px solid var(--b);text-align:left}.ok{background:#ecfdf5;color:#065f46;border:1px solid #86efac;padding:10px;border-radius:10px;margin:8px 0}.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:10px;border-radius:10px;margin:8px 0}
    </style>
</head>
<body>
<div class="wrap">
    <div class="head"><i class="ph ph-building"></i><span><?= h($brandName) ?></span></div>
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
            <h2 style="margin:0;font-size:1.1rem;">Portal do Hóspede</h2>
            <span class="pill"><?= h($status) ?></span>
        </div>
        <p class="mut" style="margin:.35rem 0 0;">Olá, <strong><?= h((string) ($row['guest_name'] ?? 'Hóspede')) ?></strong> · <?= h((string) ($row['chalet_name'] ?? 'Acomodação')) ?></p>

        <div class="grid">
            <div class="box"><small class="mut">Check-in</small><div><strong><?= h(fmt_br_date((string) ($row['checkin_date'] ?? ''))) ?></strong></div></div>
            <div class="box"><small class="mut">Check-out</small><div><strong><?= h(fmt_br_date((string) ($row['checkout_date'] ?? ''))) ?></strong></div></div>
        </div>

        <?php if ($message !== ''): ?><div class="ok"><?= h($message) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="err"><?= h($error) ?></div><?php endif; ?>

        <?php if ($status === 'Pendente'): ?>
            <h3 style="margin:.5rem 0;">Pagamento do Sinal</h3>
            <p class="mut">Para confirmar sua reserva na <?= h($brandName) ?>, realize o pagamento do sinal.</p>
            <div class="box" style="margin-bottom:10px;">
                <div>Total da reserva: <strong><?= h(fmt_money($totalAmount)) ?></strong></div>
                <div>Sinal previsto: <strong><?= h(fmt_money($deposit)) ?></strong></div>
            </div>
            <?php if ($mpLink !== ''): ?>
                <a class="btn" href="<?= h($mpLink) ?>" target="_blank" rel="noopener"><i class="ph ph-credit-card"></i> Pagar sinal agora</a>
            <?php else: ?>
                <p class="mut">A recepção enviará as instruções de pagamento pelo canal de atendimento.</p>
            <?php endif; ?>
        <?php elseif ($status === 'Confirmada'): ?>
            <h3 style="margin:.5rem 0;">Pré-Check-in</h3>
            <p class="mut">Preencha seus dados para agilizar sua chegada.</p>
            <?php if (trim((string) ($row['guest_cpf'] ?? '')) !== ''): ?>
                <div class="ok">Seus dados já foram recebidos. Na chegada, apresente-se na receção para o acerto do saldo e entrega das chaves.</div>
            <?php else: ?>
                <form method="post" action="checkin.php" autocomplete="on" novalidate>
                    <input type="hidden" name="token" value="<?= h($token) ?>">
                    <div class="f"><label>CPF</label><input name="cpf" required maxlength="14" placeholder="000.000.000-00"></div>
                    <div class="f"><label>Telefone</label><input name="phone" placeholder="(xx) xxxxx-xxxx"></div>
                    <div class="f"><label>Endereço</label><textarea name="address" rows="2" required placeholder="Rua, número, bairro, cidade, UF, CEP"></textarea></div>
                    <div class="f"><label>Placa do veículo</label><input name="car_plate" maxlength="10"></div>
                    <div class="f"><label>Acompanhantes</label><textarea name="companions" rows="2"></textarea></div>
                    <button class="btn" type="submit"><i class="ph ph-floppy-disk"></i> Guardar Dados</button>
                </form>
            <?php endif; ?>
        <?php elseif ($status === 'Hospedado'): ?>
            <h3 style="margin:.5rem 0;">Conta da Estadia (ao vivo)</h3>
            <div class="grid">
                <div class="box"><small class="mut">Diárias</small><div><strong><?= h(fmt_money($totalAmount)) ?></strong></div></div>
                <div class="box"><small class="mut">Consumo atual</small><div><strong id="liveCons"><?= h(fmt_money($totalConsumed)) ?></strong></div></div>
            </div>
            <div class="box" style="margin-bottom:8px;"><small class="mut">Saldo parcial a pagar</small><div><strong id="liveDue"><?= h(fmt_money($nowDue)) ?></strong></div></div>
            <div style="max-height:220px;overflow:auto;border:1px solid var(--b);border-radius:10px;">
                <table class="tb"><thead><tr><th>Descrição</th><th>Qtd</th><th>Total</th></tr></thead><tbody id="liveRows"><tr><td colspan="3" class="mut">Carregando consumo...</td></tr></tbody></table>
            </div>
            <script>
                (function(){
                    const token = <?= json_encode($token) ?>;
                    const rowsEl = document.getElementById('liveRows');
                    const consEl = document.getElementById('liveCons');
                    const dueEl = document.getElementById('liveDue');
                    const fmt = (n) => 'R$ ' + Number(n||0).toLocaleString('pt-BR',{minimumFractionDigits:2});
                    const balance = <?= json_encode($balancePaid ? 0.0 : $balance) ?>;
                    async function reload(){
                        try{
                            const r = await fetch('checkin.php?id=' + encodeURIComponent(token) + '&ajax=folio');
                            const d = await r.json();
                            const items = Array.isArray(d.items) ? d.items : [];
                            const tc = Number(d.total_consumed || 0);
                            consEl.textContent = fmt(tc);
                            dueEl.textContent = fmt(tc + balance);
                            rowsEl.innerHTML = items.length ? items.map(it => `<tr><td>${it.description}</td><td>${it.quantity}</td><td>${fmt(it.total_price)}</td></tr>`).join('') : '<tr><td colspan="3" class="mut">Sem consumo lançado.</td></tr>';
                        }catch(e){ rowsEl.innerHTML = '<tr><td colspan="3" class="mut">Não foi possível atualizar agora.</td></tr>'; }
                    }
                    reload(); setInterval(reload, 20000);
                })();
            </script>
        <?php elseif ($status === 'Finalizada'): ?>
            <h3 style="margin:.5rem 0;">Estadia Encerrada</h3>
            <div class="ok">Conta finalizada. Obrigado por escolher <?= h($brandName) ?>. Esperamos recebê-lo(a) novamente em breve!</div>
            <div class="box"><small class="mut">Resumo final</small><div><strong><?= h(fmt_money($portalFinal)) ?></strong> <span class="mut">(situação encerrada)</span></div></div>
        <?php else: ?>
            <p class="mut">Seu portal será atualizado conforme o andamento da reserva.</p>
        <?php endif; ?>
    </div>
</div>
</body></html>
