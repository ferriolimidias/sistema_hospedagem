<?php
declare(strict_types=1);

/**
 * Raiz do projeto (pasta que contém /api e /images).
 */
function projectRootPath(): string
{
    return dirname(__DIR__);
}

/**
 * Caminho absoluto da pasta images/.
 */
function projectImagesRoot(): string
{
    return projectRootPath() . DIRECTORY_SEPARATOR . 'images';
}

/**
 * Devolve o caminho Web relativo (ex.: images/hero.png) se o ficheiro existir no disco; caso contrário string vazia.
 * Equivalente a: file_exists(__DIR__ . '/../images/hero.png') ? 'images/hero.png' : ''
 */
function assetRelativeIfExists(string $relativeWebPath): string
{
    $relativeWebPath = str_replace('\\', '/', $relativeWebPath);
    $full = projectRootPath() . '/' . $relativeWebPath;
    return is_file($full) ? $relativeWebPath : '';
}

/**
 * Primeiro nome de ficheiro da lista que existir em images/.
 *
 * @param list<string> $basenames Apenas o nome do ficheiro (ex.: 'logo.png')
 */
function firstExistingAssetRelPath(array $basenames): string
{
    foreach ($basenames as $name) {
        $rel = 'images/' . ltrim(str_replace('\\', '/', (string) $name), '/');
        if (assetRelativeIfExists($rel) !== '') {
            return $rel;
        }
    }
    return '';
}

/**
 * Lista caminhos relativos para imagens de hero (ficheiros hero* na raiz de images/).
 *
 * @return list<string>
 */
function discoverHeroImagePaths(): array
{
    $dir = projectImagesRoot();
    if (!is_dir($dir)) {
        $one = assetRelativeIfExists('images/hero.png');
        return $one !== '' ? [$one] : [];
    }
    $paths = [];
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
        foreach (glob($dir . '/hero*.' . $ext) ?: [] as $full) {
            if (is_file($full)) {
                $paths[] = 'images/' . basename($full);
            }
        }
    }
    $paths = array_values(array_unique($paths));
    sort($paths);
    $hero = assetRelativeIfExists('images/hero.png');
    if ($hero !== '' && in_array('images/hero.png', $paths, true)) {
        $paths = array_values(array_unique(array_merge(['images/hero.png'], array_diff($paths, ['images/hero.png']))));
    }
    if ($paths === [] && $hero !== '') {
        return [$hero];
    }
    return $paths;
}

/**
 * Após criar tabelas: insere personalização, logos em settings e chalés de demonstração quando as tabelas estão vazias.
 */
