<?php
declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

function ensureContractsStorageDir(): string
{
    $dir = realpath(__DIR__ . '/../storage');
    $contractsDir = ($dir ?: (__DIR__ . '/../storage')) . '/contracts';
    if (!is_dir($contractsDir)) {
        mkdir($contractsDir, 0755, true);
    }

    $htaccessPath = $contractsDir . '/.htaccess';
    if (!file_exists($htaccessPath)) {
        file_put_contents($htaccessPath, "Deny from all\n");
    }

    return $contractsDir;
}

function buildContractHtml(array $res): string
{
    $guestName = htmlspecialchars((string)($res['guest_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $guestEmail = htmlspecialchars((string)($res['guest_email'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $guestPhone = htmlspecialchars((string)($res['guest_phone'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $chaletName = htmlspecialchars((string)($res['chalet_name'] ?? 'Chalé'), ENT_QUOTES, 'UTF-8');
    $checkin = htmlspecialchars((string)($res['checkin_date'] ?? ''), ENT_QUOTES, 'UTF-8');
    $checkout = htmlspecialchars((string)($res['checkout_date'] ?? ''), ENT_QUOTES, 'UTF-8');
    $createdAt = htmlspecialchars((string)($res['created_at'] ?? date('Y-m-d H:i:s')), ENT_QUOTES, 'UTF-8');
    $reservationId = (int)($res['id'] ?? 0);
    $total = number_format((float)($res['total_amount'] ?? 0), 2, ',', '.');
    $paymentRule = strtolower((string)($res['payment_rule'] ?? 'full'));
    $paidNow = $paymentRule === 'half' ? ((float)$res['total_amount'] / 2) : (float)$res['total_amount'];
    $paidNowFormatted = number_format($paidNow, 2, ',', '.');
    $ruleLabel = $paymentRule === 'half' ? 'Sinal de 50%' : 'Pagamento integral';
    $logoPath = __DIR__ . '/../images/logo.png';
    $logoHtml = file_exists($logoPath) ? '<img src="' . $logoPath . '" alt="Logo">' : '<h1>Recantos da Serra</h1>';

    return <<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <style>
    body { font-family: DejaVu Sans, sans-serif; color: #212121; margin: 30px; font-size: 12px; }
    .header { border-bottom: 2px solid #c96621; padding-bottom: 12px; margin-bottom: 18px; }
    .header img { max-height: 52px; }
    .title { color: #c96621; font-size: 20px; margin: 0 0 4px; }
    .subtitle { margin: 0; color: #666; }
    .section { margin-top: 16px; }
    .section h2 { font-size: 14px; border-bottom: 1px solid #eee; padding-bottom: 4px; margin: 0 0 8px; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 6px 4px; vertical-align: top; }
    .label { width: 34%; color: #555; font-weight: bold; }
    .box { border: 1px solid #ddd; border-radius: 8px; padding: 10px; background: #fafafa; }
    .terms { line-height: 1.45; text-align: justify; }
    .signature { margin-top: 42px; }
    .line { border-top: 1px solid #555; width: 320px; margin-top: 40px; padding-top: 6px; font-size: 11px; }
  </style>
</head>
<body>
  <div class="header">
    {$logoHtml}
    <p class="title">Contrato de Hospedagem</p>
    <p class="subtitle">Reserva #{$reservationId} - Emitido em {$createdAt}</p>
  </div>

  <div class="section">
    <h2>Dados do Hóspede</h2>
    <table>
      <tr><td class="label">Nome</td><td>{$guestName}</td></tr>
      <tr><td class="label">E-mail</td><td>{$guestEmail}</td></tr>
      <tr><td class="label">Telefone</td><td>{$guestPhone}</td></tr>
    </table>
  </div>

  <div class="section">
    <h2>Dados da Reserva</h2>
    <table>
      <tr><td class="label">Acomodação</td><td>{$chaletName}</td></tr>
      <tr><td class="label">Check-in</td><td>{$checkin}</td></tr>
      <tr><td class="label">Check-out</td><td>{$checkout}</td></tr>
      <tr><td class="label">Condição</td><td>{$ruleLabel}</td></tr>
      <tr><td class="label">Valor total da estadia</td><td>R$ {$total}</td></tr>
      <tr><td class="label">Valor pago nesta transação</td><td>R$ {$paidNowFormatted}</td></tr>
    </table>
  </div>

  <div class="section box">
    <h2>Regras de Check-in / Check-out</h2>
    <ul>
      <li>Check-in a partir das 15:00 e check-out até 12:00.</li>
      <li>Documento oficial com foto é obrigatório no check-in.</li>
      <li>Danos ao imóvel ou enxoval serão cobrados conforme avaliação.</li>
      <li>Cancelamentos e alterações seguem política vigente no ato da reserva.</li>
    </ul>
  </div>

  <div class="section terms">
    <h2>Termos e Condições</h2>
    <p>
      Ao confirmar esta reserva, o hóspede declara estar de acordo com as regras de hospedagem, políticas de
      cancelamento, normas de uso da acomodação e responsabilidades civis decorrentes da estadia. Este contrato
      representa o aceite eletrônico entre as partes, com validade jurídica para todos os efeitos.
    </p>
  </div>

  <div class="signature">
    <div class="line">Recantos da Serra - Responsável pela hospedagem</div>
    <div class="line">Hóspede</div>
  </div>
</body>
</html>
HTML;
}

function generateContractForReservation(PDO $pdo, int $reservationId): array
{
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new RuntimeException('Dependências PHP não instaladas. Execute "composer install".');
    }
    require_once $autoloadPath;

    $stmt = $pdo->prepare("
        SELECT r.*, c.name AS chalet_name
        FROM reservations r
        LEFT JOIN chalets c ON c.id = r.chalet_id
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->execute([$reservationId]);
    $res = $stmt->fetch();
    if (!$res) {
        throw new RuntimeException('Reserva não encontrada.');
    }

    $contractsDir = ensureContractsStorageDir();
    $filename = sprintf('contract_%d_%s.pdf', $reservationId, date('Ymd_His'));
    $fullPath = $contractsDir . '/' . $filename;

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml(buildContractHtml($res), 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    file_put_contents($fullPath, $dompdf->output());

    $stmtUpd = $pdo->prepare("UPDATE reservations SET contract_filename = ? WHERE id = ?");
    $stmtUpd->execute([$filename, $reservationId]);

    return [
        'reservation_id' => $reservationId,
        'filename' => $filename,
        'path' => $fullPath
    ];
}

