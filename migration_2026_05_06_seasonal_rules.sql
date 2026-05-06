-- Migração: regras sazonais + política de cancelamento
-- Execute este arquivo no banco de produção atual.

CREATE TABLE IF NOT EXISTS `seasonal_rules` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `rule_name` VARCHAR(255) NOT NULL,
  `rule_type` ENUM('period','recurring') NOT NULL DEFAULT 'period',
  `start_date` DATE NULL,
  `end_date` DATE NULL,
  `recurring_days` JSON NULL,
  `min_nights` INT NOT NULL,
  `chalet_id` INT NULL,
  CONSTRAINT `fk_seasonal_rules_chalet` FOREIGN KEY (`chalet_id`) REFERENCES `chalets`(`id`) ON DELETE CASCADE,
  KEY `idx_seasonal_dates` (`start_date`, `end_date`),
  KEY `idx_seasonal_chalet` (`chalet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `settings` (`setting_key`, `setting_value`)
VALUES (
  'cancellation_policy',
  'Cancelamentos com 14 dias ou mais de antecedência têm reembolso integral.\nEntre 13 e 7 dias, devolvemos 50% do valor pago.\nCom menos de 7 dias, não há reembolso, mas o valor pode ser convertido em crédito para nova hospedagem em até 12 meses (1 remarcação, sujeito à disponibilidade e eventual diferença tarifária).\nEm caso de no-show, o valor pago é retido.'
)
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

UPDATE `faqs`
SET `answer` = 'Cancelamentos com 14 dias ou mais de antecedência têm reembolso integral. Entre 13 e 7 dias, devolvemos 50% do valor pago. Com menos de 7 dias, não há reembolso, mas o valor pode ser convertido em crédito para nova hospedagem em até 12 meses (1 remarcação, sujeito à disponibilidade e eventual diferença tarifária). Em caso de no-show, o valor pago é retido.'
WHERE `question` = 'Qual a política de cancelamento?';

INSERT INTO `faqs` (`question`, `answer`, `sort_order`, `is_active`)
SELECT
  'Qual a política de cancelamento?',
  'Cancelamentos com 14 dias ou mais de antecedência têm reembolso integral. Entre 13 e 7 dias, devolvemos 50% do valor pago. Com menos de 7 dias, não há reembolso, mas o valor pode ser convertido em crédito para nova hospedagem em até 12 meses (1 remarcação, sujeito à disponibilidade e eventual diferença tarifária). Em caso de no-show, o valor pago é retido.',
  30,
  1
WHERE NOT EXISTS (
  SELECT 1 FROM `faqs` WHERE `question` = 'Qual a política de cancelamento?' LIMIT 1
);
