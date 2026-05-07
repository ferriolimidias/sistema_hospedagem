-- Melhorias comerciais de reserva: taxas globais, descontos por noites,
-- idade das crianças, pet e bloqueios explícitos por status.

ALTER TABLE `reservations`
  ADD COLUMN IF NOT EXISTS `children_ages` JSON NULL AFTER `guests_children`;

ALTER TABLE `reservations`
  ADD COLUMN IF NOT EXISTS `brings_pet` TINYINT(1) NOT NULL DEFAULT 0 AFTER `children_ages`;

CREATE TABLE IF NOT EXISTS `stay_discounts` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `min_nights` INT NOT NULL,
  `discount_percentage` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stay_discounts_min_nights` (`min_nights`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('cleaning_fee', '0'),
  ('pet_fee', '0'),
  ('calendar_max_months', '6')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;

-- A coluna reservations.status já é VARCHAR no projeto atual.
-- O novo status operacional "Bloqueado" não requer ALTER quando a base está atualizada.
