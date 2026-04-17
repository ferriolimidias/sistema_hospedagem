<?php
// Produção: não exibir erros ao usuário; desenvolvimento: exibir
$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$|\.(local|test)$/i', $host);
$isProd = (getenv('APP_ENV') === 'production' || (isset($_SERVER['APP_ENV']) && $_SERVER['APP_ENV'] === 'production') || !$isLocal);
if ($isProd) {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Permitir requisições CORS básicas (no mesmo host)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Tratamento de preflight request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

/**
 * Valida e processa upload de imagem. Retorna caminho relativo ou null.
 * @param array $file elemento de $_FILES
 * @param string $prefix prefixo do nome do arquivo
 * @param string $uploadDir diretório absoluto para salvar
 * @param int $maxBytes tamanho máximo em bytes (default 5MB)
 * @return array ['path' => string|null, 'error' => string|null]
 */
function validateAndSaveImageUpload($file, $prefix, $uploadDir, $maxBytes = 5242880) {
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon'];
    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico'];
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return ['path' => null, 'error' => 'Upload inválido'];
    }
    if ($file['size'] > $maxBytes) {
        return ['path' => null, 'error' => 'Arquivo muito grande (máx. ' . round($maxBytes/1048576) . 'MB)'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt)) {
        return ['path' => null, 'error' => 'Tipo de arquivo não permitido'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowedMimes)) {
        return ['path' => null, 'error' => 'Tipo MIME não permitido'];
    }
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $fileName = time() . '_' . $prefix . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($file['name']));
    if (empty(pathinfo($fileName, PATHINFO_EXTENSION))) $fileName .= '.' . $ext;
    $targetPath = rtrim($uploadDir, '/') . '/' . $fileName;
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['path' => 'images/uploads/' . $fileName, 'error' => null];
    }
    return ['path' => null, 'error' => 'Falha ao salvar arquivo'];
}

