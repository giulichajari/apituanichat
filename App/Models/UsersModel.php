<?php
namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class UsersModel {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // Obtener todos los usuarios paginados
    public function getUsers(int $page, int $perPage = 10): array|bool {
        try {
            $offset = ($page - 1) * $perPage;
            $stmt = $this->db->prepare("SELECT id, name, email FROM users LIMIT :limit OFFSET :offset");
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return false;
        }
    }

    // Obtener un usuario por ID
    public function getUser(int $id): array|bool {
        try {
            $stmt = $this->db->prepare("SELECT id,  email FROM users WHERE id = :id");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            return false;
        }
    }

    // Crear un usuario
    public function addUser(string $name, string $email, string $password): bool {
        try {
            $stmt = $this->db->prepare("INSERT INTO users (name, email, password) VALUES (:name, :email, :password)");
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':email', $email);
            $stmt->bindValue(':password', password_hash($password, PASSWORD_DEFAULT));
            return $stmt->execute();
        } catch (PDOException $e) {
            return false;
        }
    }

    // Actualizar usuario
    public function updateUser(int $id, string $name, string $email): bool {
        try {
            $stmt = $this->db->prepare("UPDATE users SET name = :name, email = :email WHERE id = :id");
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':email', $email);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            return false;
        }
    }

    // Borrar usuario
    public function deleteUser(int $id): bool {
        try {
            $stmt = $this->db->prepare("DELETE FROM users WHERE id = :id");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            return false;
        }
    }

    // Verificar login
    public function verifyCredentials(string $email, string $password): array|bool {
        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email");
            $stmt->bindValue(':email', $email);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
     

            if ($user && password_verify($password, $user['pass'])) {
                return $user;
            }
            return false;
        } catch (PDOException $e) {
            return false;
        }
    }
}
