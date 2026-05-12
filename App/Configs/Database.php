<?php

namespace App\Configs;

use PDO;
use PDOException;

class Database
{
    private static ?Database $instance = null;
    private PDO $connection;
    private array $config;

    private function __construct()
    {
        // ✅ Soportar variables de entorno (.env) para VPS
        // Mantener defaults para entorno local (WAMP).
        $this->config = [
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'dbname' => $_ENV['DB_NAME'] ?? ($_ENV['DB_DATABASE'] ?? 'tuanichatbd'),
            'user' => $_ENV['DB_USER'] ?? ($_ENV['DB_USERNAME'] ?? 'root'),
            'pass' => $_ENV['DB_PASS'] ?? ($_ENV['DB_PASSWORD'] ?? ''),
            'socket' => $_ENV['DB_SOCKET'] ?? null,
        ];

        try {
            $this->connection = $this->createConnection();
        } catch (PDOException $e) {
            // Guardar en php-error.log
            error_log("DB CONNECTION ERROR: " . $e->getMessage());

            // En CLI (ej. ws-server.php) no hay respuesta HTTP; solo log y salida de texto
            if (php_sapi_name() === 'cli') {
                fwrite(STDERR, "ERROR DB: " . $e->getMessage() . "\n");
                exit(1);
            }

            // Respuesta JSON para APIs web
            if (!headers_sent()) {
                header('Content-Type: application/json');
                http_response_code(500);
            }
            echo json_encode(['error' => 'Error en la conexión a la base de datos']);
            exit(1);
        }
    }

    private function createConnection(): PDO
    {
        $dsn = "mysql:host={$this->config['host']};dbname={$this->config['dbname']};charset=utf8mb4";
        if (!empty($this->config['port'])) {
            $dsn .= ";port={$this->config['port']}";
        }
        if (!empty($this->config['socket'])) {
            $dsn .= ";unix_socket={$this->config['socket']}";
        }

        $connection = new PDO($dsn, $this->config['user'], $this->config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $connection;
    }

    private function isRecoverableConnectionError(PDOException $e): bool
    {
        $message = strtolower($e->getMessage());
        $errorCode = $e->errorInfo[1] ?? null;

        return $errorCode === 2006
            || $errorCode === 2013
            || str_contains($message, 'server has gone away')
            || str_contains($message, 'lost connection');
    }

    public function refreshConnectionIfNeeded(): PDO
    {
        try {
            $this->connection->query('SELECT 1');
            return $this->connection;
        } catch (PDOException $e) {
            if (!$this->isRecoverableConnectionError($e)) {
                throw $e;
            }

            error_log("DB RECONNECT: " . $e->getMessage());
            $this->connection = $this->createConnection();
            return $this->connection;
        }
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->refreshConnectionIfNeeded();
    }
}