// Configurações do Banco de Dados
$host = '127.0.0.1';
$db = 'recantodaserra_db';
$user = 'root';
$pass = ''; // Senha padrão do Laragon é vazia
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    // 4. Criação da tabela de admins
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Popula um admin padrão caso não exista (usa prepared statement)
    $stmtAdmin = $pdo->query("SELECT COUNT(*) FROM admins WHERE email = 'admin@admin.com'");
    if ($stmtAdmin->fetchColumn() == 0) {
        $defaultPassword = password_hash('admin123', PASSWORD_DEFAULT);
        $stmtIns = $pdo->prepare("INSERT INTO admins (email, password) VALUES (?, ?)");
        $stmtIns->execute(['admin@admin.com', $defaultPassword]);
    }

    try {
        $pdo->exec("ALTER TABLE admins ADD COLUMN role VARCHAR(50) DEFAULT 'admin'");
    }
    catch (PDOException $e) {
    }

    try {
        $pdo->exec("ALTER TABLE admins ADD COLUMN name VARCHAR(100) NULL AFTER id");
    }
    catch (PDOException $e) {
    }

    try {
        $pdo->exec("ALTER TABLE admins ADD COLUMN permissions TEXT NULL COMMENT 'JSON array de menus permitidos'");
    }
    catch (PDOException $e) {
    }

    // Popula secretaria padrão caso não exista (usa prepared statement)
    $stmtSec = $pdo->query("SELECT COUNT(*) FROM admins WHERE email = 'secretaria@secretaria.com'");
    if ($stmtSec->fetchColumn() == 0) {
        $secPass = password_hash('secre123', PASSWORD_DEFAULT);
        $stmtIns = $pdo->prepare("INSERT INTO admins (email, password, role) VALUES (?, ?, ?)");
        $stmtIns->execute(['secretaria@secretaria.com', $secPass, 'secretaria']);
    }

    // 5. Novas colunas na tabela chalets (cada uma em try separado para não falhar se já existir)
    $addCol = function ($def) use ($pdo) {
        try { $pdo->exec("ALTER TABLE chalets ADD COLUMN $def"); } catch (PDOException $e) { /* já existe */ }
    };
    $addCol("main_image VARCHAR(255) NULL AFTER name");
    $addCol("badge VARCHAR(50) NULL");
    $addCol("images TEXT NULL");
    $addCol("price_mon DECIMAL(10,2) NULL");
    $addCol("price_tue DECIMAL(10,2) NULL");
    $addCol("price_wed DECIMAL(10,2) NULL");
    $addCol("price_thu DECIMAL(10,2) NULL");
    $addCol("price_fri DECIMAL(10,2) NULL");
    $addCol("price_sat DECIMAL(10,2) NULL");
    $addCol("price_sun DECIMAL(10,2) NULL");
    $addCol("full_description LONGTEXT NULL");

    try {
        $pdo->exec("ALTER TABLE reservations ADD COLUMN payment_rule VARCHAR(20) DEFAULT 'full' AFTER total_amount");
    }
    catch (PDOException $e) {
    }

    try {
        $pdo->exec("ALTER TABLE reservations ADD COLUMN guests_adults INT DEFAULT 2 AFTER guest_phone");
    }
    catch (PDOException $e) {
    }

    try {
        $pdo->exec("ALTER TABLE reservations ADD COLUMN guests_children INT DEFAULT 0 AFTER guests_adults");
    }
    catch (PDOException $e) {
    }

    // 6. Tabela de Configurações (Settings) - logos, integrações
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(255) PRIMARY KEY,
            setting_value LONGTEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    // 6.1. Tabela personalizacao - colunas por sessão
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS personalizacao (
            id INT NOT NULL AUTO_INCREMENT,
            hero_titulo VARCHAR(255) NULL,
            hero_subtitulo TEXT NULL,
            hero_imagens TEXT NULL,
            about_titulo VARCHAR(255) NULL,
            about_texto TEXT NULL,
            about_imagem VARCHAR(500) NULL,
            chalets_subtitulo VARCHAR(255) NULL,
            chalets_titulo VARCHAR(255) NULL,
            chalets_desc TEXT NULL,
            feat1_titulo VARCHAR(255) NULL,
            feat1_desc TEXT NULL,
            feat2_titulo VARCHAR(255) NULL,
            feat2_desc TEXT NULL,
            feat3_titulo VARCHAR(255) NULL,
            feat3_desc TEXT NULL,
            feat4_titulo VARCHAR(255) NULL,
            feat4_desc TEXT NULL,
            feat5_titulo VARCHAR(255) NULL,
            feat5_desc TEXT NULL,
            testi1_nome VARCHAR(100) NULL,
            testi1_local VARCHAR(100) NULL,
            testi1_texto TEXT NULL,
            testi1_imagem VARCHAR(500) NULL,
            testi2_nome VARCHAR(100) NULL,
            testi2_local VARCHAR(100) NULL,
            testi2_texto TEXT NULL,
            testi2_imagem VARCHAR(500) NULL,
            testi3_nome VARCHAR(100) NULL,
            testi3_local VARCHAR(100) NULL,
            testi3_texto TEXT NULL,
            testi3_imagem VARCHAR(500) NULL,
            loc_endereco TEXT NULL,
            loc_carro TEXT NULL,
            loc_map_link VARCHAR(500) NULL,
            wa_numero VARCHAR(20) NULL,
            wa_mensagem TEXT NULL,
            footer_desc TEXT NULL,
            footer_endereco VARCHAR(255) NULL,
            footer_email VARCHAR(255) NULL,
            footer_telefone VARCHAR(50) NULL,
            footer_copyright VARCHAR(255) NULL,
            favicon VARCHAR(500) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 6.2. Seed: insere conteúdo padrão se personalizacao vazia
    $stmtP = $pdo->query("SELECT 1 FROM personalizacao LIMIT 1");
    if ($stmtP->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO personalizacao (hero_titulo, hero_subtitulo, hero_imagens, about_titulo, about_texto, about_imagem, chalets_subtitulo, chalets_titulo, chalets_desc, feat1_titulo, feat1_desc, feat2_titulo, feat2_desc, feat3_titulo, feat3_desc, feat4_titulo, feat4_desc, feat5_titulo, feat5_desc, testi1_nome, testi1_local, testi1_texto, testi1_imagem, testi2_nome, testi2_local, testi2_texto, testi2_imagem, testi3_nome, testi3_local, testi3_texto, testi3_imagem, loc_endereco, loc_carro, loc_map_link, wa_numero, wa_mensagem, footer_desc, footer_endereco, footer_email, footer_telefone, footer_copyright) VALUES ('Seu Refúgio de Luxo na Natureza', 'Desconecte-se da rotina e viva momentos inesquecíveis em nossos chalés exclusivos na serra.', '[\"images/hero.png\"]', 'Uma experiência imersiva', 'Nascido do desejo de integrar conforto absoluto à natureza intocada, o Recantos da Serra oferece uma hospedagem ímpar. Nossos chalés foram projetados para se fundirem com a paisagem, garantindo privacidade, luxo e uma vista de tirar o fôlego.\n\nAcorde com o canto dos pássaros, desfrute de um café da manhã artesanal e relaxe em uma banheira de hidromassagem com vista para o vale.', 'images/chalet3.png', 'Nossas Acomodações', 'Escolha seu Refúgio', 'Designs únicos pensados para proporcionar o máximo de conforto em meio às montanhas.', 'Wi-Fi rápido 📶', 'Internet de alta velocidade para você ficar conectado.', 'Cozinha completa 🍳', 'Cozinha equipada para preparar suas refeições com conforto.', 'Estacionamento 🚗', 'Vaga de estacionamento para seu veículo.', 'Ambiente confortável 🛏️', 'Espaço aconchegante para relaxar e descansar.', 'Pet friendly 🐾', 'Seu amigo de quatro patas é muito bem-vindo aqui.', 'Mariana Costa', 'São Paulo, SP', 'Simplesmente perfeito! O chalé panorâmico nos proporcionou uma vista maravilhosa ao amanhecer. O atendimento e o café da manhã artesanal são impecáveis. Voltaremos com certeza!', 'https://ui-avatars.com/api/?name=Mariana+Costa&background=C89B5F&color=fff', 'Pedro Henrique', 'Belo Horizonte, MG', 'O Ninho de Inverno é o lugar mais aconchegante que já fiquei. A lareira, a adega e o silêncio da montanha criam um ambiente super romântico. Vale cada centavo.', 'https://ui-avatars.com/api/?name=Pedro+Henrique&background=c96621&color=fff', 'Juliana A. & Thor', 'Campinas, SP', 'Muito bom poder viajar com nossa cachorrinha e ser tão bem recebidos. A estrutura A-Frame é linda e tudo estava extremamente limpo e organizado. Nota 10!', 'https://ui-avatars.com/api/?name=Juliana+Alves&background=C89B5F&color=fff', 'Recanto da Serra Eco Park - Serra da Mantiqueira, MG', 'Apenas 2h30 da capital. Estrada 100% asfaltada até a entrada.', 'https://www.google.com/maps/search/?api=1&query=Recanto+da+Serra+Eco+Park', '5535999999999', 'Olá, gostaria de mais informações sobre os chalés!', 'Luxo, conforto e natureza em perfeita harmonia.', 'Serra da Mantiqueira, MG', 'contato@recantosdaserra.com', '(35) 99999-9999', '© 2026 Recantos da Serra. Todos os direitos reservados.')");
    }

    // 7. Tabela de Preços Especiais (Feriados)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS chalet_custom_prices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            chalet_id INT NOT NULL,
            custom_date DATE NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            description VARCHAR(255) NULL,
            FOREIGN KEY (chalet_id) REFERENCES chalets(id) ON DELETE CASCADE,
            UNIQUE KEY unique_date_chalet (chalet_id, custom_date)
        )
    ");

}
catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Erro na conexão com o banco de dados."]);
    exit;
}

// Função auxiliar para padronizar respostas JSON
function jsonResponse($data, $statusCode = 200)
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
?>
