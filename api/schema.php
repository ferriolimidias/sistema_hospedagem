<?php
declare(strict_types=1);

function runInitialSchema(PDO $pdo): void
{
    $queries = [
        "CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
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
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS reservations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            guest_name VARCHAR(255) NOT NULL,
            guest_email VARCHAR(255) NULL,
            guest_phone VARCHAR(50) NULL,
            guests_adults INT NOT NULL DEFAULT 2,
            guests_children INT NOT NULL DEFAULT 0,
            chalet_id INT NOT NULL,
            checkin_date DATE NOT NULL,
            checkout_date DATE NOT NULL,
            total_amount DECIMAL(10,2) NOT NULL,
            payment_rule VARCHAR(20) NOT NULL DEFAULT 'full',
            status VARCHAR(50) NOT NULL DEFAULT 'Confirmada',
            expires_at DATETIME NULL,
            mp_init_point TEXT NULL,
            contract_filename VARCHAR(255) NULL,
            balance_paid TINYINT(1) NOT NULL DEFAULT 0,
            balance_paid_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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
    ];

    foreach ($queries as $query) {
        $pdo->exec($query);
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
}

