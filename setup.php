<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/api/schema.php';

$configDir = __DIR__ . '/config';
$configPath = $configDir . '/database.php';
$errors = [];
$success = false;
$message = '';

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

    // Admin: só exigimos os dados quando for primeira instalação (tabela admins vazia).
    // Se o banco já tiver administradores, preservamos os existentes e ignoramos os campos.
    $requireAdminFields = true;
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

            // Sincronização de schema (migrator): cria tabelas que faltam e adiciona colunas novas,
            // sem apagar dados existentes. Todas as operações são idempotentes.
            // Sem transação: CREATE/ALTER TABLE no MySQL fazem commit implícito.
            runInitialSchema($pdo);

            // Decide se precisamos criar o primeiro administrador ou preservar os existentes.
            $existingAdmins = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
            $requireAdminFields = $existingAdmins === 0;

            if ($requireAdminFields && !empty($adminFieldErrors)) {
                // Primeira instalação exige os dados do admin.
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

            if ($requireAdminFields) {
                // Banco vazio → cria o primeiro administrador informado.
                $insertAdmin = $pdo->prepare(
                    'INSERT INTO admins (name, email, password, role, permissions) VALUES (?, ?, ?, ?, ?)'
                );
                $insertAdmin->execute([
                    $adminName,
                    $adminEmail,
                    password_hash($adminPass, PASSWORD_DEFAULT),
                    'admin',
                    json_encode(['dashboard', 'reservas', 'chales', 'usuarios', 'configuracoes', 'financeiro']),
                ]);
                $adminMsg = 'Primeiro administrador criado com sucesso.';
            } else {
                // Banco com admins existentes → preservados integralmente (senhas, permissões, etc.).
                $adminMsg = "Administradores existentes preservados ({$existingAdmins}). Use o login atual para aceder.";
            }

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
            --primary: #c96621;
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
        ul { margin: .4rem 0 0 1.2rem; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Instalação e Sincronização</h1>
            <p class="lead">
                Configure a base de dados e (na primeira instalação) o administrador inicial.
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
                    <legend>Primeiro Administrador (apenas em base vazia)</legend>
                    <p style="margin:.2rem 0 .9rem; color:var(--muted); font-size:.88rem;">
                        Estes campos são usados apenas se o banco ainda não tiver nenhum administrador.
                        Se já existir algum admin, estes valores são <strong>ignorados</strong> para preservar o login atual.
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

