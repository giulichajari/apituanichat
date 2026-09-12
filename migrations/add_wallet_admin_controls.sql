ALTER TABLE wallets
  ADD COLUMN status ENUM('active','frozen') NOT NULL DEFAULT 'active' AFTER balance;

ALTER TABLE wallet_transactions
  MODIFY COLUMN type ENUM('recharge','transfer_in','transfer_out','purchase','refund','adjustment') NOT NULL;

CREATE TABLE IF NOT EXISTS wallet_alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  wallet_id INT NOT NULL,
  type ENUM('high_amount','high_frequency') NOT NULL,
  transaction_id INT NULL,
  details TEXT NULL,
  resolved TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_alerts_wallet (wallet_id),
  KEY idx_alerts_resolved (resolved)
);
