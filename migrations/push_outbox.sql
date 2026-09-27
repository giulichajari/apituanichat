CREATE TABLE IF NOT EXISTS push_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_key CHAR(64) NOT NULL UNIQUE,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  payload JSON NOT NULL,
  expires_at BIGINT NOT NULL,
  next_attempt_at BIGINT NOT NULL,
  claimed_until BIGINT NOT NULL DEFAULT 0,
  lease_key CHAR(32) NULL,
  attempts INT NOT NULL DEFAULT 0,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  created_at BIGINT NOT NULL,
  INDEX push_ready (status, next_attempt_at, claimed_until),
  INDEX push_expiry (expires_at)
) ENGINE=InnoDB;
