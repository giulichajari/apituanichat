<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class ReportModel
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
                "INSERT INTO user_reports (reporter_id, reported_user_id, context_type, context_id, reason, message, status, created_at)
                 VALUES (:reporter, :reported, :context_type, :context_id, :reason, :message, 'pending', NOW())"
            );
            return $stmt->execute([
                ':reporter'     => $data['reporter_id'],
                ':reported'     => $data['reported_user_id'],
                ':context_type' => $data['context_type'],
                ':context_id'   => $data['context_id'],
                ':reason'       => $data['reason'],
                ':message'      => $data['message'],
            ]);
        } catch (PDOException $e) {
            error_log("ReportModel::create ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function listPending(): array
    {
        $stmt = $this->db->prepare(
            "SELECT ur.*, reporter.name AS reporter_name, reported.name AS reported_name, reported.email AS reported_email
             FROM user_reports ur
             JOIN users reporter ON reporter.id = ur.reporter_id
             JOIN users reported ON reported.id = ur.reported_user_id
             WHERE ur.status = 'pending'
             ORDER BY ur.created_at ASC"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function setStatus(int $id, string $status, int $adminId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE user_reports SET status = :status, reviewed_by = :admin, reviewed_at = NOW() WHERE id = :id"
        );
        return $stmt->execute([':status' => $status, ':admin' => $adminId, ':id' => $id]);
    }
}
