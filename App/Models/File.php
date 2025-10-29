<?php

namespace App\Models;

use App\Configs\Database;
use PDO;

class File
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Crear un nuevo registro de archivo
     */
    public function create(array $data): ?int
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO files 
                (name, original_name, path, url, size, mime_type, chat_id, user_id, message_id, created_at)
                VALUES (:name, :original_name, :path, :url, :size, :mime_type, :chat_id, :user_id, :message_id, NOW())
            ");

            $stmt->execute([
                ':name' => $data['name'],
                ':original_name' => $data['original_name'],
                ':path' => $data['path'],
                ':url' => $data['url'],
                ':size' => $data['size'],
                ':mime_type' => $data['mime_type'],
                ':chat_id' => $data['chat_id'],
                ':user_id' => $data['user_id'],
                ':message_id' => $data['message_id'] ?? null
            ]);

            return $this->db->lastInsertId();
        } catch (\PDOException $e) {
            error_log("❌ Error al crear archivo: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtener archivo por ID
     */
    public function find(int $fileId): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT f.*, u.name as user_name, u.email as user_email
                FROM files f
                LEFT JOIN users u ON f.user_id = u.id
                WHERE f.id = ?
            ");
            $stmt->execute([$fileId]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
            return $file ?: null;
        } catch (\PDOException $e) {
            error_log("❌ Error al buscar archivo: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtener archivos por chat con paginación
     */
    public function getByChat(int $chatId, int $page = 1, int $limit = 20): array
    {
        try {
            $offset = ($page - 1) * $limit;
            
            $stmt = $this->db->prepare("
                SELECT f.*, u.name as user_name
                FROM files f
                LEFT JOIN users u ON f.user_id = u.id
                WHERE f.chat_id = ?
                ORDER BY f.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->bindValue(1, $chatId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("❌ Error al obtener archivos del chat: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Contar archivos por chat
     */
    public function countByChat(int $chatId): int
    {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM files WHERE chat_id = ?");
            $stmt->execute([$chatId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['total'] ?? 0;
        } catch (\PDOException $e) {
            error_log("❌ Error al contar archivos: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Obtener archivos por usuario
     */
    public function getByUser(int $userId, int $page = 1, int $limit = 20): array
    {
        try {
            $offset = ($page - 1) * $limit;
            
            $stmt = $this->db->prepare("
                SELECT f.*, c.name as chat_name
                FROM files f
                LEFT JOIN chats c ON f.chat_id = c.id
                WHERE f.user_id = ?
                ORDER BY f.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("❌ Error al obtener archivos del usuario: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Eliminar archivo por ID
     */
    public function delete(int $fileId): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM files WHERE id = ?");
            return $stmt->execute([$fileId]);
        } catch (\PDOException $e) {
            error_log("❌ Error al eliminar archivo: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Eliminar archivos por chat
     */
    public function deleteByChat(int $chatId): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM files WHERE chat_id = ?");
            return $stmt->execute([$chatId]);
        } catch (\PDOException $e) {
            error_log("❌ Error al eliminar archivos del chat: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Actualizar información del archivo
     */
    public function update(int $fileId, array $data): bool
    {
        try {
            $allowedFields = ['name', 'original_name', 'path', 'url', 'size', 'mime_type'];
            $updates = [];
            $params = [];

            foreach ($data as $key => $value) {
                if (in_array($key, $allowedFields)) {
                    $updates[] = "$key = ?";
                    $params[] = $value;
                }
            }

            if (empty($updates)) {
                return false;
            }

            $params[] = $fileId;
            $sql = "UPDATE files SET " . implode(', ', $updates) . " WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (\PDOException $e) {
            error_log("❌ Error al actualizar archivo: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Buscar archivos por nombre o tipo
     */
    public function search(int $chatId, string $query, int $page = 1, int $limit = 20): array
    {
        try {
            $offset = ($page - 1) * $limit;
            $searchTerm = "%$query%";
            
            $stmt = $this->db->prepare("
                SELECT f.*, u.name as user_name
                FROM files f
                LEFT JOIN users u ON f.user_id = u.id
                WHERE f.chat_id = ? 
                AND (f.original_name LIKE ? OR f.name LIKE ? OR f.mime_type LIKE ?)
                ORDER BY f.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->bindValue(1, $chatId, PDO::PARAM_INT);
            $stmt->bindValue(2, $searchTerm, PDO::PARAM_STR);
            $stmt->bindValue(3, $searchTerm, PDO::PARAM_STR);
            $stmt->bindValue(4, $searchTerm, PDO::PARAM_STR);
            $stmt->bindValue(5, $limit, PDO::PARAM_INT);
            $stmt->bindValue(6, $offset, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("❌ Error al buscar archivos: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtener estadísticas de archivos por chat
     */
    public function getStatsByChat(int $chatId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_files,
                    SUM(size) as total_size,
                    COUNT(DISTINCT user_id) as users_with_files,
                    mime_type,
                    COUNT(*) as type_count
                FROM files 
                WHERE chat_id = ?
                GROUP BY mime_type
            ");
            $stmt->execute([$chatId]);
            
            $stats = [
                'total_files' => 0,
                'total_size' => 0,
                'users_with_files' => 0,
                'types' => []
            ];

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($stats['total_files'] === 0) {
                    $stats['total_files'] = $row['total_files'];
                    $stats['total_size'] = $row['total_size'];
                    $stats['users_with_files'] = $row['users_with_files'];
                }
                $stats['types'][$row['mime_type']] = $row['type_count'];
            }

            return $stats;
        } catch (\PDOException $e) {
            error_log("❌ Error al obtener estadísticas: " . $e->getMessage());
            return [
                'total_files' => 0,
                'total_size' => 0,
                'users_with_files' => 0,
                'types' => []
            ];
        }
    }

    /**
     * Verificar si el usuario puede acceder al archivo
     */
    public function userCanAccess(int $fileId, int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as can_access
                FROM files f
                JOIN chat_usuarios cu ON f.chat_id = cu.chat_id
                WHERE f.id = ? AND cu.user_id = ?
            ");
            $stmt->execute([$fileId, $userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return ($result['can_access'] ?? 0) > 0;
        } catch (\PDOException $e) {
            error_log("❌ Error al verificar acceso: " . $e->getMessage());
            return false;
        }
    }
}