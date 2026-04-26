-- Setup master atualizado (estrutura + conteúdo inicial)
-- Compatível com MySQL 8+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `personalizacao` (
  `id` int NOT NULL AUTO_INCREMENT,
  `hero_titulo` varchar(255) DEFAULT NULL,
  `hero_subtitulo` text,
  `hero_imagens` text,
  `about_titulo` varchar(255) DEFAULT NULL,
  `about_texto` text,
  `about_imagem` varchar(500) DEFAULT NULL,
  `chalets_subtitulo` varchar(255) DEFAULT NULL,
  `chalets_titulo` varchar(255) DEFAULT NULL,
  `chalets_desc` text,
  `feat1_titulo` varchar(255) DEFAULT NULL,
  `feat1_desc` text,
  `feat2_titulo` varchar(255) DEFAULT NULL,
  `feat2_desc` text,
  `feat3_titulo` varchar(255) DEFAULT NULL,
  `feat3_desc` text,
  `feat4_titulo` varchar(255) DEFAULT NULL,
  `feat4_desc` text,
  `feat5_titulo` varchar(255) DEFAULT NULL,
  `feat5_desc` text,
  `testi1_nome` varchar(100) DEFAULT NULL,
  `testi1_local` varchar(100) DEFAULT NULL,
  `testi1_texto` text,
  `testi1_imagem` varchar(500) DEFAULT NULL,
  `testi2_nome` varchar(100) DEFAULT NULL,
  `testi2_local` varchar(100) DEFAULT NULL,
  `testi2_texto` text,
  `testi2_imagem` varchar(500) DEFAULT NULL,
  `testi3_nome` varchar(100) DEFAULT NULL,
  `testi3_local` varchar(100) DEFAULT NULL,
  `testi3_texto` text,
  `testi3_imagem` varchar(500) DEFAULT NULL,
  `loc_endereco` text,
  `loc_carro` text,
  `loc_map_link` varchar(500) DEFAULT NULL,
  `loc_map_embed` text,
  `videos_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `videos_json` text,
  `wa_numero` varchar(20) DEFAULT NULL,
  `wa_mensagem` text,
  `footer_desc` text,
  `footer_endereco` varchar(255) DEFAULT NULL,
  `footer_email` varchar(255) DEFAULT NULL,
  `footer_telefone` varchar(50) DEFAULT NULL,
  `footer_copyright` varchar(255) DEFAULT NULL,
  `favicon` varchar(500) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chalets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `type` varchar(100) NOT NULL,
  `badge` varchar(50) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `description` text,
  `full_description` longtext,
  `status` varchar(30) NOT NULL DEFAULT 'Ativo',
  `main_image` varchar(255) DEFAULT NULL,
  `images` text,
  `price_mon` decimal(10,2) DEFAULT NULL,
  `price_tue` decimal(10,2) DEFAULT NULL,
  `price_wed` decimal(10,2) DEFAULT NULL,
  `price_thu` decimal(10,2) DEFAULT NULL,
  `price_fri` decimal(10,2) DEFAULT NULL,
  `price_sat` decimal(10,2) DEFAULT NULL,
  `price_sun` decimal(10,2) DEFAULT NULL,
  `base_guests` int NOT NULL DEFAULT '2',
  `max_guests` int NOT NULL DEFAULT '4',
  `extra_guest_fee` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` varchar(255) NOT NULL,
  `setting_value` longtext,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELETE FROM `personalizacao`;
INSERT INTO `personalizacao` (
  `hero_titulo`, `hero_subtitulo`, `hero_imagens`, `about_titulo`, `about_texto`, `about_imagem`,
  `chalets_subtitulo`, `chalets_titulo`, `chalets_desc`,
  `feat1_titulo`, `feat1_desc`, `feat2_titulo`, `feat2_desc`, `feat3_titulo`, `feat3_desc`,
  `feat4_titulo`, `feat4_desc`, `feat5_titulo`, `feat5_desc`,
  `testi1_nome`, `testi1_local`, `testi1_texto`,
  `testi2_nome`, `testi2_local`, `testi2_texto`,
  `testi3_nome`, `testi3_local`, `testi3_texto`,
  `loc_endereco`, `loc_carro`, `loc_map_link`, `loc_map_embed`,
  `videos_enabled`, `videos_json`,
  `wa_numero`, `wa_mensagem`,
  `footer_desc`, `footer_endereco`, `footer_email`, `footer_telefone`, `footer_copyright`
) VALUES (
  'Bem-vindo ao Sistema Modelo',
  'Gestão completa para reservas, check-in e experiência digital de hospedagem.',
  '[]',
  'Atrativos',
  'Praia do Simão: faixa de areia preservada, mar de águas claras e cenário ideal para dias de descanso em família.\n\nPraia de Calheiros: conhecida pela tranquilidade, boa estrutura no entorno e excelente opção para contemplar o pôr do sol.\n\nTrilha João do Campo: percurso em meio à natureza com nível moderado, recomendado para quem busca contato com a mata local.\n\nTrilha de Ganchos de Fora: rota com vistas panorâmicas e pontos estratégicos para fotografia.\n\nE-book do destino: material digital com dicas de passeios, gastronomia e orientações práticas para aproveitar a estadia.',
  '',
  'Acomodações',
  'Unidades disponíveis',
  'Estruturas preparadas para lazer e conforto em diferentes perfis de viagem.',
  'Wi-Fi',
  'Internet de alta velocidade em todas as unidades.',
  'Cozinha',
  'Cozinha equipada para refeições práticas durante a hospedagem.',
  'Estacionamento',
  'Vaga no local conforme disponibilidade.',
  'Conforto',
  'Enxoval completo e ambientes planejados para descanso.',
  'Spa',
  'Unidades selecionadas com experiência de imersão e relaxamento.',
  'Hóspede Exemplo',
  'Avaliação verificada',
  'Excelente estadia, ambiente limpo e atendimento impecável.',
  'Hóspede Exemplo',
  'Avaliação verificada',
  'Processo de reserva simples e comunicação muito clara.',
  'Hóspede Exemplo',
  'Avaliação verificada',
  'Estrutura completa e ótima organização em toda a jornada.',
  'Endereço do estabelecimento',
  'Personalize aqui as orientações de acesso por carro.',
  'https://www.google.com/maps',
  '',
  0,
  '[]',
  '5500000000000',
  'Olá, {nome}. Recebemos seu contato sobre disponibilidade em {pousada}.',
  'Plataforma de hospedagem preparada para reservas diretas e gestão profissional.',
  'Cidade/UF',
  'contato@meuestabelecimento.com',
  '(00) 00000-0000',
  '© 2026 Todos os direitos reservados.'
);

DELETE FROM `chalets`;
INSERT INTO `chalets` (
  `name`, `type`, `badge`, `price`, `description`, `full_description`, `status`,
  `main_image`, `images`,
  `price_mon`, `price_tue`, `price_wed`, `price_thu`, `price_fri`, `price_sat`, `price_sun`,
  `base_guests`, `max_guests`, `extra_guest_fee`
) VALUES
(
  'Flat com Spa 01', 'Flat', 'Destaque', 450.00,
  'Unidade com quarto climatizado, cozinha funcional e enxoval completo.',
  'Flat com Spa 01\n\nQuarto: cama casal, ar-condicionado, TV e armário de apoio.\nCozinha: cooktop, frigobar, micro-ondas, utensílios e bancada.\nEnxoval: roupa de cama, toalhas e itens essenciais para estadia.\nSpa: área privativa com banheira de imersão para momentos de relaxamento.',
  'Ativo', '', '[]',
  450.00, 450.00, 450.00, 450.00, 550.00, 550.00, 550.00,
  2, 4, 0.00
),
(
  'Flat com Spa 02', 'Flat', 'Destaque', 450.00,
  'Flat completo com estrutura técnica para estadias curtas e longas.',
  'Flat com Spa 02\n\nQuarto: cama casal, climatização, TV e iluminação aconchegante.\nCozinha: equipada com eletros essenciais e utensílios completos.\nEnxoval: kit de cama e banho com reposição programada.\nSpa: banheira com espaço de apoio para experiência de conforto.',
  'Ativo', '', '[]',
  450.00, 450.00, 450.00, 450.00, 550.00, 550.00, 550.00,
  2, 4, 0.00
),
(
  'Estúdio com Banheira', 'Estúdio', 'Premium', 550.00,
  'Estúdio premium com banheira e layout integrado.',
  'Estúdio com Banheira\n\nQuarto: layout integrado com cama casal, ar-condicionado e TV.\nCozinha: compacta e equipada com itens para preparo de refeições.\nEnxoval: enxoval completo de cama e banho para toda a estadia.\nSpa: banheira interna para uso privativo e relaxamento.',
  'Ativo', '', '[]',
  550.00, 550.00, 550.00, 550.00, 625.00, 625.00, 625.00,
  2, 4, 0.00
),
(
  'Studio Casal 01', 'Studio', NULL, 550.00,
  'Studio casal com cozinha compacta e conforto térmico.',
  'Studio Casal 01\n\nQuarto: cama casal, ar-condicionado, smart TV e bancada de apoio.\nCozinha: frigobar, micro-ondas, cooktop e utensílios básicos.\nEnxoval: toalhas, lençóis e itens de hospitalidade.\nSpa: unidade preparada para experiência de descanso com área de relaxamento.',
  'Ativo', '', '[]',
  550.00, 550.00, 550.00, 550.00, 625.00, 625.00, 625.00,
  2, 3, 0.00
),
(
  'Studio Casal 02', 'Studio', NULL, 550.00,
  'Studio casal com infraestrutura completa para hospedagem.',
  'Studio Casal 02\n\nQuarto: cama casal, climatização e armário funcional.\nCozinha: estrutura compacta com eletros essenciais e utensílios.\nEnxoval: cama e banho completos para o período contratado.\nSpa: proposta de bem-estar com espaço dedicado ao relaxamento.',
  'Ativo', '', '[]',
  550.00, 550.00, 550.00, 550.00, 625.00, 625.00, 625.00,
  2, 3, 0.00
);

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('site_title', 'Meu Estabelecimento'),
('meta_description', 'Plataforma completa para gestão de reservas, check-in online e controle de hospedagem.'),
('primary_color', '#2563eb'),
('secondary_color', '#1e293b'),
('manual_pix_instructions', 'Para confirmar a reserva, realizamos o pagamento em PIX em 2 etapas: 50% no ato da contratação e 50% até o check-in. Após o envio do comprovante, o contrato digital é liberado para assinatura.'),
('pre_checkin_message', 'Olá, {nome}! Sua reserva em {pousada} está confirmada para {checkin} a {checkout}. Para agilizar sua chegada, finalize o pré-check-in no link enviado pela equipe.'),
('wa_mensagem', 'Olá, gostaria de informações sobre disponibilidade e política de pagamento (PIX em 2x com contrato) da {pousada}.')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

SET FOREIGN_KEY_CHECKS = 1;
