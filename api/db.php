<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/session_init.php';

// Produção: não exibir erros ao usuário; desenvolvimento: exibir
$requestHost = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$|\.(local|test)$/i', $requestHost);
$isProd = (getenv('APP_ENV') === 'production' || (isset($_SERVER['APP_ENV']) && $_SERVER['APP_ENV'] === 'production') || !$isLocal);
if ($isProd) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// CORS com credenciais e origem dinâmica
$origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_HOST'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Internal-Key');

// Tratamento de preflight request
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Valida e processa upload de imagem. Retorna caminho relativo ou null.
 * @param array $file elemento de $_FILES
 * @param string $prefix prefixo do nome do arquivo
 * @param string $uploadDir diretório absoluto para salvar
 * @param int $maxBytes tamanho máximo em bytes (default 5MB)
 * @return array{path: string|null, error: string|null}
 */
function validateAndSaveImageUpload(array $file, string $prefix, string $uploadDir, int $maxBytes = 5242880): array
{
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon'];
    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['path' => null, 'error' => 'Upload inválido'];
    }
    if (($file['size'] ?? 0) > $maxBytes) {
        return ['path' => null, 'error' => 'Arquivo muito grande (máx. ' . round($maxBytes / 1048576) . 'MB)'];
    }
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return ['path' => null, 'error' => 'Tipo de arquivo não permitido'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowedMimes, true)) {
        return ['path' => null, 'error' => 'Tipo MIME não permitido'];
    }
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '', basename((string)$file['name']));
    $fileName = time() . '_' . $prefix . '_' . $safeName;
    if (empty(pathinfo($fileName, PATHINFO_EXTENSION))) {
        $fileName .= '.' . $ext;
    }
    $targetPath = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $fileName;
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['path' => 'images/uploads/' . $fileName, 'error' => null];
    }
    return ['path' => null, 'error' => 'Falha ao salvar arquivo'];
}

/**
 * Remove com segurança um ficheiro da pasta de uploads.
 * Aceita apenas caminhos relativos dentro de images/uploads/ e ignora URLs externas,
 * caminhos suspeitos (../, ..\), data URIs e ficheiros fora da raiz do projeto.
 *
 * @param string|null $path caminho relativo guardado no banco (ex: images/uploads/xpto.jpg)
 * @return bool true se removeu ou se já não existia dentro da pasta permitida
 */
