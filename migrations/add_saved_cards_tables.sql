CREATE TABLE IF NOT EXISTS square_customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  square_customer_id VARCHAR(100) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS saved_cards (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  square_card_id VARCHAR(100) NOT NULL UNIQUE,
  card_brand VARCHAR(30) NULL,
  last_4 VARCHAR(4) NULL,
  exp_month INT NULL,
  exp_year INT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_saved_cards_user (user_id)
);
