<?php
namespace App\Configs;

use PDO;
use PDOException;

class Database {
    private static ?Database $instance = null;
    private PDO $connection;

    private function __construct() {
        $host = "127.0.0.1";
        $dbname = "tuanichatbd";
        $user = "random";
        $pass = "Argentina1991!";
 
        try {
            $this->connection = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
    // Guardar en php-error.log
error_log("DB CONNECTION ERROR: " . $e->getMessage(), 3, "/var/www/apituanichat/php-error.log");



    // Opcional: responder JSON para APIs
    header('Content-Type: application/json');  echo json_encode(['error' => $e->getMessage()]); 
}
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        return $this->connection;
    }
}
