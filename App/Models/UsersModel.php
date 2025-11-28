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
  public function getUsers(int $page, int $perPage = 10, int $excludeUserId = null): array|bool
{
    try {
        $offset = ($page - 1) * $perPage;
        
        if ($excludeUserId) {
            $stmt = $this->db->prepare("
                SELECT id, name, email, phone, is_verified, online, rol, created_at 
                FROM users 
                WHERE id != :exclude_user_id
                ORDER BY created_at DESC 
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':exclude_user_id', $excludeUserId, PDO::PARAM_INT);
        } else {
            $stmt = $this->db->prepare("
                SELECT id, name, email, phone, is_verified, online, rol, created_at 
                FROM users 
                ORDER BY created_at DESC 
                LIMIT :limit OFFSET :offset
            ");
        }
        
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("GetUsers ERROR: " . $e->getMessage());
        return false;
    }
}
// En UsuariosModel.php

    /**
     * Obtener todos los usuarios excepto uno específico
     */
    public function getAllUsersExcept(int $excludeUserId): array
    {
        try {
            $stmt = $this->db->prepare("
            SELECT id, name, email 
            FROM users 
            WHERE id != :exclude_id 
            AND deleted_at IS NULL
        ");
            $stmt->execute([':exclude_id' => $excludeUserId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Error getting users except: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Crear un nuevo chat
     */
    public function createChat(array $chatData): int|false
    {
        try {
            $stmt = $this->db->prepare("
            INSERT INTO chats (name, type, created_by, created_at) 
            VALUES (:name, :type, :created_by, NOW())
        ");
            $stmt->execute([
                ':name' => $chatData['name'],
                ':type' => $chatData['type'],
                ':created_by' => $chatData['created_by']
            ]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('Error creating chat: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Agregar usuario a un chat
     */
    public function addUserToChat(int $chatId, int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
            INSERT INTO chat_usuarios (chat_id, user_id, joined_at) 
            VALUES (:chat_id, :user_id, NOW())
        ");
            return $stmt->execute([
                ':chat_id' => $chatId,
                ':user_id' => $userId
            ]);
        } catch (PDOException $e) {
            error_log('Error adding user to chat: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Crear perfil de usuario
     */
    public function createUserProfile(array $profileData): bool
    {
        try {
            $stmt = $this->db->prepare("
            INSERT INTO profiles 
            (user_id, bio, email, website, instagram, facebook, twitter, linkedin, tiktok, avatar, created_at) 
            VALUES 
            (:user_id, :bio, :email, :website, :instagram, :facebook, :twitter, :linkedin, :tiktok, :avatar, NOW())
        ");

            return $stmt->execute([
                ':user_id' => $profileData['user_id'],
                ':bio' => $profileData['bio'],
                ':email' => $profileData['email'],
                ':website' => $profileData['website'],
                ':instagram' => $profileData['instagram'],
                ':facebook' => $profileData['facebook'],
                ':twitter' => $profileData['twitter'],
                ':linkedin' => $profileData['linkedin'],
                ':tiktok' => $profileData['tiktok'],
                ':avatar' => $profileData['avatar']
            ]);
        } catch (PDOException $e) {
            error_log('Error creating user profile: ' . $e->getMessage());
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

            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
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
        $stmt = $this->db->prepare("SELECT online FROM users WHERE id = :id");
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        // Retornar array vacío si no hay resultados, no false
        return $result ?: [];
        
    } catch (\PDOException $e) {
        error_log("Error getUserStatus: " . $e->getMessage());
        return false; // Solo false en caso de error excepcional
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
