<?php
namespace App\Models;
use App\Configs\Database;
use PDO;

class VerificationRequestModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function create(array $data): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO verification_requests
                (user_id, category, alias, full_name, document_number, document_photo, selfie_photo, message, status)
            VALUES
                (:user_id, :category, :alias, :full_name, :document_number, :document_photo, :selfie_photo, :message, 'pending')
        ");
        return $stmt->execute([
            ':user_id' => $data['user_id'],
            ':category' => $data['category'],
            ':alias' => $data['alias'] ?? null,
            ':full_name' => $data['full_name'],
            ':document_number' => $data['document_number'],
            ':document_photo' => $data['document_photo'],
            ':selfie_photo' => $data['selfie_photo'],
            ':message' => $data['message'] ?? '',
        ]);
    }

    public function getPending(): array
    {
        $stmt = $this->db->prepare("
            SELECT vr.*, u.name AS user_name, u.email, u.avatar
            FROM verification_requests vr
            JOIN users u ON u.id = vr.user_id
            WHERE vr.status = 'pending'
            ORDER BY vr.created_at ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStatusForUser(int $userId): ?string
    {
        $stmt = $this->db->prepare("
            SELECT status FROM verification_requests
            WHERE user_id = :user_id
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['status'] ?? null;
    }

    public function approve(int $requestId, int $adminId): bool
    {
        $stmt = $this->db->prepare("SELECT user_id, category, alias FROM verification_requests WHERE id = :id");
        $stmt->execute([':id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                UPDATE verification_requests
                SET status = 'approved', reviewed_by = :admin_id, reviewed_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':admin_id' => $adminId, ':id' => $requestId]);

            $stmt = $this->db->prepare("
                UPDATE users SET is_verified = 1, verified_type = :type, verified_alias = :alias
                WHERE id = :user_id
            ");
            $stmt->execute([
                ':type' => $row['category'],
                ':alias' => $row['alias'],
                ':user_id' => $row['user_id'],
            ]);

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log("❌ VerificationRequestModel::approve: " . $e->getMessage());
            return false;
        }
    }

    public function reject(int $requestId, int $adminId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE verification_requests
            SET status = 'rejected', reviewed_by = :admin_id, reviewed_at = NOW()
            WHERE id = :id
        ");
        return $stmt->execute([':admin_id' => $adminId, ':id' => $requestId]);
    }

    public function forceVerify(int $userId, string $type, ?string $alias): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE users SET is_verified = 1, verified_type = :type, verified_alias = :alias
                WHERE id = :user_id
            ");
            return $stmt->execute([
                ':type' => $type,
                ':alias' => $alias,
                ':user_id' => $userId,
            ]);
        } catch (\Throwable $e) {
            error_log("VerificationRequestModel::forceVerify: " . $e->getMessage());
            return false;
        }
    }

    public function unverify(int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE users SET is_verified = 0, verified_type = NULL, verified_alias = NULL
                WHERE id = :user_id
            ");
            return $stmt->execute([':user_id' => $userId]);
        } catch (\Throwable $e) {
            error_log("VerificationRequestModel::unverify: " . $e->getMessage());
            return false;
        }
    }
}
