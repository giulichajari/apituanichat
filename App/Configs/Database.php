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
        $host = "72.60.124.216";
        $dbname = "tuanichatbd";
$user = "tuanichat";
$pass = "Argentina1991!";
    //    $user = "root";
    //     $pass = "";
        try {
            $this->connection = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            // Guardar en php-error.log
            error_log("DB CONNECTION ERROR: " . $e->getMessage());

            // Opcional: responder JSON para APIs
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['error' => 'Error en la conexión a la base de datos']);
            exit();
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
