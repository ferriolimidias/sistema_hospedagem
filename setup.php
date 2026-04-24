<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/api/schema.php';

$configDir = __DIR__ . '/config';
$configPath = $configDir . '/database.php';
$errors = [];
$success = false;
$message = '';
$schemaVersion = '1.0.0';
$schemaVersionVerified = '';
$installErrorDetail = '';
$installChecklist = [
    'db_connection' => ['label' => 'Conexão com o Banco de Dados', 'ok' => false, 'detail' => ''],
    'base_tables' => ['label' => 'Criação das Tabelas Base', 'ok' => false, 'detail' => ''],
    'guest_folio' => ['label' => 'Módulo de Consumo (Guest Folio)', 'ok' => false, 'detail' => ''],
    'fnrh' => ['label' => 'Módulo Gov.br (FNRH)', 'ok' => false, 'detail' => ''],
    'evolution' => ['label' => 'Chaves Evolution API', 'ok' => false, 'detail' => ''],
];

$formData = [
    'db_host' => $_POST['db_host'] ?? '127.0.0.1',
    'db_name' => $_POST['db_name'] ?? '',
    'db_user' => $_POST['db_user'] ?? '',
    'db_pass' => $_POST['db_pass'] ?? '',
    'admin_name' => $_POST['admin_name'] ?? '',
    'admin_email' => $_POST['admin_email'] ?? '',
    'admin_pass' => $_POST['admin_pass'] ?? '',
];

