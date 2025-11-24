<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;
use Exception;

class ChatModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * @deprecated Usar sendMessage en su lugar
     */
    public function addMessage($messageData)
    {
        try {
            error_log("⚠️ addMessage llamado (método legacy)");
            
            return $this->sendMessage(
                $messageData['chat_id'] ?? null,
                $messageData['user_id'] ?? null,
                $messageData['contenido'] ?? '',
                $messageData['tipo'] ?? 'texto',
                $messageData['file_id'] ?? null,
                $messageData['other_user_id'] ?? null
            );
        } catch (Exception $e) {
            error_log("Error en addMessage: " . $e->getMessage());
            throw $e;
        }
    }

    public function createChat(array $userIds, ?string $chatName = null): int
    {
        try {
            $uniqueUserIds = array_unique($userIds);
            if (count($uniqueUserIds) < 2) {
                throw new Exception("Se necesitan al menos 2 usuarios diferentes para crear un chat");
            }

            foreach ($uniqueUserIds as $userId) {
                if (!$this->userExists($userId)) {
                    throw new Exception("El usuario {$userId} no existe");
                }
            }

            if (!$chatName) {
                $chatName = $this->generateChatName($uniqueUserIds);
            }

            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                INSERT INTO chats (name, created_at, last_message_at) 
                VALUES (:name, NOW(), NOW())
            ");
            $stmt->execute([':name' => $chatName]);
            $chatId = (int)$this->db->lastInsertId();

            $stmt = $this->db->prepare("
                INSERT INTO chat_usuarios (chat_id, user_id, added_at) 
                VALUES (?, ?, NOW())
            ");

            foreach ($uniqueUserIds as $userId) {
                $stmt->execute([$chatId, $userId]);
            }

            $this->db->commit();
            return $chatId;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("❌ Error creando chat: " . $e->getMessage());
            throw $e;
        }
    }

    public function sendMessage($chatId, $userId, $contenido, $tipo = 'texto', $fileId = null, $otherUserId = null): int
    {
        try {
            $finalChatId = $chatId;

            if (!$this->chatExists($chatId) || $chatId == $userId) {
                if (!$otherUserId || $otherUserId == $userId) {
                    throw new Exception("Se necesita un usuario diferente para crear un chat nuevo");
                }
                
                $existingChatId = $this->findChatBetweenUsers($userId, $otherUserId);
                
                if ($existingChatId) {
                    $finalChatId = $existingChatId;
                } else {
                    $userIds = [$userId, $otherUserId];
                    $finalChatId = $this->createChat($userIds);
                }
            }

            if (!$this->userInChat($finalChatId, $userId)) {
                $this->addUserToChat($finalChatId, $userId);
            }

            $stmt = $this->db->prepare("
                INSERT INTO mensajes (chat_id, user_id, contenido, tipo, file_id, enviado_en) 
                VALUES (:chat_id, :user_id, :contenido, :tipo, :file_id, NOW())
            ");
            $stmt->execute([
                ':chat_id' => $finalChatId,
                ':user_id' => $userId,
                ':contenido' => $contenido,
                ':tipo' => $tipo,
                ':file_id' => $fileId
            ]);

            $messageId = (int)$this->db->lastInsertId();
            $this->updateChatLastMessage($finalChatId);

            return $messageId;

        } catch (PDOException $e) {
            error_log("Error en sendMessage: " . $e->getMessage());
            throw $e;
        }
    }

    public function findChatBetweenUsers($user1, $user2)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT c.id
                FROM chats c
                INNER JOIN chat_usuarios cu1 ON c.id = cu1.chat_id AND cu1.user_id = ?
                INNER JOIN chat_usuarios cu2 ON c.id = cu2.chat_id AND cu2.user_id = ?
                WHERE (
                    SELECT COUNT(*) 
                    FROM chat_usuarios cu3 
                    WHERE cu3.chat_id = c.id
                ) = 2
                LIMIT 1
            ");
            $stmt->execute([$user1, $user2]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? (int)$result['id'] : null;

        } catch (Exception $e) {
            error_log("Error buscando chat entre usuarios: " . $e->getMessage());
            return null;
        }
    }

    public function getOtherUserFromChat($chatId, $currentUserId)
    {
        try {
            if (!$chatId) return null;

            $stmt = $this->db->prepare("
                SELECT user_id FROM chat_usuarios 
                WHERE chat_id = ? AND user_id != ?
                LIMIT 1
            ");
            $stmt->execute([$chatId, $currentUserId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? $result['user_id'] : null;
        } catch (Exception $e) {
            error_log("Error obteniendo otro usuario del chat: " . $e->getMessage());
            return null;
        }
    }

    public function saveFile(array $fileData): int
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO files (name, original_name, path, url, size, mime_type, chat_id, user_id, created_at) 
                VALUES (:name, :original_name, :path, :url, :size, :mime_type, :chat_id, :user_id, NOW())
            ");
            $stmt->execute([
                ':name' => $fileData['name'],
                ':original_name' => $fileData['original_name'],
                ':path' => $fileData['path'],
                ':url' => $fileData['url'],
                ':size' => $fileData['size'],
                ':mime_type' => $fileData['mime_type'],
                ':chat_id' => $fileData['chat_id'],
                ':user_id' => $fileData['user_id']
            ]);

            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Error al guardar archivo: " . $e->getMessage());
            throw $e;
        }
    }

    public function getMessages($chatId, $userId = null): array
    {
        try {
            $limit = 50;
            $offset = 0;

            $stmt = $this->db->prepare("
                SELECT 
                    m.*,
                    u.name as user_name,
                    u.email as user_email,
                    f.name as file_name,
                    f.original_name as file_original_name,
                    f.path as file_path,
                    f.url as file_url,
                    f.size as file_size,
                    f.mime_type as file_mime_type,
                    CASE WHEN m.user_id = :user_id THEN 1 ELSE 0 END as is_own
                FROM mensajes m
                LEFT JOIN users u ON m.user_id = u.id
                LEFT JOIN files f ON m.file_id = f.id
                WHERE m.chat_id = :chat_id
                ORDER BY m.enviado_en ASC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':chat_id', $chatId, PDO::PARAM_INT);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error obteniendo mensajes: " . $e->getMessage());
            throw $e;
        }
    }

    public function getChatsByUser($userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    c.*, 
                    (SELECT contenido FROM mensajes m WHERE m.chat_id = c.id ORDER BY m.id DESC LIMIT 1) as ultimo_mensaje,
                    (SELECT enviado_en FROM mensajes m WHERE m.chat_id = c.id ORDER BY m.id DESC LIMIT 1) as ultimo_mensaje_fecha
                FROM chats c
                JOIN chat_usuarios cu ON cu.chat_id = c.id
                WHERE cu.user_id = :user_id
                ORDER BY c.last_message_at DESC
            ");
            $stmt->execute([':user_id' => $userId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error obteniendo chats: " . $e->getMessage());
            throw $e;
        }
    }

    public function getDb()
    {
        return $this->db;
    }

    // ==================== MÉTODOS PRIVADOS ====================

    private function userExists($userId): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT id FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            return $stmt->fetch() !== false;
        } catch (Exception $e) {
            error_log("Error verificando existencia de usuario {$userId}: " . $e->getMessage());
            return false;
        }
    }

    private function chatExists($chatId): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM chats WHERE id = ?");
        $stmt->execute([$chatId]);
        return $stmt->fetch() !== false;
    }

    private function userInChat($chatId, $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1 FROM chat_usuarios 
            WHERE chat_id = ? AND user_id = ?
        ");
        $stmt->execute([$chatId, $userId]);
        return $stmt->fetch() !== false;
    }

    private function addUserToChat($chatId, $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO chat_usuarios (chat_id, user_id, added_at) 
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([$chatId, $userId]);
            return true;
        } catch (Exception $e) {
            error_log("Error agregando usuario al chat: " . $e->getMessage());
            throw $e;
        }
    }

    private function generateChatName(array $userIds): string
    {
        if (count($userIds) === 2) {
            $userNames = [];
            foreach ($userIds as $userId) {
                $userName = $this->getUserName($userId);
                if ($userName) {
                    $userNames[] = $userName;
                }
            }
            return count($userNames) >= 2 ? implode(' & ', $userNames) : 'Chat privado';
        } else {
            return 'Grupo (' . count($userIds) . ' personas)';
        }
    }

    private function getUserName($userId): string|null
    {
        try {
            $stmt = $this->db->prepare("SELECT name FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['name'] : null;
        } catch (Exception $e) {
            error_log("Error obteniendo nombre de usuario {$userId}: " . $e->getMessage());
            return null;
        }
    }

    private function updateChatLastMessage($chatId): void
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE chats SET last_message_at = NOW()
                WHERE id = :chat_id
            ");
            $stmt->execute([':chat_id' => $chatId]);
        } catch (PDOException $e) {
            error_log("Error al actualizar last_message_at: " . $e->getMessage());
            throw $e;
        }
    }
}