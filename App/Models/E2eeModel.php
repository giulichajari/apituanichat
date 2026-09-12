<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class E2eeModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // Sube (o reemplaza) el bundle completo de un usuario: identidad + signed prekey + prekeys de un solo uso.
    // v1: reemplaza todo, ya que solo hay una identidad activa por usuario (no multi-dispositivo real).
    public function uploadBundle(
        int $userId,
        string $identityPubKey,
        int $registrationId,
        ?string $deviceLabel,
        int $signedPrekeyId,
        string $signedPrekeyPub,
        string $signedPrekeySignature,
        array $oneTimePrekeys // [['key_id' => int, 'pub_key' => string], ...]
    ): bool {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                INSERT INTO e2ee_identities (user_id, identity_pub_key, registration_id, device_label)
                VALUES (:user_id, :identity_pub_key, :registration_id, :device_label)
                ON DUPLICATE KEY UPDATE
                    identity_pub_key = VALUES(identity_pub_key),
                    registration_id = VALUES(registration_id),
                    device_label = VALUES(device_label)
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':identity_pub_key' => $identityPubKey,
                ':registration_id' => $registrationId,
                ':device_label' => $deviceLabel,
            ]);

            $stmt = $this->db->prepare("
                INSERT INTO e2ee_signed_prekeys (user_id, key_id, pub_key, signature)
                VALUES (:user_id, :key_id, :pub_key, :signature)
                ON DUPLICATE KEY UPDATE
                    key_id = VALUES(key_id),
                    pub_key = VALUES(pub_key),
                    signature = VALUES(signature)
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':key_id' => $signedPrekeyId,
                ':pub_key' => $signedPrekeyPub,
                ':signature' => $signedPrekeySignature,
            ]);

            // Nueva identidad => las prekeys de un solo uso viejas quedan invalidas
            $stmt = $this->db->prepare("DELETE FROM e2ee_one_time_prekeys WHERE user_id = :user_id");
            $stmt->execute([':user_id' => $userId]);

            $stmtInsertOtpk = $this->db->prepare("
                INSERT INTO e2ee_one_time_prekeys (user_id, key_id, pub_key)
                VALUES (:user_id, :key_id, :pub_key)
            ");
            foreach ($oneTimePrekeys as $prekey) {
                $stmtInsertOtpk->execute([
                    ':user_id' => $userId,
                    ':key_id' => $prekey['key_id'],
                    ':pub_key' => $prekey['pub_key'],
                ]);
            }

            $this->db->commit();
            return true;
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("E2eeModel uploadBundle ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Agrega mas prekeys de un solo uso sin tocar identidad/signed prekey (reposicion cuando quedan pocas)
    public function addOneTimePrekeys(int $userId, array $oneTimePrekeys): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT IGNORE INTO e2ee_one_time_prekeys (user_id, key_id, pub_key)
                VALUES (:user_id, :key_id, :pub_key)
            ");
            foreach ($oneTimePrekeys as $prekey) {
                $stmt->execute([
                    ':user_id' => $userId,
                    ':key_id' => $prekey['key_id'],
                    ':pub_key' => $prekey['pub_key'],
                ]);
            }
            return true;
        } catch (PDOException $e) {
            error_log("E2eeModel addOneTimePrekeys ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function countUnusedOneTimePrekeys(int $userId): int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM e2ee_one_time_prekeys WHERE user_id = :user_id AND used = 0
            ");
            $stmt->execute([':user_id' => $userId]);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("E2eeModel countUnusedOneTimePrekeys ERROR: " . $e->getMessage());
            return 0;
        }
    }

    public function hasBundle(int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT 1 FROM e2ee_identities WHERE user_id = :user_id LIMIT 1");
            $stmt->execute([':user_id' => $userId]);
            return (bool) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("E2eeModel hasBundle ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Trae el bundle publico de un usuario para que otro inicie sesion X3DH,
    // consumiendo (marcando used=1) una prekey de un solo uso en el proceso.
    public function consumeBundleForSession(int $targetUserId): array|false
    {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT * FROM e2ee_identities WHERE user_id = :user_id LIMIT 1");
            $stmt->execute([':user_id' => $targetUserId]);
            $identity = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$identity) {
                $this->db->rollBack();
                return false;
            }

            $stmt = $this->db->prepare("SELECT * FROM e2ee_signed_prekeys WHERE user_id = :user_id LIMIT 1");
            $stmt->execute([':user_id' => $targetUserId]);
            $signedPrekey = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$signedPrekey) {
                $this->db->rollBack();
                return false;
            }

            $stmt = $this->db->prepare("
                SELECT * FROM e2ee_one_time_prekeys
                WHERE user_id = :user_id AND used = 0
                ORDER BY id ASC
                LIMIT 1 FOR UPDATE
            ");
            $stmt->execute([':user_id' => $targetUserId]);
            $oneTimePrekey = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($oneTimePrekey) {
                $stmtMark = $this->db->prepare("UPDATE e2ee_one_time_prekeys SET used = 1 WHERE id = :id");
                $stmtMark->execute([':id' => $oneTimePrekey['id']]);
            }

            $this->db->commit();

            return [
                'identity_pub_key' => $identity['identity_pub_key'],
                'registration_id' => (int) $identity['registration_id'],
                'signed_prekey' => [
                    'key_id' => (int) $signedPrekey['key_id'],
                    'pub_key' => $signedPrekey['pub_key'],
                    'signature' => $signedPrekey['signature'],
                ],
                // Puede ser null si ya no quedan prekeys de un solo uso (X3DH aun funciona sin ella, con menor forward secrecy inicial)
                'one_time_prekey' => $oneTimePrekey ? [
                    'key_id' => (int) $oneTimePrekey['key_id'],
                    'pub_key' => $oneTimePrekey['pub_key'],
                ] : null,
            ];
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("E2eeModel consumeBundleForSession ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function enableForChat(int $chatId, int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT IGNORE INTO e2ee_chats (chat_id, enabled_by) VALUES (:chat_id, :user_id)
            ");
            return $stmt->execute([':chat_id' => $chatId, ':user_id' => $userId]);
        } catch (PDOException $e) {
            error_log("E2eeModel enableForChat ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function isEnabledForChat(int $chatId): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT 1 FROM e2ee_chats WHERE chat_id = :chat_id LIMIT 1");
            $stmt->execute([':chat_id' => $chatId]);
            return (bool) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("E2eeModel isEnabledForChat ERROR: " . $e->getMessage());
            return false;
        }
    }
}
