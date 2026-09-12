ALTER TABLE wallets
  ADD COLUMN pin_hash VARCHAR(255) NULL AFTER status,
  ADD COLUMN pin_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER pin_hash,
  ADD COLUMN pin_attempts INT NOT NULL DEFAULT 0 AFTER pin_enabled,
  ADD COLUMN lock_reason VARCHAR(50) NULL AFTER pin_attempts;
