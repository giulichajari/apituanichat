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

    public function endOrphanedLivesByGroup(int $groupId): void
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE group_posts SET status = 'ended', ended_at = NOW()
                 WHERE group_id = :group_id AND type = 'live'
                 AND status IN ('live', 'scheduled') AND deleted_at IS NULL"
            );
            $stmt->execute([':group_id' => $groupId]);
        } catch (PDOException $e) {
            error_log("endOrphanedLivesByGroup ERROR: " . $e->getMessage());
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

    public function isViewerKicked(int $postId, int $userId): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT kicked FROM group_live_viewers WHERE post_id = :post_id AND user_id = :user_id LIMIT 1"
            );
            $stmt->execute([':post_id' => $postId, ':user_id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? ((int) $row['kicked'] === 1) : false;
        } catch (PDOException $e) {
            error_log("isViewerKicked ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function isViewerBlocked(int $groupId, int $userId, int $postId): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id FROM group_live_blocks
                 WHERE group_id = :group_id AND user_id = :user_id
                 AND (post_id IS NULL OR post_id = :post_id)
                 LIMIT 1"
            );
            $stmt->execute([
                ':group_id' => $groupId,
                ':user_id' => $userId,
                ':post_id' => $postId,
            ]);
            return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("isViewerBlocked ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function registerViewerHeartbeat(int $postId, int $userId): int
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id FROM group_live_viewers WHERE post_id = :post_id AND user_id = :user_id LIMIT 1"
            );
            $stmt->execute([':post_id' => $postId, ':user_id' => $userId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $upd = $this->db->prepare(
                    "UPDATE group_live_viewers SET last_heartbeat_at = NOW() WHERE id = :id"
                );
                $upd->execute([':id' => $existing['id']]);
            } else {
                $ins = $this->db->prepare(
                    "INSERT INTO group_live_viewers (post_id, user_id, joined_at, last_heartbeat_at, kicked)
                     VALUES (:post_id, :user_id, NOW(), NOW(), 0)"
                );
                $ins->execute([':post_id' => $postId, ':user_id' => $userId]);
            }

            $countStmt = $this->db->prepare(
                "SELECT COUNT(*) as total FROM group_live_viewers
                 WHERE post_id = :post_id AND kicked = 0
                 AND last_heartbeat_at > (NOW() - INTERVAL 30 SECOND)"
            );
            $countStmt->execute([':post_id' => $postId]);
            $activeCount = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $this->db->prepare(
                "UPDATE group_posts SET peak_viewers = GREATEST(COALESCE(peak_viewers, 0), :active)
                 WHERE id = :post_id"
            )->execute([':active' => $activeCount, ':post_id' => $postId]);

            return $activeCount;
        } catch (PDOException $e) {
            error_log("registerViewerHeartbeat ERROR: " . $e->getMessage());
            return 0;
        }
    }

    public function removeViewer(int $postId, int $userId): bool
    {
        try {
            $stmt = $this->db->prepare(
                "DELETE FROM group_live_viewers WHERE post_id = :post_id AND user_id = :user_id"
            );
            $stmt->execute([':post_id' => $postId, ':user_id' => $userId]);
            return true;
        } catch (PDOException $e) {
            error_log("removeViewer ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function getLiveSummary(int $postId)
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT gp.id, gp.started_at, gp.ended_at, gp.peak_viewers,
                        COALESCE((SELECT SUM(amount) FROM group_live_gifts WHERE post_id = gp.id AND estado = 'completado'), 0) as total_gifts,
                        COALESCE((SELECT COUNT(DISTINCT sender_user_id) FROM group_live_gifts WHERE post_id = gp.id AND estado = 'completado'), 0) as unique_donors
                 FROM group_posts gp
                 WHERE gp.id = :post_id AND gp.deleted_at IS NULL"
            );
            $stmt->execute([':post_id' => $postId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log("getLiveSummary ERROR: " . $e->getMessage());
            return null;
        }
    }

    public function getActiveViewersList(int $postId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT gv.user_id, u.name
                 FROM group_live_viewers gv
                 JOIN users u ON u.id = gv.user_id
                 WHERE gv.post_id = :post_id AND gv.kicked = 0
                 AND gv.last_heartbeat_at > (NOW() - INTERVAL 30 SECOND)
                 ORDER BY gv.joined_at ASC"
            );
            $stmt->execute([':post_id' => $postId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("getActiveViewersList ERROR: " . $e->getMessage());
            return [];
        }
    }

    public function kickViewer(int $postId, int $userId): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE group_live_viewers SET kicked = 1 WHERE post_id = :post_id AND user_id = :user_id"
            );
            $stmt->execute([':post_id' => $postId, ':user_id' => $userId]);
            return true;
        } catch (PDOException $e) {
            error_log("kickViewer ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function blockViewer(int $groupId, int $userId, int $blockedBy, string $scope, ?int $postId = null): bool
    {
        try {
            $scopedPostId = ($scope === 'live') ? $postId : null;

            $stmt = $this->db->prepare(
                "INSERT INTO group_live_blocks (group_id, user_id, post_id, blocked_by, created_at)
                 VALUES (:group_id, :user_id, :post_id, :blocked_by, NOW())"
            );
            $stmt->execute([
                ':group_id' => $groupId,
                ':user_id' => $userId,
                ':post_id' => $scopedPostId,
                ':blocked_by' => $blockedBy,
            ]);

            if ($postId) {
                $this->kickViewer($postId, $userId);
            }

            return true;
        } catch (PDOException $e) {
            error_log("blockViewer ERROR: " . $e->getMessage());
            return false;
        }
    }
}