function runInitialDataSeed(PDO $pdo): void
{
    $heroJson = json_encode(discoverHeroImagePaths(), JSON_UNESCAPED_UNICODE);
    if ($heroJson === '[]' || $heroJson === false) {
        $heroJson = '[]';
    }

    $aboutImg = assetRelativeIfExists('images/chalet3.png');
    if ($aboutImg === '') {
        $aboutImg = firstExistingAssetRelPath(['about.png', 'about.jpg', 'chalet3.jpg']);
    }

    $testi1 = firstExistingAssetRelPath(['testi1.png', 'testi1.jpg']);
    $testi2 = firstExistingAssetRelPath(['testi2.png', 'testi2.jpg']);
    $testi3 = firstExistingAssetRelPath(['testi3.png', 'testi3.jpg']);
    $favicon = firstExistingAssetRelPath(['favicon.ico', 'favicon.png']);

    $stmt = $pdo->query('SELECT COUNT(*) FROM personalizacao');
    $pc = $stmt ? (int) $stmt->fetchColumn() : 0;
    if ($pc === 0) {
        $aboutText = "Bem-vindo ao seu estabelecimento. Este sistema foi preparado para apresentar acomodações, diferenciais e experiências com total flexibilidade de marca.\n\nPersonalize este texto com a identidade do seu hotel ou pousada.";
        $ins = $pdo->prepare(
            'INSERT INTO personalizacao (
                hero_titulo, hero_subtitulo, hero_imagens, about_titulo, about_texto, about_imagem,
                chalets_subtitulo, chalets_titulo, chalets_desc,
                feat1_titulo, feat1_desc, feat2_titulo, feat2_desc, feat3_titulo, feat3_desc, feat4_titulo, feat4_desc, feat5_titulo, feat5_desc,
                testi1_nome, testi1_local, testi1_texto, testi1_imagem,
                testi2_nome, testi2_local, testi2_texto, testi2_imagem,
                testi3_nome, testi3_local, testi3_texto, testi3_imagem,
                loc_endereco, loc_carro, loc_map_link, wa_numero, wa_mensagem,
                footer_desc, footer_endereco, footer_email, footer_telefone, footer_copyright, favicon
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?
            )'
        );
        $ins->execute([
            'Bem-vindo ao Sistema Modelo',
            'Personalize textos, imagens e conteúdos para refletir a identidade do seu estabelecimento.',
            $heroJson,
            'Uma experiência imersiva',
            $aboutText,
            $aboutImg,
            'Nossas Acomodações',
            'Conheça Nossas Acomodações',
            'Estruturas confortáveis e funcionais para diferentes perfis de hóspedes.',
            'Wi-Fi rápido 📶',
            'Internet de alta velocidade para você ficar conectado.',
            'Cozinha completa 🍳',
            'Cozinha equipada para preparar suas refeições com conforto.',
            'Estacionamento 🚗',
            'Vaga de estacionamento para seu veículo.',
            'Ambiente confortável 🛏️',
            'Espaço aconchegante para relaxar e descansar.',
            'Pet friendly 🐾',
            'Seu amigo de quatro patas é muito bem-vindo aqui.',
            'Hóspede Exemplo',
            'Avaliação verificada',
            'Excelente estadia, ambiente limpo e atendimento impecável.',
            $testi1,
            'Hóspede Exemplo',
            'Avaliação verificada',
            'Processo de reserva simples, quarto confortável e ótima organização.',
            $testi2,
            'Hóspede Exemplo',
            'Avaliação verificada',
            'Ótima experiência geral, check-in rápido e suporte atencioso durante toda a hospedagem.',
            $testi3,
            'Endereço do estabelecimento',
            'Informações de acesso e deslocamento podem ser personalizadas pelo estabelecimento.',
            'https://www.google.com/maps',
            '5500000000000',
            'Olá! Gostaria de mais informações sobre disponibilidade e valores em {pousada}.',
            'Estrutura completa para uma experiência de hospedagem confortável e segura.',
            'Cidade/UF',
            'contato@meuestabelecimento.com',
            '(00) 00000-0000',
            '© ' . date('Y') . ' Todos os direitos reservados.',
            $favicon,
        ]);
    }

    $logo = firstExistingAssetRelPath(['logo.png', 'logo.jpg']);
    $logoLight = firstExistingAssetRelPath(['logo_light.png', 'logo-light.png', 'logo_light.jpg']);
    if ($logo !== '') {
        $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $st->execute(['company_logo']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || trim((string) ($row['setting_value'] ?? '')) === '') {
            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
                ->execute(['company_logo', $logo]);
        }
    }
    if ($logoLight !== '') {
        $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $st->execute(['company_logo_light']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || trim((string) ($row['setting_value'] ?? '')) === '') {
            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
                ->execute(['company_logo_light', $logoLight]);
        }
    }

    // Seed protegido: FAQs padrão apenas na primeira instalação (tabela vazia).
    try {
        $stmtF = $pdo->query('SELECT COUNT(*) FROM faqs');
        $fc = $stmtF ? (int) $stmtF->fetchColumn() : 0;
    } catch (PDOException $e) {
        $fc = 0;
    }
    if ($fc === 0) {
        $defaultFaqs = [
            [
                'Qual o horário de check-in e check-out?',
                'O check-in é a partir das 14:00 e o check-out até as 12:00. Se precisar de um horário diferente, fale com a nossa equipa pelo WhatsApp — faremos o possível dentro da disponibilidade.',
                10,
            ],
            [
                'Aceitam animais de estimação (pets)?',
                'A política para pets pode variar conforme a acomodação. Consulte a nossa equipa antes de concluir a reserva.',
                20,
            ],
            [
                'Qual a política de cancelamento?',
                'Cancelamentos feitos com mais de 7 dias de antecedência têm reembolso integral do sinal. Entre 7 e 3 dias, devolvemos 50%. Em cima da data, o sinal não é reembolsado, mas pode ser usado como crédito para uma nova estadia dentro de 90 dias.',
                30,
            ],
            [
                'O café da manhã está incluso?',
                'A inclusão de café da manhã depende do plano contratado. Essa informação aparece nos detalhes da reserva.',
                40,
            ],
            [
                'Possuem estacionamento?',
                'Sim, oferecemos estacionamento sujeito à disponibilidade no período da hospedagem.',
                50,
            ],
            [
                'Como funciona o cancelamento?',
                'As condições de cancelamento são informadas no momento da reserva e no comprovante enviado ao hóspede.',
                60,
            ],
            [
                'Há Wi-Fi nas acomodações?',
                'Sim, disponibilizamos acesso Wi-Fi nas áreas comuns e acomodações.',
                70,
            ],
            [
                'Quais formas de pagamento são aceitas?',
                'Trabalhamos com pagamento online e opções manuais configuradas pelo estabelecimento.',
                80,
            ],
            [
                'É possível solicitar check-in antecipado ou check-out tardio?',
                'Sim, mediante disponibilidade e eventual cobrança adicional. Consulte a equipa com antecedência.',
                90,
            ],
        ];
        $insF = $pdo->prepare('INSERT INTO faqs (question, answer, sort_order, is_active) VALUES (?, ?, ?, 1)');
        foreach ($defaultFaqs as $f) {
            $insF->execute([$f[0], $f[1], $f[2]]);
        }
    }

    $stmt = $pdo->query('SELECT COUNT(*) FROM chalets');
    $cc = $stmt ? (int) $stmt->fetchColumn() : 0;
    if ($cc === 0) {
        $demos = [
            ['Quarto Standard', 'Standard', 'Acomodação confortável com ar-condicionado, TV e Wi-Fi.', 'images/chalet1.png', ['chalet1.jpg']],
            ['Suíte Premium', 'Premium', 'Suíte com ambiente amplo, cama queen, frigobar e amenities selecionados.', 'images/chalet2.png', ['chalet2.jpg']],
            ['Acomodação Familiar', 'Família', 'Espaço ideal para famílias com cozinha compacta, boa circulação e conforto.', 'images/chalet3.png', ['chalet3.jpg']],
        ];
        $insC = $pdo->prepare(
            'INSERT INTO chalets (name, type, badge, price, description, full_description, status, main_image, images, base_guests, max_guests, extra_guest_fee)
             VALUES (?, ?, NULL, 500.00, ?, ?, \'Ativo\', ?, NULL, 2, 4, 0.00)'
        );
        foreach ($demos as $d) {
            $main = assetRelativeIfExists($d[3]);
            if ($main === '' && isset($d[4])) {
                $main = firstExistingAssetRelPath($d[4]);
            }
            $insC->execute([$d[0], $d[1], $d[2], $d[2], $main !== '' ? $main : null]);
        }
    }
}

