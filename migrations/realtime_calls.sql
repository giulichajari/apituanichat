CREATE TABLE IF NOT EXISTS realtime_calls (
  call_id VARCHAR(120) NOT NULL PRIMARY KEY,
  caller_id INT NOT NULL,
  callee_id INT NOT NULL,
  status VARCHAR(16) NOT NULL,
  revision BIGINT NOT NULL DEFAULT 1,
  updated_at BIGINT NOT NULL,
  state JSON NOT NULL,
  INDEX calls_callee (callee_id,status),
  INDEX calls_caller (caller_id,status),
  INDEX calls_cleanup (status,updated_at)
) ENGINE=InnoDB;
