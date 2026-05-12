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
    public function getProfile(int $userId)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, user_id, bio, email, website,
                       instagram, facebook, twitter, linkedin, tiktok, avatar,
                       enable_welcome_message, welcome_message, welcome_link, company_description,
                       enable_unavailable_auto_reply, unavailable_auto_reply_message
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
                (user_id, bio, email, website, instagram, facebook, twitter, linkedin, tiktok, avatar,
                 enable_welcome_message, welcome_message, welcome_link, company_description,
                 enable_unavailable_auto_reply, unavailable_auto_reply_message)
                VALUES (:user_id, :bio, :email, :website, :instagram, :facebook, :twitter, :linkedin, :tiktok, :avatar,
                        :enable_welcome_message, :welcome_message, :welcome_link, :company_description,
                        :enable_unavailable_auto_reply, :unavailable_auto_reply_message)
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
                ':avatar' => $data['avatar'] ?? '',
                ':enable_welcome_message' => (int)($data['enable_welcome_message'] ?? 0),
                ':welcome_message' => $data['welcome_message'] ?? '',
                ':welcome_link' => $data['welcome_link'] ?? '',
                ':company_description' => $data['company_description'] ?? '',
                ':enable_unavailable_auto_reply' => (int)($data['enable_unavailable_auto_reply'] ?? 0),
                ':unavailable_auto_reply_message' => $data['unavailable_auto_reply_message'] ?? ''
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
                    avatar = :avatar,
                    enable_welcome_message = :enable_welcome_message,
                    welcome_message = :welcome_message,
                    welcome_link = :welcome_link,
                    company_description = :company_description,
                    enable_unavailable_auto_reply = :enable_unavailable_auto_reply,
                    unavailable_auto_reply_message = :unavailable_auto_reply_message
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
                ':enable_welcome_message' => (int)($data['enable_welcome_message'] ?? 0),
                ':welcome_message' => $data['welcome_message'] ?? '',
                ':welcome_link' => $data['welcome_link'] ?? '',
                ':company_description' => $data['company_description'] ?? '',
                ':enable_unavailable_auto_reply' => (int)($data['enable_unavailable_auto_reply'] ?? 0),
                ':unavailable_auto_reply_message' => $data['unavailable_auto_reply_message'] ?? '',
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
