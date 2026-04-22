-- Soft delete para grupos: no listar ni usar grupos borrados; miembros desvinculados vía group_users.deleted_at
-- Ejecutar una vez en la base de datos de TuaniChat.

ALTER TABLE `groups`
  ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `created_at`,
  ADD COLUMN `deleted_by` INT UNSIGNED NULL DEFAULT NULL AFTER `deleted_at`;

CREATE INDEX `idx_groups_deleted_at` ON `groups` (`deleted_at`);
