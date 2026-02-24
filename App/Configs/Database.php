<?php

namespace App\Configs;

use PDO;
use PDOException;

class Database
{
    private static ?Database $instance = null;
    private PDO $connection;

    private function __construct()
    {
        // ✅ Soportar variables de entorno (.env) para VPS
        // Mantener defaults para entorno local (WAMP).
        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $port = $_ENV['DB_PORT'] ?? '3306';
        $dbname = $_ENV['DB_NAME'] ?? ($_ENV['DB_DATABASE'] ?? 'tuanichatbd');
        $user = $_ENV['DB_USER'] ?? ($_ENV['DB_USERNAME'] ?? 'root');
        $pass = $_ENV['DB_PASS'] ?? ($_ENV['DB_PASSWORD'] ?? '');
        $socket = $_ENV['DB_SOCKET'] ?? null;

        try {
            $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
            if (!empty($port)) {
                $dsn .= ";port={$port}";
            }
            if (!empty($socket)) {
                $dsn .= ";unix_socket={$socket}";
            }

            $this->connection = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }
}
