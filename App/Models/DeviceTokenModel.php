<?php

namespace App\Models;

use App\Configs\Database;
use PDO;

class DeviceTokenModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function upsertToken(int $userId, string $fcmToken, string $platform): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO device_tokens (user_id, fcm_token, platform, is_active, last_used_at)
                VALUES (:user_id, :fcm_token, :platform, 1, NOW())
                ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    platform = VALUES(platform),
                    is_active = 1,
                    last_used_at = NOW()
            ");
            return $stmt->execute([
                ':user_id' => $userId,
                ':fcm_token' => $fcmToken,
                ':platform' => $platform
            ]);
        } catch (\PDOException $e) {
            error_log("❌ DeviceTokenModel upsertToken: " . $e->getMessage());
            return false;
        }
    }

    public function deactivateToken(string $fcmToken): bool
    {
        $stmt = $this->db->prepare("
            UPDATE device_tokens SET is_active = 0 WHERE fcm_token = ?
        ");
        return $stmt->execute([$fcmToken]);
    }

    public function getActiveTokensForUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM device_tokens WHERE user_id = ? AND is_active = 1
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
