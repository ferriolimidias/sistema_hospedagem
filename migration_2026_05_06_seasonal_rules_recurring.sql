-- Migração complementar: suporte a regras recorrentes por dia da semana
-- Execute no banco de produção após a migração inicial de seasonal_rules.

ALTER TABLE `seasonal_rules`
  ADD COLUMN IF NOT EXISTS `rule_type` ENUM('period','recurring') NOT NULL DEFAULT 'period' AFTER `rule_name`;

ALTER TABLE `seasonal_rules`
  MODIFY COLUMN `start_date` DATE NULL,
  MODIFY COLUMN `end_date` DATE NULL;

ALTER TABLE `seasonal_rules`
  ADD COLUMN IF NOT EXISTS `recurring_days` JSON NULL AFTER `end_date`;
