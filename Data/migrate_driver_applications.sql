-- Ejecutar una sola vez en la base tuanichatbd
-- Tabla para solicitudes de nuevos drivers (Become a Driver)

CREATE TABLE IF NOT EXISTS driver_applications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  form_data JSON NOT NULL,
  documents JSON NULL,
  signature_path VARCHAR(255) NULL,
  signature_name VARCHAR(150) NULL,
  signature_date DATE NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status),
  INDEX idx_email (email),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
