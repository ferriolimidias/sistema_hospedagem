-- Calendário público: tipo de limite e período opcional (consumido por api/booking_options.php e script.js)
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('calendar_limit_type', 'months')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('calendar_period_start', '')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('calendar_period_end', '')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;

-- Conteúdo institucional (Nossa História + localização) na última linha de `personalizacao`
UPDATE `personalizacao` AS p
INNER JOIN (
    SELECT `id` FROM `personalizacao` ORDER BY `id` DESC LIMIT 1
) AS t ON p.`id` = t.`id`
SET
    p.`about_titulo` = 'Nossa História',
    p.`about_texto` = 'Nossa história começa no coração do litoral catarinense, entre morros, matas preservadas e a brisa constante do mar.\n\nAo longo dos anos, transformamos a paixão por receber pessoas em um compromisso diário com conforto, transparência e respeito ao tempo de cada hóspede. Cuidamos das reservas, dos detalhes da casa e da comunicação para que você só precise relaxar.\n\nAqui, acreditamos que viagem boa é feita de pequenos gestos: café pronto cedo, orientações sinceras sobre a região e a tranquilidade de saber que está em boas mãos.\n\nSeja bem-vindo. A praia espera — e nós também.',
    p.`loc_endereco` = 'Localização privilegiada no coração do litoral catarinense, próximo às principais praias e serviços da região.',
    p.`loc_carro` = 'De carro, o acesso costuma ser tranquilo a partir de Florianópolis e cidades vizinhas; siga as orientações do GPS e, em temporada, reserve alguns minutos extras para estacionar com calma.';
