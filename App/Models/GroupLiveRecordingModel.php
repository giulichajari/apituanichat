<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class GroupLiveRecordingModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function create(int $postId, int $groupId, int $userId, string $filePath, int $durationSeconds, int $fileSizeBytes): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO group_live_recordings
                (post_id, group_id, user_id, file_path, duration_seconds, file_size_bytes)
            VALUES
                (:post_id, :group_id, :user_id, :file_path, :duration_seconds, :file_size_bytes)
        ");
        $stmt->execute([
            ':post_id' => $postId,
            ':group_id' => $groupId,
            ':user_id' => $userId,
            ':file_path' => $filePath,
            ':duration_seconds' => $durationSeconds,
            ':file_size_bytes' => $fileSizeBytes,
        ]);
        return (int) $this->db->lastInsertId();
    }

    // Lista de grabaciones de un grupo, más recientes primero, con nombre del host
    public function getByGroup(int $groupId, int $limit = 30): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT r.id, r.post_id, r.group_id, r.user_id, r.file_path,
                       r.duration_seconds, r.file_size_bytes, r.created_at,
                       u.name as host_name
                FROM group_live_recordings r
                JOIN users u ON u.id = r.user_id
                WHERE r.group_id = :group_id
                ORDER BY r.created_at DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':group_id', $groupId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("GroupLiveRecordingModel getByGroup ERROR: " . $e->getMessage());
            return [];
        }
    }

    public function getById(int $id): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM group_live_recordings WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }
}