if (file_exists($configPath) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim((string)$formData['db_host']);
    $dbName = trim((string)$formData['db_name']);
    $dbUser = trim((string)$formData['db_user']);
    $dbPass = (string)$formData['db_pass'];
    $adminName = trim((string)$formData['admin_name']);
    $adminEmail = trim((string)$formData['admin_email']);
    $adminPass = (string)$formData['admin_pass'];

    if ($dbHost === '') $errors[] = 'Host do banco é obrigatório.';
    if ($dbName === '') $errors[] = 'Nome da base de dados é obrigatório.';
    if ($dbName !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $dbName) !== 1) {
        $errors[] = 'Nome da base de dados deve conter apenas letras, números e underscore.';
    }
    if ($dbUser === '') $errors[] = 'Utilizador do banco é obrigatório.';

    $adminFieldErrors = [];
    if ($adminName === '') $adminFieldErrors[] = 'Nome do primeiro administrador é obrigatório.';
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $adminFieldErrors[] = 'Email do administrador é inválido.';
    if (strlen($adminPass) < 8) $adminFieldErrors[] = 'Password do administrador deve ter no mínimo 8 caracteres.';

    if (empty($errors)) {
        try {
            $pdoRoot = new PDO(
                "mysql:host={$dbHost};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo = new PDO(
                "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            $installChecklist['db_connection']['ok'] = true;
            $installChecklist['db_connection']['detail'] = 'Conexão estabelecida com sucesso.';

            // Sincronização de schema (migrator): cria tabelas que faltam e adiciona colunas novas,
            // sem apagar dados existentes. Todas as operações são idempotentes.
            // Sem transação: CREATE/ALTER TABLE no MySQL fazem commit implícito.
            runInitialSchema($pdo);

            $requiredBaseTables = ['admins', 'chalets', 'reservations', 'settings', 'faqs'];
            $missingBaseTables = [];
            foreach ($requiredBaseTables as $tbl) {
                $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tbl));
                $exists = $st ? $st->fetchColumn() : false;
                if ($exists === false) $missingBaseTables[] = $tbl;
            }
            if ($missingBaseTables !== []) {
                throw new RuntimeException('Tabelas base ausentes: ' . implode(', ', $missingBaseTables));
            }
            $installChecklist['base_tables']['ok'] = true;
            $installChecklist['base_tables']['detail'] = 'Estruturas principais validadas.';

            // Define versão atual do schema sem sobrescrever instalações já versionadas.
            $stSchemaVersion = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('db_schema_version', ?) ON DUPLICATE KEY UPDATE setting_value = setting_value");
            $stSchemaVersion->execute([$schemaVersion]);
            $stSchemaVersionRead = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'db_schema_version' LIMIT 1");
            $stSchemaVersionRead->execute();
            $schemaVersionReadRaw = $stSchemaVersionRead->fetchColumn();
            $schemaVersionVerified = trim((string)($schemaVersionReadRaw ?? ''));

            $stConsumption = $pdo->query("SHOW TABLES LIKE 'reservation_consumptions'");
            $consumptionTableExists = $stConsumption ? $stConsumption->fetchColumn() !== false : false;
            if (!$consumptionTableExists) {
                throw new RuntimeException('Tabela reservation_consumptions não foi criada.');
            }
            $stConsumptionColumn = $pdo->query("SHOW COLUMNS FROM reservation_consumptions LIKE 'total_price'");
            $consumptionColumnExists = $stConsumptionColumn ? $stConsumptionColumn->fetchColumn() !== false : false;
            if (!$consumptionColumnExists) {
                throw new RuntimeException('Coluna total_price ausente em reservation_consumptions.');
            }
            $installChecklist['guest_folio']['ok'] = true;
            $installChecklist['guest_folio']['detail'] = 'Tabela e colunas críticas de consumos confirmadas.';

            $stGuestCpf = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'guest_cpf'");
            $hasGuestCpf = $stGuestCpf ? $stGuestCpf->fetchColumn() !== false : false;
            $stFnrhStatus = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'fnrh_status'");
            $hasFnrhStatus = $stFnrhStatus ? $stFnrhStatus->fetchColumn() !== false : false;
            if (!$hasGuestCpf || !$hasFnrhStatus) {
                throw new RuntimeException('Estrutura FNRH incompleta em reservations (guest_cpf/fnrh_status).');
            }
            $installChecklist['fnrh']['ok'] = true;
            $installChecklist['fnrh']['detail'] = 'Colunas FNRH validadas em reservations.';

            $evolutionKeys = [
                'evo_url',
                'evo_instance',
                'evo_apikey',
                'evo_notify_reserva',
                'evo_notify_checkin',
                'evo_notify_checkout',
            ];
            $missingEvolutionKeys = [];
            $stSettingExists = $pdo->prepare('SELECT 1 FROM settings WHERE setting_key = ? LIMIT 1');
            foreach ($evolutionKeys as $settingKey) {
                $stSettingExists->execute([$settingKey]);
                if ($stSettingExists->fetchColumn() === false) {
                    $missingEvolutionKeys[] = $settingKey;
                }
            }
            if ($missingEvolutionKeys !== []) {
                throw new RuntimeException('Chaves Evolution ausentes: ' . implode(', ', $missingEvolutionKeys));
            }
            $installChecklist['evolution']['ok'] = true;
            $installChecklist['evolution']['detail'] = 'Configurações da Evolution API presentes.';

            if (!empty($adminFieldErrors)) {
                throw new RuntimeException(implode(' ', $adminFieldErrors));
            }

            // Escreve/atualiza config/database.php somente após o schema ter sido criado com sucesso,
            // para evitar gravar credenciais de um banco que não aceita as migrações.
            if (!is_dir($configDir) && !mkdir($configDir, 0755, true) && !is_dir($configDir)) {
                throw new RuntimeException('Não foi possível criar a pasta de configuração.');
            }

            $configContent = "<?php\n";
            $configContent .= "declare(strict_types=1);\n\n";
            $configContent .= "return [\n";
            $configContent .= "    'host' => " . var_export($dbHost, true) . ",\n";
            $configContent .= "    'dbname' => " . var_export($dbName, true) . ",\n";
            $configContent .= "    'user' => " . var_export($dbUser, true) . ",\n";
            $configContent .= "    'pass' => " . var_export($dbPass, true) . ",\n";
            $configContent .= "    'charset' => 'utf8mb4',\n";
            $configContent .= "];\n";

            if (file_put_contents($configPath, $configContent) === false) {
                throw new RuntimeException('Não foi possível gravar o ficheiro config/database.php.');
            }
            @chmod($configPath, 0640);

            // Cria/atualiza o administrador informado no formulário.
            $adminPasswordHash = password_hash($adminPass, PASSWORD_DEFAULT);
            $adminPermissions = json_encode([
                'dashboard',
                'reservations',
                'chalets',
                'financeiro',
                'coupons',
                'faqs',
                'settings',
                'customization',
                'users',
            ], JSON_UNESCAPED_UNICODE);
            $upsertAdmin = $pdo->prepare(
                'INSERT INTO admins (name, email, password, role, permissions)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    password = VALUES(password),
                    role = VALUES(role),
                    permissions = VALUES(permissions)'
            );
            $upsertAdmin->execute([
                $adminName,
                $adminEmail,
                $adminPasswordHash,
                'admin',
                $adminPermissions,
            ]);
            $adminMsg = 'Administrador criado com sucesso! Use o e-mail ' . $adminEmail . ' para fazer login.';

            // Bloqueia reutilização direta do instalador após sucesso.
            $lockPath = __DIR__ . '/config/.installed.lock';
            @file_put_contents($lockPath, date('c'));

            $renamed = @rename(__FILE__, __DIR__ . '/setup.installed.php');
            $installerMsg = $renamed
                ? 'O instalador foi desativado.'
                : 'Remova manualmente setup.php por segurança.';
            $message = 'Sincronização concluída com sucesso. ' . $adminMsg . ' ' . $installerMsg;
            $success = true;
            header('Refresh: 4; URL=/index.php');
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                    // Ignora: após DDL o MySQL pode já não ter transação ativa.
                }
            }
            $detail = $e->getMessage();
            if ($e instanceof PDOException && isset($e->errorInfo[2]) && (string)$e->errorInfo[2] !== '') {
                $detail .= ' [' . (string)$e->errorInfo[2] . ']';
            }
            $installErrorDetail = $detail;
            if (!$installChecklist['db_connection']['ok']) {
                $installChecklist['db_connection']['detail'] = $detail;
            } elseif (!$installChecklist['base_tables']['ok']) {
                $installChecklist['base_tables']['detail'] = $detail;
            } elseif (!$installChecklist['guest_folio']['ok']) {
                $installChecklist['guest_folio']['detail'] = $detail;
            } elseif (!$installChecklist['fnrh']['ok']) {
                $installChecklist['fnrh']['detail'] = $detail;
            } elseif (!$installChecklist['evolution']['ok']) {
                $installChecklist['evolution']['detail'] = $detail;
            }
            $errors[] = 'Falha na instalação: ' . $detail;
        }
    }
}

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instalação Inicial</title>
    <style>
        :root {
            --bg: #f6f7fb;
            --card: #fff;
            --text: #1f2430;
            --muted: #667085;
            --primary: #2563eb;
            --danger: #b42318;
            --success: #067647;
            --border: #e4e7ec;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Inter, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        .wrap {
            max-width: 760px;
            margin: 2rem auto;
            padding: 1rem;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.4rem;
            box-shadow: 0 10px 28px rgba(16, 24, 40, .08);
        }
        h1 { margin: 0 0 0.3rem; font-size: 1.5rem; }
        p.lead { margin: 0 0 1.4rem; color: var(--muted); }
        fieldset {
            border: 1px solid var(--border);
            border-radius: 12px;
            margin: 0 0 1rem;
            padding: 1rem;
        }
        legend { font-weight: 600; padding: 0 0.4rem; }
        .grid {
            display: grid;
            gap: .8rem;
            grid-template-columns: 1fr 1fr;
        }
        .full { grid-column: 1 / -1; }
        label {
            display: block;
            font-size: .9rem;
            margin-bottom: .35rem;
            color: #344054;
        }
        input {
            width: 100%;
            border: 1px solid #d0d5dd;
            border-radius: 10px;
            padding: .65rem .75rem;
            outline: none;
        }
        input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(201, 102, 33, .15);
        }
        button {
            border: 0;
            border-radius: 10px;
            background: var(--primary);
            color: #fff;
            padding: .72rem 1rem;
            font-weight: 600;
            cursor: pointer;
        }
        .notice {
            border-radius: 10px;
            padding: .75rem .9rem;
            margin-bottom: 1rem;
            font-size: .92rem;
        }
        .error { background: #fef3f2; border: 1px solid #fecdca; color: var(--danger); }
        .ok { background: #ecfdf3; border: 1px solid #abefc6; color: var(--success); }
        .console {
            margin: 0 0 1rem;
            border-radius: 12px;
            border: 1px solid #2b3445;
            background: #0b1220;
            color: #e5e7eb;
            overflow: hidden;
            box-shadow: 0 12px 32px rgba(2, 6, 23, .35);
        }
        .console-head {
            padding: .55rem .8rem;
            border-bottom: 1px solid #1f2937;
            font-size: .85rem;
            color: #9ca3af;
            letter-spacing: .02em;
        }
        .console-body { padding: .75rem .85rem; }
        .check-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .8rem;
            padding: .35rem 0;
            border-bottom: 1px dashed rgba(148, 163, 184, .24);
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: .9rem;
        }
        .check-row:last-child { border-bottom: 0; }
        .check-label { color: #d1d5db; }
        .check-status.ok { color: #22c55e; border: 0; background: transparent; padding: 0; margin: 0; }
        .check-status.err { color: #f87171; border: 0; background: transparent; padding: 0; margin: 0; }
        .check-detail {
            margin: .2rem 0 0 0;
            color: #94a3b8;
            font-size: .8rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }
        .schema-line {
            margin-top: .7rem;
            padding-top: .55rem;
            border-top: 1px solid rgba(148, 163, 184, .24);
            color: #cbd5e1;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: .9rem;
        }
        ul { margin: .4rem 0 0 1.2rem; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Instalação e Sincronização</h1>
            <p class="lead">
                Configure a base de dados e o administrador principal.
                Se o banco já contiver dados, eles serão <strong>preservados</strong>: apenas serão criadas
                as tabelas e colunas que faltam.
            </p>

            <?php if (!empty($errors)): ?>
                <div class="notice error">
                    <strong>Não foi possível concluir a instalação.</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= esc($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                <div class="console">
                    <div class="console-head">diagnostics://installer/schema-checklist</div>
                    <div class="console-body">
                        <?php foreach ($installChecklist as $row): ?>
                            <div class="check-row">
                                <span class="check-label"><?= esc($row['label']) ?></span>
                                <span class="check-status <?= $row['ok'] ? 'ok' : 'err' ?>">
                                    <?= $row['ok'] ? '✅' : '❌' ?>
                                </span>
                            </div>
                            <?php if ((string)$row['detail'] !== ''): ?>
                                <div class="check-detail"><?= esc((string)$row['detail']) ?></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ($installErrorDetail !== ''): ?>
                            <div class="check-detail">Erro PDO/Runtime: <?= esc($installErrorDetail) ?></div>
                        <?php endif; ?>
                        <?php if ($schemaVersionVerified !== ''): ?>
                            <div class="schema-line">Versão do Schema Instalada e Verificada: v<?= esc($schemaVersionVerified) ?></div>
                        <?php else: ?>
                            <div class="schema-line" style="color:#f87171;">❌ Erro: Não foi possível ler a versão do schema.</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="notice ok">
                    <strong><?= esc($message) ?></strong><br>
                    Redirecionamento para o sistema em instantes...
                </div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <fieldset>
                    <legend>Base de Dados</legend>
                    <div class="grid">
                        <div>
                            <label for="db_host">Host</label>
                            <input id="db_host" name="db_host" required value="<?= esc((string)$formData['db_host']) ?>">
                        </div>
                        <div>
                            <label for="db_name">Nome da Base de Dados</label>
                            <input id="db_name" name="db_name" required value="<?= esc((string)$formData['db_name']) ?>">
                        </div>
                        <div>
                            <label for="db_user">Utilizador</label>
                            <input id="db_user" name="db_user" required value="<?= esc((string)$formData['db_user']) ?>">
                        </div>
                        <div>
                            <label for="db_pass">Password</label>
                            <input id="db_pass" name="db_pass" type="password" value="<?= esc((string)$formData['db_pass']) ?>">
                        </div>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Administrador Principal</legend>
                    <p style="margin:.2rem 0 .9rem; color:var(--muted); font-size:.88rem;">
                        Estes campos serão usados para criar ou atualizar o administrador informado durante a instalação.
                    </p>
                    <div class="grid">
                        <div>
                            <label for="admin_name">Nome</label>
                            <input id="admin_name" name="admin_name" value="<?= esc((string)$formData['admin_name']) ?>">
                        </div>
                        <div>
                            <label for="admin_email">Email</label>
                            <input id="admin_email" name="admin_email" type="email" value="<?= esc((string)$formData['admin_email']) ?>">
                        </div>
                        <div class="full">
                            <label for="admin_pass">Password</label>
                            <input id="admin_pass" name="admin_pass" type="password" minlength="8">
                        </div>
                    </div>
                </fieldset>

                <button type="submit">Instalar / Sincronizar</button>
            </form>
        </div>
    </div>
</body>
</html>