function runInitialSchema(PDO $pdo): void
{
    $queries = [
        "CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            auth_token VARCHAR(255) NULL,
            role VARCHAR(50) NOT NULL DEFAULT 'admin',
            permissions TEXT NULL COMMENT 'JSON array de menus permitidos',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS chalets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            type VARCHAR(100) NOT NULL,
            badge VARCHAR(50) NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            description TEXT NULL,
            full_description LONGTEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Ativo',
            main_image VARCHAR(255) NULL,
            images TEXT NULL,
            price_mon DECIMAL(10,2) NULL,
            price_tue DECIMAL(10,2) NULL,
            price_wed DECIMAL(10,2) NULL,
            price_thu DECIMAL(10,2) NULL,
            price_fri DECIMAL(10,2) NULL,
            price_sat DECIMAL(10,2) NULL,
            price_sun DECIMAL(10,2) NULL,
            base_guests INT NOT NULL DEFAULT 2,
            max_guests INT NOT NULL DEFAULT 4,
            extra_guest_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS reservations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            guest_name VARCHAR(255) NOT NULL,
            guest_email VARCHAR(255) NULL,
            guest_phone VARCHAR(50) NULL,
            guest_cpf VARCHAR(14) NULL,
            guest_address TEXT NULL,
            guest_car_plate VARCHAR(16) NULL,
            guest_companion_names TEXT NULL,
            guests_adults INT NOT NULL DEFAULT 2,
            guests_children INT NOT NULL DEFAULT 0,
            chalet_id INT NOT NULL,
            checkin_date DATE NOT NULL,
            checkout_date DATE NOT NULL,
            total_amount DECIMAL(10,2) NOT NULL,
            additional_value DECIMAL(10,2) NOT NULL DEFAULT 0,
            payment_rule VARCHAR(20) NOT NULL DEFAULT 'full',
            payment_method VARCHAR(32) NOT NULL DEFAULT 'mercadopago',
            status VARCHAR(50) NOT NULL DEFAULT 'Confirmada',
            expires_at DATETIME NULL,
            mp_init_point TEXT NULL,
            contract_filename VARCHAR(255) NULL,
            balance_paid TINYINT(1) NOT NULL DEFAULT 0,
            balance_paid_at DATETIME NULL,
            fnrh_access_token VARCHAR(64) NULL,
            fnrh_data TEXT NULL,
            fnrh_status VARCHAR(32) NOT NULL DEFAULT 'pendente',
            fnrh_submitted_at DATETIME NULL,
            fnrh_last_response TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_reservations_fnrh_token (fnrh_access_token),
            CONSTRAINT fk_reservations_chalet FOREIGN KEY (chalet_id)
                REFERENCES chalets(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(255) PRIMARY KEY,
            setting_value LONGTEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS personalizacao (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS chalet_custom_prices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            chalet_id INT NOT NULL,
            custom_date DATE NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            description VARCHAR(255) NULL,
            CONSTRAINT fk_chalet_custom_prices_chalet FOREIGN KEY (chalet_id)
                REFERENCES chalets(id) ON DELETE CASCADE,
            UNIQUE KEY unique_date_chalet (chalet_id, custom_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS coupons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(64) NOT NULL,
            type ENUM('fixed', 'percent') NOT NULL DEFAULT 'percent',
            value DECIMAL(10,2) NOT NULL,
            expiry_date DATE NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_coupons_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS extra_services (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            description TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS reservation_consumptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reservation_id INT NOT NULL,
            description VARCHAR(255) NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_consumptions_reservation FOREIGN KEY (reservation_id)
                REFERENCES reservations(id) ON DELETE CASCADE,
            KEY idx_consumptions_reservation (reservation_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS faqs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            question VARCHAR(500) NOT NULL,
            answer TEXT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_faqs_order (is_active, sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($queries as $query) {
        $pdo->exec($query);
    }

    try {
        $pdo->exec("ALTER TABLE admins ADD COLUMN auth_token VARCHAR(255) NULL AFTER password");
    } catch (PDOException $e) {
        // Coluna já existe.
    }

    // Compatibilidade para bases já existentes.
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
        $pdo->exec('ALTER TABLE chalets ADD COLUMN base_guests INT NOT NULL DEFAULT 2 AFTER price_sun');
    } catch (PDOException $e) {
        // Coluna já existe.
    }

    try {
        $pdo->exec('ALTER TABLE chalets ADD COLUMN extra_guest_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER base_guests');
    } catch (PDOException $e) {
        // Coluna já existe.
    }

    try {
        $pdo->exec('ALTER TABLE chalets ADD COLUMN max_guests INT NOT NULL DEFAULT 4 AFTER base_guests');
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

    // Check-in 360º — campos dedicados da FNRH.
    $__checkinCols = [
        "ALTER TABLE reservations ADD COLUMN guest_cpf VARCHAR(14) NULL AFTER guest_phone",
        "ALTER TABLE reservations ADD COLUMN guest_address TEXT NULL AFTER guest_cpf",
        "ALTER TABLE reservations ADD COLUMN guest_car_plate VARCHAR(16) NULL AFTER guest_address",
        "ALTER TABLE reservations ADD COLUMN guest_companion_names TEXT NULL AFTER guest_car_plate",
        "ALTER TABLE reservations ADD COLUMN fnrh_status VARCHAR(32) NOT NULL DEFAULT 'pendente' AFTER fnrh_data",
        "ALTER TABLE reservations ADD COLUMN fnrh_submitted_at DATETIME NULL AFTER fnrh_status",
        "ALTER TABLE reservations ADD COLUMN fnrh_last_response TEXT NULL AFTER fnrh_submitted_at",
    ];
    foreach ($__checkinCols as $__sql) {
        try { $pdo->exec($__sql); } catch (PDOException $e) { /* coluna já existe */ }
    }

    try {
        $pdo->exec('ALTER TABLE reservations ADD COLUMN additional_value DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER total_amount');
    } catch (PDOException $e) {
        // Coluna já existe.
    }

    // Método de pagamento (mercadopago | manual) — diferencia reservas automáticas (MP)
    // das manuais (PIX via WhatsApp). Sem DROP; se a coluna já existir, o erro é ignorado.
    try {
        $pdo->exec("ALTER TABLE reservations ADD COLUMN payment_method VARCHAR(32) NOT NULL DEFAULT 'mercadopago' AFTER payment_rule");
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

    // Métodos de pagamento híbridos (MP automático / PIX manual via WhatsApp).
    try {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('payment_mercadopago_active', '1') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute();
    } catch (PDOException $e) { /* existe */ }
    try {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('payment_manual_pix_active', '0') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute();
    } catch (PDOException $e) { /* existe */ }
    try {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('manual_pix_key', '') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute();
    } catch (PDOException $e) { /* existe */ }
    try {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('manual_pix_instructions', 'Olá! Realizei uma pré-reserva em {pousada}. Segue o comprovante do PIX para validação do pagamento. Obrigado(a).') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute();
    } catch (PDOException $e) { /* existe */ }

    // Integração FNRH (Check-in 360º).
    try {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('fnrh_active', '0') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute();
    } catch (PDOException $e) { /* existe */ }
    try {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('fnrh_api_key', '') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute();
    } catch (PDOException $e) { /* existe */ }
    try {
        $__defaultPreCheckin = "Olá, {nome}! Sua reserva em {pousada} está confirmada para {checkin} — {checkout}.\n\nPara agilizar seu atendimento, finalize o pré-check-in online neste link seguro:\n{link}\n\nSe precisar de suporte, estamos à disposição.";
        $st = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('pre_checkin_message', ?) ON DUPLICATE KEY UPDATE setting_value = setting_value");
        $st->execute([$__defaultPreCheckin]);
    } catch (PDOException $e) { /* existe */ }

    // Comunicação e Integrações (Evolution API nativa)
    try { $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('evo_url', '') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute(); } catch (PDOException $e) { /* existe */ }
    try { $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('evo_instance', '') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute(); } catch (PDOException $e) { /* existe */ }
    try { $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('evo_apikey', '') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute(); } catch (PDOException $e) { /* existe */ }
    try { $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('evo_notify_reserva', '1') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute(); } catch (PDOException $e) { /* existe */ }
    try { $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('evo_notify_checkin', '1') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute(); } catch (PDOException $e) { /* existe */ }
    try { $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('evo_notify_checkout', '1') ON DUPLICATE KEY UPDATE setting_value = setting_value")->execute(); } catch (PDOException $e) { /* existe */ }

    runInitialDataSeed($pdo);
}

