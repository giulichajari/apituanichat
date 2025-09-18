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
            $stmt = $this->db->prepare("SELECT id, name, email, phone, is_verified, created_at FROM users LIMIT :limit OFFSET :offset");
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
            $stmt = $this->db->prepare("SELECT id, name, email, phone, is_verified, avatar, created_at FROM users WHERE id = :id");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            return false;
        }
    }

    // Crear un usuario
    public function addUser(string $name, string $email, string $password, string $phone = ""): bool {
        try {
            $stmt = $this->db->prepare("INSERT INTO users (name, email, pass, phone) VALUES (:name, :email, :pass, :phone)");
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':email', $email);
            $stmt->bindValue(':pass', password_hash($password, PASSWORD_DEFAULT));
            $stmt->bindValue(':phone', $phone);
            return $stmt->execute();
        } catch (PDOException $e) {
            return false;
        }
    }

    // Actualizar usuario
    public function updateUser(int $id, string $name, string $email, string $phone = ""): bool {
        try {
            $stmt = $this->db->prepare("UPDATE users SET name = :name, email = :email, phone = :phone WHERE id = :id");
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':email', $email);
            $stmt->bindValue(':phone', $phone);
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

    // Buscar usuario por email (para forgot-password)
    public function getUserByEmail(string $email): array|false {
        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email");
            $stmt->bindValue(':email', $email);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return false;
        }
    }

    // Guardar token de reseteo (tabla users debería tener columna reset_token y reset_token_expire)
    public function storeResetToken(int $userId, string $token): bool {
        try {
            $stmt = $this->db->prepare("UPDATE users SET otp = :otp, otp_created_at = NOW() WHERE id = :id");
            $stmt->bindValue(':otp', $token);
            $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            return false;
        }
    }

    // Obtener usuario a partir de token de reseteo
    public function getUserIdByResetToken(string $token): int|false {
        try {
            $stmt = $this->db->prepare("SELECT id FROM users WHERE otp = :otp");
            $stmt->bindValue(':otp', $token);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (int)$row['id'] : false;
        } catch (PDOException $e) {
            return false;
        }
    }

    // Actualizar contraseña
    public function updatePassword(int $userId, string $hashedPassword): bool {
        try {
            $stmt = $this->db->prepare("UPDATE users SET pass = :pass, otp = NULL, otp_created_at = NULL WHERE id = :id");
            $stmt->bindValue(':pass', $hashedPassword);
            $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            return false;
        }
    }
}
