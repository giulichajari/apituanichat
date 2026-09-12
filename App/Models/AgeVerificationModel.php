<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class AgeVerificationModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function create(array $data): bool
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO age_verifications (user_id, full_name, document_number, document_photo, selfie_photo, status, created_at)
                 VALUES (:user_id, :full_name, :document_number, :document_photo, :selfie_photo, 'pending', NOW())"
            );
            $ok = $stmt->execute([
                ':user_id'         => $data['user_id'],
                ':full_name'       => $data['full_name'],
                ':document_number' => $data['document_number'],
                ':document_photo'  => $data['document_photo'],
                ':selfie_photo'    => $data['selfie_photo'],
            ]);
            if ($ok) {
                $upd = $this->db->prepare("UPDATE users SET age_verification_status = 'pending' WHERE id = :id");
                $upd->execute([':id' => $data['user_id']]);
            }
            return $ok;
        } catch (PDOException $e) {
            error_log("AgeVerificationModel::create ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function getMyStatus(int $userId): string
    {
        $stmt = $this->db->prepare("SELECT age_verification_status FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['age_verification_status'] : 'none';
    }

    public function listPending(): array
    {
        $stmt = $this->db->prepare(
            "SELECT av.*, u.name AS user_name, u.email, u.avatar
             FROM age_verifications av
             JOIN users u ON u.id = av.user_id
             WHERE av.status = 'pending'
             ORDER BY av.created_at ASC"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function approve(int $id, int $adminId): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT user_id FROM age_verifications WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return false;

            $upd = $this->db->prepare("UPDATE age_verifications SET status = 'approved', reviewed_by = :admin, reviewed_at = NOW() WHERE id = :id");
            $upd->execute([':admin' => $adminId, ':id' => $id]);

            $updUser = $this->db->prepare("UPDATE users SET age_verification_status = 'approved' WHERE id = :id");
            return $updUser->execute([':id' => $row['user_id']]);
        } catch (PDOException $e) {
            error_log("AgeVerificationModel::approve ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function reject(int $id, int $adminId): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT user_id FROM age_verifications WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return false;

            $upd = $this->db->prepare("UPDATE age_verifications SET status = 'rejected', reviewed_by = :admin, reviewed_at = NOW() WHERE id = :id");
            $upd->execute([':admin' => $adminId, ':id' => $id]);

            $updUser = $this->db->prepare("UPDATE users SET age_verification_status = 'rejected' WHERE id = :id");
            return $updUser->execute([':id' => $row['user_id']]);
        } catch (PDOException $e) {
            error_log("AgeVerificationModel::reject ERROR: " . $e->getMessage());
            return false;
        }
    }
}