function safeDeleteUploadedImage(?string $path): bool
{
    $rel = trim((string)$path);
    if ($rel === '') return false;
    if (preg_match('/^https?:\/\//i', $rel) === 1) return false;
    if (stripos($rel, 'data:') === 0) return false;

    $rel = str_replace('\\', '/', $rel);
    $rel = ltrim($rel, '/');
    if (strpos($rel, '..') !== false) return false;

    $allowedPrefix = 'images/uploads/';
    if (strpos($rel, $allowedPrefix) !== 0) return false;

    $projectRoot = realpath(__DIR__ . '/..');
    $uploadsRoot = realpath(__DIR__ . '/../images/uploads');
    if ($projectRoot === false || $uploadsRoot === false) return false;

    $abs = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $absReal = realpath($abs);
    if ($absReal === false) {
        return false;
    }
    if (strpos($absReal, $uploadsRoot . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }
    if (!is_file($absReal)) return false;

    return @unlink($absReal);
}

function jsonResponse($data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

$configPath = __DIR__ . '/../config/database.php';
if (!file_exists($configPath)) {
    jsonResponse([
        'status' => 'error',
        'message' => 'Configuração do banco não encontrada. Execute /setup.php.'
    ], 503);
}

$dbConfig = require $configPath;
if (!is_array($dbConfig)) {
    jsonResponse(['status' => 'error', 'message' => 'Configuração do banco inválida.'], 500);
}

$dbHost = $dbConfig['host'] ?? '';
$dbName = $dbConfig['dbname'] ?? '';
$dbUser = $dbConfig['user'] ?? '';
$dbPass = $dbConfig['pass'] ?? '';
$dbCharset = $dbConfig['charset'] ?? 'utf8mb4';

if ($dbHost === '' || $dbName === '' || $dbUser === '') {
    jsonResponse(['status' => 'error', 'message' => 'Configuração do banco incompleta.'], 500);
}

try {
    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    jsonResponse(['status' => 'error', 'message' => 'Erro na conexão com o banco de dados.'], 500);
}

// Compatibilidade: garante colunas novas de hold/idempotência em bases já criadas.
try {
    $pdo->exec("ALTER TABLE reservations ADD COLUMN expires_at DATETIME NULL AFTER status");
} catch (PDOException $e) {
    // Coluna já existe.
}

try {
    $pdo->exec("ALTER TABLE reservations ADD COLUMN mp_init_point TEXT NULL AFTER expires_at");
} catch (PDOException $e) {
    // Coluna já existe.
}

try {
    $pdo->exec("ALTER TABLE reservations ADD COLUMN contract_filename VARCHAR(255) NULL AFTER mp_init_point");
} catch (PDOException $e) {
    // Coluna já existe.
}

try {
    $pdo->exec("ALTER TABLE reservations ADD COLUMN balance_paid TINYINT(1) NOT NULL DEFAULT 0 AFTER contract_filename");
} catch (PDOException $e) {
    // Coluna já existe.
}

try {
    $pdo->exec("ALTER TABLE reservations ADD COLUMN balance_paid_at DATETIME NULL AFTER balance_paid");
} catch (PDOException $e) {
    // Coluna já existe.
}

try {
    $pdo->exec("ALTER TABLE chalets ADD COLUMN base_guests INT NOT NULL DEFAULT 2 AFTER price_sun");
} catch (PDOException $e) {
    // Coluna já existe.
}

try {
    $pdo->exec("ALTER TABLE chalets ADD COLUMN extra_guest_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER base_guests");
} catch (PDOException $e) {
    // Coluna já existe.
}

try {
    $pdo->exec("ALTER TABLE chalets ADD COLUMN max_guests INT NOT NULL DEFAULT 4 AFTER base_guests");
} catch (PDOException $e) {
    // Coluna já existe.
}

try {
    $pdo->exec('ALTER TABLE reservations ADD COLUMN coupon_code VARCHAR(100) NULL AFTER balance_paid_at');
} catch (PDOException $e) {
    // Coluna já existe.
}

try {
    $pdo->exec('ALTER TABLE reservations ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER coupon_code');
} catch (PDOException $e) {
    // Coluna já existe.
}

try {
    $pdo->exec('ALTER TABLE reservations ADD COLUMN extras_json TEXT NULL AFTER discount_amount');
} catch (PDOException $e) {
    // Coluna já existe.
}

try {
    $pdo->exec('ALTER TABLE reservations ADD COLUMN extras_total DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER extras_json');
} catch (PDOException $e) {
    // Coluna já existe.
}

try {
    $pdo->exec('ALTER TABLE reservations ADD COLUMN fnrh_access_token VARCHAR(64) NULL AFTER extras_total');
} catch (PDOException $e) {
    // Coluna já existe.
}

try {
    $pdo->exec('CREATE UNIQUE INDEX uq_reservations_fnrh_token ON reservations (fnrh_access_token)');
} catch (PDOException $e) {
    // Índice já existe.
}

try {
    $pdo->exec('ALTER TABLE reservations ADD COLUMN fnrh_data TEXT NULL AFTER fnrh_access_token');
} catch (PDOException $e) {
    // Coluna já existe.
}

/* =============================================================
 * Check-in 360º — campos dedicados da FNRH (Fase 1).
 * Mantemos fnrh_data como histórico/backup; as novas colunas
 * permitem leitura direta e filtros no admin.
 * ============================================================= */
$checkinColumns = [
    "ALTER TABLE reservations ADD COLUMN guest_cpf VARCHAR(14) NULL AFTER guest_phone",
    "ALTER TABLE reservations ADD COLUMN guest_address TEXT NULL AFTER guest_cpf",
    "ALTER TABLE reservations ADD COLUMN guest_car_plate VARCHAR(16) NULL AFTER guest_address",
    "ALTER TABLE reservations ADD COLUMN guest_companion_names TEXT NULL AFTER guest_car_plate",
    "ALTER TABLE reservations ADD COLUMN fnrh_status VARCHAR(32) NOT NULL DEFAULT 'pendente' AFTER fnrh_data",
    "ALTER TABLE reservations ADD COLUMN fnrh_submitted_at DATETIME NULL AFTER fnrh_status",
    "ALTER TABLE reservations ADD COLUMN fnrh_last_response TEXT NULL AFTER fnrh_submitted_at",
];
foreach ($checkinColumns as $sql) {
    try { $pdo->exec($sql); } catch (PDOException $e) { /* coluna já existe */ }
}

try {
    $pdo->exec('ALTER TABLE reservations ADD COLUMN additional_value DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER total_amount');
} catch (PDOException $e) {
    // Coluna já existe.
}

try {
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('checkin_time', '14:00') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute();
} catch (PDOException $e) {
    // Chave já existe ou tabela sem constraint única.
}

try {
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('checkout_time', '12:00') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute();
} catch (PDOException $e) {
    // Chave já existe ou tabela sem constraint única.
}

try {
    $defaultPolicies = json_encode([
        ['code' => 'half', 'label' => 'Sinal de 50% para reserva', 'percent_now' => 50],
        ['code' => 'full', 'label' => 'Pagamento 100% Antecipado', 'percent_now' => 100],
    ], JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('payment_policies', ?) ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute([$defaultPolicies]);
} catch (PDOException $e) {
    // Chave já existe ou tabela sem constraint única.
}

try {
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('site_title', 'Meu Estabelecimento') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute();
} catch (PDOException $e) {
    // Chave já existe.
}

try {
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('meta_description', 'Plataforma completa para gestão de reservas, check-in online e controle de hospedagem.') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute();
} catch (PDOException $e) {
    // Chave já existe.
}

try {
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('primary_color', '#2563eb') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute();
} catch (PDOException $e) {
    // Chave já existe.
}

try {
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('secondary_color', '#1e293b') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute();
} catch (PDOException $e) {
    // Chave já existe.
}

// --- Métodos de pagamento (híbrido: Mercado Pago automático + PIX manual via WhatsApp) ---
try {
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('payment_mercadopago_active', '1') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute();
} catch (PDOException $e) { /* chave já existe */ }

try {
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('payment_manual_pix_active', '0') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute();
} catch (PDOException $e) { /* chave já existe */ }

try {
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('manual_pix_key', '') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute();
} catch (PDOException $e) { /* chave já existe */ }

try {
    $defaultInstructions = 'Olá! Realizei uma pré-reserva em {pousada}. Segue o comprovante do PIX para validação do pagamento. Obrigado(a).';
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('manual_pix_instructions', ?) ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute([$defaultInstructions]);
} catch (PDOException $e) { /* chave já existe */ }

// Integração FNRH (Fase 1 — Check-in 360º).
try {
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('fnrh_active', '0') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute();
} catch (PDOException $e) { /* chave já existe */ }
try {
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('fnrh_api_key', '') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute();
} catch (PDOException $e) { /* chave já existe */ }
try {
    $defaultPreCheckinMsg = "Olá, {nome}! Sua reserva em {pousada} está confirmada para {checkin} — {checkout}.\n\nPara agilizar seu atendimento, finalize o pré-check-in online neste link seguro:\n{link}\n\nSe precisar de suporte, estamos à disposição.";
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('pre_checkin_message', ?) ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute([$defaultPreCheckinMsg]);
} catch (PDOException $e) { /* chave já existe */ }

// Comunicação e Integrações (Evolution API nativa)
try { $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('evo_url', '') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute(); } catch (PDOException $e) { /* chave já existe */ }
try { $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('evo_instance', '') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute(); } catch (PDOException $e) { /* chave já existe */ }
try { $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('evo_apikey', '') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute(); } catch (PDOException $e) { /* chave já existe */ }
try { $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('evo_notify_reserva', '1') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute(); } catch (PDOException $e) { /* chave já existe */ }
try { $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('evo_notify_checkin', '1') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute(); } catch (PDOException $e) { /* chave já existe */ }
try { $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('evo_notify_checkout', '1') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute(); } catch (PDOException $e) { /* chave já existe */ }

// Coluna payment_method identifica se a reserva veio do MP (automática) ou manual (PIX/WhatsApp).
try {
    $pdo->exec("ALTER TABLE reservations ADD COLUMN IF NOT EXISTS payment_method VARCHAR(32) NOT NULL DEFAULT 'mercadopago'");
} catch (PDOException $e) { /* MySQL < 8 pode não suportar IF NOT EXISTS */ }
try {
    $pdo->exec("ALTER TABLE reservations ADD COLUMN payment_method VARCHAR(32) NOT NULL DEFAULT 'mercadopago'");
} catch (PDOException $e) { /* coluna já existe */ }

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS coupons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(64) NOT NULL,
        type ENUM('fixed', 'percent') NOT NULL DEFAULT 'percent',
        value DECIMAL(10,2) NOT NULL,
        expiry_date DATE NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_coupons_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    // Tabela já existe ou erro de permissão.
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS extra_services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        description TEXT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    // Tabela já existe ou erro de permissão.
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reservation_consumptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reservation_id INT NOT NULL,
        description VARCHAR(255) NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_consumptions_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
        KEY idx_consumptions_reservation (reservation_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    // Tabela já existe ou erro de permissão.
}

// FAQs — perguntas frequentes geríveis pelo admin.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS faqs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        question VARCHAR(500) NOT NULL,
        answer TEXT NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_faqs_order (is_active, sort_order, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    // Tabela já existe ou erro de permissão.
}

function cleanupExpiredPendingReservations(PDO $pdo): void
{
    $stmt = $pdo->prepare("
        UPDATE reservations
        SET status = 'Expirada'
        WHERE status = 'Aguardando Pagamento'
          AND expires_at IS NOT NULL
          AND expires_at <= NOW()
    ");
    $stmt->execute();
}
