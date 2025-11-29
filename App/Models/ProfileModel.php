<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class ProfileModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // Obtener perfil por user_id
    public function getProfile(int $userId): array|bool
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, user_id, bio, email, website,
                       instagram, facebook, twitter, linkedin, tiktok, avatar
                FROM profiles
                WHERE user_id = :user_id
            ");
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            return false;
        }
    }

    // Crear perfil (si no existe)
    public function createProfile(int $userId, array $data): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO profiles 
                (user_id, bio, email, website, instagram, facebook, twitter, linkedin, tiktok, avatar)
                VALUES (:user_id, :bio, :email, :website, :instagram, :facebook, :twitter, :linkedin, :tiktok, :avatar)
            ");
            return $stmt->execute([
                ':user_id' => $userId,
                ':bio' => $data['bio'] ?? '',
                ':email' => $data['email'] ?? '',
                ':website' => $data['website'] ?? '',
                ':instagram' => $data['instagram'] ?? '',
                ':facebook' => $data['facebook'] ?? '',
                ':twitter' => $data['twitter'] ?? '',
                ':linkedin' => $data['linkedin'] ?? '',
                ':tiktok' => $data['tiktok'] ?? '',
                ':avatar' => $data['avatar'] ?? ''
            ]);
        } catch (PDOException $e) {
            return false;
        }
    }

    // Actualizar perfil existente
    public function updateProfile(int $userId, array $data): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE profiles SET
                    bio = :bio,
                    email = :email,
                    website = :website,
                    instagram = :instagram,
                    facebook = :facebook,
                    twitter = :twitter,
                    linkedin = :linkedin,
                    tiktok = :tiktok,
                    avatar = :avatar
                WHERE user_id = :user_id
            ");
            return $stmt->execute([
                ':bio' => $data['bio'] ?? '',
                ':email' => $data['email'] ?? '',
                ':website' => $data['website'] ?? '',
                ':instagram' => $data['instagram'] ?? '',
                ':facebook' => $data['facebook'] ?? '',
                ':twitter' => $data['twitter'] ?? '',
                ':linkedin' => $data['linkedin'] ?? '',
                ':tiktok' => $data['tiktok'] ?? '',
                ':avatar' => $data['avatar'] ?? '',
                ':user_id' => $userId
            ]);
        } catch (PDOException $e) {
            return false;
        }
    }

   public function updateAvatar(int $userId, string $avatarPath): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE profiles 
                SET avatar = :avatar 
                WHERE user_id = :user_id
            ");
            return $stmt->execute([
                ':avatar' => $avatarPath,
                ':user_id' => $userId  // ✅ CORREGIDO: usar user_id en lugar de id
            ]);
        } catch (PDOException $e) {
            error_log("UpdateAvatar ERROR: " . $e->getMessage());
            return false;
        }
    }
}
