<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class StatusModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Crear un nuevo estado
     */
    public function createStatus(int $userId, string $fileType, string $fileUrl, string $textContent = ''): int|false
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO statuses (user_id, file_type, file_url, text_content, created_at) 
                VALUES (:user_id, :file_type, :file_url, :text_content, NOW())
            ");

            $stmt->execute([
                ':user_id' => $userId,
                ':file_type' => $fileType,
                ':file_url' => $fileUrl,
                ':text_content' => $textContent
            ]);

            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("CreateStatus ERROR: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtener todos los estados activos (no expirados)
     */
    public function getActiveStatuses(int $viewerId, int $limit = 20, int $offset = 0): array
    {
        try {
            // Primero, eliminar estados expirados (más de 24 horas)
            $this->cleanupExpiredStatuses();

            // Obtener estados activos con información del usuario y si el viewer los ha visto
            $stmt = $this->db->prepare("
                SELECT 
                    s.*,
                    u.id as user_id,
                    u.name as user_name,
                    u.avatar as user_avatar,
                    u.email as user_email,
                    COUNT(DISTINCT sv.id) as views_count,
                    MAX(CASE WHEN sv.viewer_id = :viewer_id THEN 1 ELSE 0 END) as viewed_by_me,
                    TIMESTAMPDIFF(SECOND, NOW(), s.expires_at) as seconds_remaining
                FROM statuses s
                INNER JOIN users u ON s.user_id = u.id
                LEFT JOIN status_views sv ON s.id = sv.status_id
                WHERE s.expires_at > NOW()
                GROUP BY s.id
                ORDER BY s.created_at DESC
                LIMIT :limit OFFSET :offset
            ");

            $stmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Formatear los datos
            foreach ($statuses as &$status) {
                $status['hours_remaining'] = floor($status['seconds_remaining'] / 3600);
                $status['minutes_remaining'] = floor(($status['seconds_remaining'] % 3600) / 60);
                $status['viewed_by_me'] = (bool)$status['viewed_by_me'];
                $status['user'] = [
                    'id' => $status['user_id'],
                    'name' => $status['user_name'],
                    'avatar' => $status['user_avatar'],
                    'email' => $status['user_email']
                ];
                // Eliminar campos duplicados
                unset($status['user_id'], $status['user_name'], $status['user_avatar'], $status['user_email']);
            }

            return $statuses;
        } catch (PDOException $e) {
            error_log("GetActiveStatuses ERROR: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtener estados de un usuario específico
     */
    public function getUserStatuses(int $userId): array
    {
        try {
            // Limpiar estados expirados primero
            $this->cleanupExpiredStatuses();

            $stmt = $this->db->prepare("
                SELECT 
                    s.*,
                    COUNT(sv.id) as views_count,
                    TIMESTAMPDIFF(SECOND, NOW(), s.expires_at) as seconds_remaining
                FROM statuses s
                LEFT JOIN status_views sv ON s.id = sv.status_id
                WHERE s.user_id = :user_id 
                AND s.expires_at > NOW()
                GROUP BY s.id
                ORDER BY s.created_at DESC
            ");

            $stmt->execute([':user_id' => $userId]);
            $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Formatear datos
            foreach ($statuses as &$status) {
                $status['hours_remaining'] = floor($status['seconds_remaining'] / 3600);
                $status['minutes_remaining'] = floor(($status['seconds_remaining'] % 3600) / 60);
                $status['views'] = $this->getStatusViews($status['id']);
            }

            return $statuses;
        } catch (PDOException $e) {
            error_log("GetUserStatuses ERROR: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtener un estado por ID
     */
    public function getStatusById(int $statusId, int $viewerId): array|false
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    s.*,
                    u.id as user_id,
                    u.name as user_name,
                    u.avatar as user_avatar,
                    COUNT(DISTINCT sv.id) as views_count,
                    MAX(CASE WHEN sv.viewer_id = :viewer_id THEN 1 ELSE 0 END) as viewed_by_me
                FROM statuses s
                INNER JOIN users u ON s.user_id = u.id
                LEFT JOIN status_views sv ON s.id = sv.status_id
                WHERE s.id = :status_id
                AND s.expires_at > NOW()
                GROUP BY s.id
            ");

            $stmt->execute([
                ':status_id' => $statusId,
                ':viewer_id' => $viewerId
            ]);

            $status = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($status) {
                $status['viewed_by_me'] = (bool)$status['viewed_by_me'];
                $status['user'] = [
                    'id' => $status['user_id'],
                    'name' => $status['user_name'],
                    'avatar' => $status['user_avatar']
                ];
                unset($status['user_id'], $status['user_name'], $status['user_avatar']);
            }

            return $status;
        } catch (PDOException $e) {
            error_log("GetStatusById ERROR: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Agregar una vista a un estado
     */
    public function addView(int $statusId, int $viewerId): bool
    {
        try {
            // Verificar si ya fue visto por este usuario
            $checkStmt = $this->db->prepare("
                SELECT id FROM status_views 
                WHERE status_id = :status_id AND viewer_id = :viewer_id
            ");
            $checkStmt->execute([
                ':status_id' => $statusId,
                ':viewer_id' => $viewerId
            ]);

            if ($checkStmt->fetch()) {
                return true; // Ya estaba visto
            }

            // Insertar nueva vista
            $stmt = $this->db->prepare("
                INSERT INTO status_views (status_id, viewer_id, viewed_at) 
                VALUES (:status_id, :viewer_id, NOW())
            ");

            $result = $stmt->execute([
                ':status_id' => $statusId,
                ':viewer_id' => $viewerId
            ]);

            // Actualizar contador de vistas en la tabla statuses
            if ($result) {
                $updateStmt = $this->db->prepare("
                    UPDATE statuses 
                    SET views_count = views_count + 1 
                    WHERE id = :status_id
                ");
                $updateStmt->execute([':status_id' => $statusId]);
            }

            return $result;
        } catch (PDOException $e) {
            error_log("AddView ERROR: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Eliminar un estado
     */
    public function deleteStatus(int $statusId, int $userId): bool
    {
        try {
            // Verificar que el estado pertenezca al usuario
            $stmt = $this->db->prepare("
                DELETE FROM statuses 
                WHERE id = :status_id AND user_id = :user_id
            ");

            return $stmt->execute([
                ':status_id' => $statusId,
                ':user_id' => $userId
            ]);
        } catch (PDOException $e) {
            error_log("DeleteStatus ERROR: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtener vistas de un estado
     */
    public function getStatusViews(int $statusId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    sv.*,
                    u.name as viewer_name,
                    u.avatar as viewer_avatar
                FROM status_views sv
                INNER JOIN users u ON sv.viewer_id = u.id
                WHERE sv.status_id = :status_id
                ORDER BY sv.viewed_at DESC
            ");

            $stmt->execute([':status_id' => $statusId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("GetStatusViews ERROR: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Limpiar estados expirados (más de 24 horas)
     */
    public function cleanupExpiredStatuses(): int
    {
        try {
            // Primero eliminar las vistas de estados expirados
            $deleteViewsStmt = $this->db->prepare("
                DELETE sv FROM status_views sv
                INNER JOIN statuses s ON sv.status_id = s.id
                WHERE s.expires_at <= NOW()
            ");
            $deleteViewsStmt->execute();

            // Luego eliminar los estados expirados
            $deleteStatusesStmt = $this->db->prepare("
                DELETE FROM statuses 
                WHERE expires_at <= NOW()
            ");
            $deleteStatusesStmt->execute();

            return $deleteStatusesStmt->rowCount();
        } catch (PDOException $e) {
            error_log("CleanupExpiredStatuses ERROR: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Obtener estados expirados (para tareas de limpieza)
     */
    public function getExpiredStatuses(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT s.*, u.email as user_email
                FROM statuses s
                INNER JOIN users u ON s.user_id = u.id
                WHERE s.expires_at <= NOW()
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("GetExpiredStatuses ERROR: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtener estadísticas de estados
     */
    public function getStatusStats(int $userId = null): array
    {
        try {
            $whereClause = $userId ? "WHERE s.user_id = :user_id" : "";
            $params = $userId ? [':user_id' => $userId] : [];

            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_statuses,
                    SUM(CASE WHEN s.expires_at > NOW() THEN 1 ELSE 0 END) as active_statuses,
                    SUM(s.views_count) as total_views,
                    COUNT(DISTINCT s.user_id) as total_users
                FROM statuses s
                $whereClause
            ");

            $stmt->execute($params);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("GetStatusStats ERROR: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Verificar si un usuario tiene estados activos
     */
    public function userHasActiveStatus(int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count 
                FROM statuses 
                WHERE user_id = :user_id 
                AND expires_at > NOW()
            ");
            $stmt->execute([':user_id' => $userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return ($result && $result['count'] > 0);
        } catch (PDOException $e) {
            error_log("UserHasActiveStatus ERROR: " . $e->getMessage());
            return false;
        }
    }
}