CREATE TABLE IF NOT EXISTS webauthn_credentials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  credential_id TEXT NOT NULL,
  public_key TEXT NOT NULL,
  sign_count INT NOT NULL DEFAULT 0,
  aaguid VARCHAR(64) NULL,
  label VARCHAR(100) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_webauthn_user (user_id)
);

CREATE TABLE IF NOT EXISTS webauthn_challenges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  challenge TEXT NOT NULL,
  type ENUM('register','auth') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_challenges_user (user_id)
);
