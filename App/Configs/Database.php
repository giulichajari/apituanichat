<?php

namespace App\Configs;

use PDO;
use PDOException;

class Database
{
    private static ?Database $instance = null;
    private PDO $connection;

<<<<<<< HEAD
    private function __construct() {
        $host = "127.0.0.1";
        $dbname = "tuanichatbd";
        $user = "random";
=======
    private function __construct()
    {
        $host = "localhost";
        $dbname = "tuanichatbd";
        $user = "tuanichat";
>>>>>>> 7f47dd7b63e977d36ae941def79120a83386e839
        $pass = "Argentina1991!";
 
        try {
            $this->connection = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
<<<<<<< HEAD
    // Guardar en php-error.log
error_log("DB CONNECTION ERROR: " . $e->getMessage(), 3, "/var/www/apituanichat/php-error.log");



    // Opcional: responder JSON para APIs
    header('Content-Type: application/json');  echo json_encode(['error' => $e->getMessage()]); 
}
=======
            // Guardar en php-error.log
            error_log("DB CONNECTION ERROR: " . $e->getMessage());

            // Opcional: responder JSON para APIs
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['error' => 'Error en la conexión a la base de datos']);
            exit();
        }
>>>>>>> 7f47dd7b63e977d36ae941def79120a83386e839
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
