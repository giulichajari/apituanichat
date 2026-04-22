-- Soft delete para grupos: no listar ni usar grupos borrados; miembros desvinculados vía group_users.deleted_at
-- Ejecutar en la base de datos de TuaniChat (idempotente en MySQL 8+).

ALTER TABLE `groups`
  ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME NULL DEFAULT NULL AFTER `created_at`,
  ADD COLUMN IF NOT EXISTS `deleted_by` INT UNSIGNED NULL DEFAULT NULL AFTER `deleted_at`;

ALTER TABLE `group_users`
  ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME NULL DEFAULT NULL AFTER `left_at`;

CREATE INDEX IF NOT EXISTS `idx_groups_deleted_at` ON `groups` (`deleted_at`);
CREATE INDEX IF NOT EXISTS `idx_group_users_deleted_at` ON `group_users` (`deleted_at`);
