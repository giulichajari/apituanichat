<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;
use Exception;

class FamilyModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    private function generateCode(): string
    {
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            $stmt = $this->db->prepare("SELECT id FROM family_links WHERE invite_code = :code");
            $stmt->execute([':code' => $code]);
            $exists = $stmt->fetch();
        } while ($exists);
        return $code;
    }

    public function createInvite(int $parentUserId): ?string
    {
        try {
            $code = $this->generateCode();
            $stmt = $this->db->prepare(
                "INSERT INTO family_links (parent_user_id, invite_code, status, created_at) VALUES (:parent, :code, 'pending', NOW())"
            );
            $stmt->execute([':parent' => $parentUserId, ':code' => $code]);
            return $code;
        } catch (PDOException $e) {
            error_log("FamilyModel::createInvite ERROR: " . $e->getMessage());
            return null;
        }
    }

    public function joinWithCode(int $childUserId, string $code): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM family_links WHERE invite_code = :code AND status = 'pending' AND child_user_id IS NULL"
            );
            $stmt->execute([':code' => strtoupper(trim($code))]);
            $link = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$link) {
                return ['success' => false, 'message' => 'Codigo invalido o ya usado'];
            }
            if ((int) $link['parent_user_id'] === $childUserId) {
                return ['success' => false, 'message' => 'No podes vincularte a vos mismo'];
            }
            $upd = $this->db->prepare(
                "UPDATE family_links SET child_user_id = :child, status = 'active', activated_at = NOW() WHERE id = :id"
            );
            $upd->execute([':child' => $childUserId, ':id' => $link['id']]);
            return ['success' => true];
        } catch (PDOException $e) {
            error_log("FamilyModel::joinWithCode ERROR: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error interno'];
        }
    }

    public function getChildrenForParent(int $parentUserId): array
    {
        $stmt = $this->db->prepare(
            "SELECT fl.id, fl.block_adult_content, fl.wallet_share, fl.activated_at,
                    u.id AS child_id, u.name AS child_name, u.email AS child_email, u.age_verification_status
             FROM family_links fl
             JOIN users u ON u.id = fl.child_user_id
             WHERE fl.parent_user_id = :parent AND fl.status = 'active'
             ORDER BY fl.activated_at DESC"
        );
        $stmt->execute([':parent' => $parentUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getParentForChild(int $childUserId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT fl.id, fl.block_adult_content, fl.wallet_share,
                    u.name AS parent_name, u.email AS parent_email
             FROM family_links fl
             JOIN users u ON u.id = fl.parent_user_id
             WHERE fl.child_user_id = :child AND fl.status = 'active'
             LIMIT 1"
        );
        $stmt->execute([':child' => $childUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateChildSettings(int $parentUserId, int $linkId, ?bool $blockAdult, ?bool $walletShare): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM family_links WHERE id = :id AND parent_user_id = :parent AND status = 'active'");
        $stmt->execute([':id' => $linkId, ':parent' => $parentUserId]);
        if (!$stmt->fetch()) return false;

        $sets = [];
        $params = [':id' => $linkId];
        if ($blockAdult !== null) {
            $sets[] = "block_adult_content = :block";
            $params[':block'] = $blockAdult ? 1 : 0;
        }
        if ($walletShare !== null) {
            $sets[] = "wallet_share = :share";
            $params[':share'] = $walletShare ? 1 : 0;
        }
        if (empty($sets)) return true;

        $upd = $this->db->prepare("UPDATE family_links SET " . implode(', ', $sets) . " WHERE id = :id");
        return $upd->execute($params);
    }

    public function unlink(int $userId, int $linkId): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM family_links WHERE id = :id AND (parent_user_id = :user OR child_user_id = :user2)"
        );
        return $stmt->execute([':id' => $linkId, ':user' => $userId, ':user2' => $userId]);
    }

    public function isBlockedFromAdultContent(int $childUserId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM family_links WHERE child_user_id = :child AND status = 'active' AND block_adult_content = 1 LIMIT 1"
        );
        $stmt->execute([':child' => $childUserId]);
        return (bool) $stmt->fetch();
    }

    public function getWalletSharePayer(int $childUserId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT parent_user_id FROM family_links WHERE child_user_id = :child AND status = 'active' AND wallet_share = 1 LIMIT 1"
        );
        $stmt->execute([':child' => $childUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['parent_user_id'] : null;
    }
}
