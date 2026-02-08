-- Crear tabla countries si no existe (compatible con products.country_id)
CREATE TABLE IF NOT EXISTS `countries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(3) NOT NULL,
  `currency` varchar(10) DEFAULT 'USD',
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insertar países (INSERT IGNORE evita duplicados por código)
INSERT IGNORE INTO `countries` (`name`, `code`, `currency`) VALUES
('Argentina', 'ARG', 'ARS'),
('Bolivia', 'BOL', 'BOB'),
('Brasil', 'BRA', 'BRL'),
('Chile', 'CHL', 'CLP'),
('Colombia', 'COL', 'COP'),
('Costa Rica', 'CRI', 'CRC'),
('Cuba', 'CUB', 'CUP'),
('Ecuador', 'ECU', 'USD'),
('El Salvador', 'SLV', 'USD'),
('España', 'ESP', 'EUR'),
('Estados Unidos', 'USA', 'USD'),
('Guatemala', 'GTM', 'GTQ'),
('Honduras', 'HND', 'HNL'),
('México', 'MEX', 'MXN'),
('Nicaragua', 'NIC', 'NIO'),
('Panamá', 'PAN', 'PAB'),
('Paraguay', 'PRY', 'PYG'),
('Perú', 'PER', 'PEN'),
('República Dominicana', 'DOM', 'DOP'),
('Uruguay', 'URY', 'UYU'),
('Venezuela', 'VEN', 'VES');
