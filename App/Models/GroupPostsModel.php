<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class GroupPostsModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    private function generateStreamKey(): string
    {
        do {
            $key = bin2hex(random_bytes(16));
            $exists = $this->getPostByStreamKey($key);
        } while ($exists);

        return $key;
    }

    public function createLivePost(int $groupId, int $userId, string $visibility = 'public', ?float $giftAmount = null)
    {
        try {
            $streamKey = $this->generateStreamKey();

            $stmt = $this->db->prepare(
                "INSERT INTO group_posts (group_id, user_id, type, visibility, gift_amount, stream_key, status, created_at)
                 VALUES (:group_id, :user_id, 'live', :visibility, :gift_amount, :stream_key, 'scheduled', NOW())"
            );
            $stmt->execute([
                ':group_id'    => $groupId,
                ':user_id'     => $userId,
                ':visibility'  => $visibility,
                ':gift_amount' => $giftAmount,
                ':stream_key'  => $streamKey,
            ]);

            return [
                'post_id'    => (int) $this->db->lastInsertId(),
                'stream_key' => $streamKey,
            ];
        } catch (PDOException $e) {
            error_log("createLivePost ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function getPostById(int $postId)
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM group_posts WHERE id = :id AND deleted_at IS NULL");
            $stmt->execute([':id' => $postId]);
            $post = $stmt->fetch(PDO::FETCH_ASSOC);
            return $post ?: null;
        } catch (PDOException $e) {
            error_log("getPostById ERROR: " . $e->getMessage());
            return null;
        }
    }

    public function getPostByStreamKey(string $streamKey)
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM group_posts WHERE stream_key = :stream_key AND deleted_at IS NULL");
            $stmt->execute([':stream_key' => $streamKey]);
            $post = $stmt->fetch(PDO::FETCH_ASSOC);
            return $post ?: null;
        } catch (PDOException $e) {
            error_log("getPostByStreamKey ERROR: " . $e->getMessage());
            return null;
        }
    }

    public function getActiveLiveByGroup(int $groupId)
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM group_posts
                 WHERE group_id = :group_id AND type = 'live' AND status = 'live' AND deleted_at IS NULL
                 ORDER BY started_at DESC LIMIT 1"
            );
            $stmt->execute([':group_id' => $groupId]);
            $post = $stmt->fetch(PDO::FETCH_ASSOC);
            return $post ?: null;
        } catch (PDOException $e) {
            error_log("getActiveLiveByGroup ERROR: " . $e->getMessage());
            return null;
        }
    }

    public function markLiveStarted(string $streamKey): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE group_posts SET status = 'live', started_at = NOW()
                 WHERE stream_key = :stream_key AND type = 'live'"
            );
            $stmt->execute([':stream_key' => $streamKey]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("markLiveStarted ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function markLiveEnded(string $streamKey): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE group_posts SET status = 'ended', ended_at = NOW()
                 WHERE stream_key = :stream_key AND type = 'live'"
            );
            $stmt->execute([':stream_key' => $streamKey]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("markLiveEnded ERROR: " . $e->getMessage());
            return false;
        }
    }
}
