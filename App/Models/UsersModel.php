<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class UsersModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // Obtener todos los usuarios paginados
    public function getUsers(int $page, int $perPage = 10): array|bool
    {
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
    public function getUser(int $id): array|bool
    {
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
    public function addUser(string $name, string $email, string $password, string $phone = "", string $rol = "user"): int|false
    {
        try {
            $this->db->beginTransaction();

            // 1. Insertar nuevo usuario
            $stmt = $this->db->prepare("
            INSERT INTO users (name, email, pass, phone, rol) 
            VALUES (:name, :email, :pass, :phone, :rol)
        ");
            $stmt->execute([
                ':name' => $name,
                ':email' => $email,
                ':pass' => $password,
                ':phone' => $phone,
                ':rol' => $rol
            ]);

            $newUserId = (int)$this->db->lastInsertId();

            // 2. Obtener todos los usuarios existentes (menos el nuevo)
            $stmt = $this->db->prepare("SELECT id FROM users WHERE id != :newId");
            $stmt->execute([':newId' => $newUserId]);
            $users = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // 3. Crear chat 1 a 1 con cada usuario existente
            $chatStmt = $this->db->prepare("INSERT INTO chats (name, last_message_at) VALUES (:name, NOW())");
            $chatUserStmt = $this->db->prepare("INSERT INTO chat_usuarios (chat_id, user_id) VALUES (:chat_id, :user_id)");

            foreach ($users as $existingUserId) {
                // Crear un chat con nombre opcional
                $chatName = "Chat: {$newUserId}-{$existingUserId}";
                $chatStmt->execute([':name' => $chatName]);
                $chatId = (int)$this->db->lastInsertId();

                // Agregar ambos usuarios al chat
                $chatUserStmt->execute([':chat_id' => $chatId, ':user_id' => $newUserId]);
                $chatUserStmt->execute([':chat_id' => $chatId, ':user_id' => $existingUserId]);
            }

            $this->db->commit();
            return $newUserId;
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log($e->getMessage() . "\n", 3, __DIR__ . '/../php-error.log');
            return false;
        }
    }



    // Buscar usuario por teléfono
    public function getUserByPhone(string $phone): array|false
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE phone = :phone");
            $stmt->bindValue(':phone', $phone);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return false;
        }
    }
    public function getUserStatus(int $id): array|bool
    {
        try {
            $stmt = $this->db->prepare("SELECT last_seen, is_verified FROM users WHERE id = :id");
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            return false;
        }
    }

    // Actualizar usuario
    public function updateUser(int $id, string $name, string $email, string $phone = ""): bool
    {
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
    public function deleteUser(int $id): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM users WHERE id = :id");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            return false;
        }
    }

    // Verificar login
    public function verifyCredentials(string $email, string $password): array|bool
    {
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
    public function getUserByEmail(string $email): array|false
    {
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
    public function storeResetToken(int $userId, string $token): bool
    {
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
    public function getUserIdByResetToken(string $token): int|false
    {
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
    public function updatePassword(int $userId, string $hashedPassword): bool
    {
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
