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
// En App/Models/ChatModel.php - agrega este método si necesitas compatibilidad
/**
 * @deprecated Usar sendMessage en su lugar
 */

// En ChatModel.php - agrega este método para compatibilidad
/**
 * Método legacy para compatibilidad con FileUploadService
 * @deprecated Usar sendMessage en su lugar
 */
public function addMessage($messageData)
{
    try {
        error_log("⚠️ addMessage llamado (método legacy)");
        
        // Obtener other_user_id si no está en messageData
        $otherUserId = $messageData['other_user_id'] ?? null;
        if (!$otherUserId && isset($messageData['chat_id'])) {
            $otherUserId = $this->getOtherUserFromChat($messageData['chat_id'], $messageData['user_id']);
        }
        
        return $this->sendMessage(
            $messageData['chat_id'] ?? null,
            $messageData['user_id'] ?? null,
            $messageData['contenido'] ?? '',
            $messageData['tipo'] ?? 'texto',
            $messageData['file_id'] ?? null,
            $otherUserId
        );
    } catch (Exception $e) {
        error_log("Error en addMessage: " . $e->getMessage());
        throw $e;
    }
}



    // ✅ Crear chat - VERSIÓN OPTIMIZADA
 public function createChat(array $userIds, ?string $chatName = null): int
{
    try {
        $uniqueUserIds = array_unique($userIds);
        if (count($uniqueUserIds) < 2) {
            throw new Exception("Se necesitan al menos 2 usuarios diferentes para crear un chat");
        }

        // Verificar usuarios
        foreach ($uniqueUserIds as $userId) {
            if (!$this->userExists($userId)) {
                throw new Exception("El usuario {$userId} no existe");
            }
        }

        if (!$chatName) {
            $chatName = $this->generateChatName($uniqueUserIds);
        }

        // ✅ ELIMINAR beginTransaction() y commit()/rollBack()
        
        // 1. Crear el chat
        $stmt = $this->db->prepare("
            INSERT INTO chats (name, created_at, last_message_at) 
            VALUES (:name, NOW(), NOW())
        ");
        $stmt->execute([':name' => $chatName]);
        $chatId = (int)$this->db->lastInsertId();

        // 2. Agregar usuarios al chat
        $stmt = $this->db->prepare("
            INSERT INTO chat_usuarios (chat_id, user_id, added_at) 
            VALUES (?, ?, NOW())
        ");

        foreach ($uniqueUserIds as $userId) {
            $stmt->execute([$chatId, $userId]);
        }

        error_log("✅ Chat creado exitosamente - ID: {$chatId}");
        return $chatId;

    } catch (Exception $e) {
        // ✅ ELIMINAR rollBack() ya que no hay transacción
        error_log("❌ Error creando chat: " . $e->getMessage());
        throw $e;
    }
}

    // ✅ Método auxiliar para verificar si usuario existe
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

   // ✅ Enviar mensaje - VERSIÓN CORREGIDA
public function sendMessage($chatId, $userId, $contenido, $tipo = 'texto', $fileId = null, $otherUserId = null): int
{
    try {
        $finalChatId = $chatId;

        // ✅ SI el chat no existe O si chatId es el mismo que userId (error), crear nuevo chat
        if (!$this->chatExists($chatId) || $chatId == $userId) {
            if (!$otherUserId || $otherUserId == $userId) {
                throw new Exception("Se necesita un usuario diferente para crear un chat nuevo");
            }
            
            // ✅ BUSCAR PRIMERO si ya existe un chat entre estos usuarios
            $existingChatId = $this->findChatBetweenUsers($userId, $otherUserId);
            
            if ($existingChatId) {
                $finalChatId = $existingChatId;
                error_log("✅ Chat existente encontrado entre {$userId} y {$otherUserId}: {$finalChatId}");
            } else {
                // Crear nuevo chat entre los dos usuarios
                $userIds = [$userId, $otherUserId];
                $finalChatId = $this->createChat($userIds);
                error_log("🆕 Chat creado automáticamente - ID: {$finalChatId}, Usuarios: " . implode(', ', $userIds));
            }
        }

        // ✅ Verificar que el usuario pertenece al chat, si no, agregarlo
        if (!$this->userInChat($finalChatId, $userId)) {
            $this->addUserToChat($finalChatId, $userId);
            error_log("➕ Usuario {$userId} agregado al chat {$finalChatId}");
        }

        // ✅ INSERTAR MENSAJE CON EL CHAT_ID CORRECTO
        $stmt = $this->db->prepare("
            INSERT INTO mensajes (chat_id, user_id, contenido, tipo, file_id, enviado_en) 
            VALUES (:chat_id, :user_id, :contenido, :tipo, :file_id, NOW())
        ");
        $stmt->execute([
            ':chat_id' => $finalChatId, // ✅ MISMO chat_id para ambos usuarios
            ':user_id' => $userId,      // ✅ DIFERENTE user_id para cada mensaje
            ':contenido' => $contenido,
            ':tipo' => $tipo,
            ':file_id' => $fileId
        ]);

        $messageId = (int)$this->db->lastInsertId();

        // Actualizar last_message_at
        $this->updateChatLastMessage($finalChatId);

        error_log("✅ Mensaje enviado - Chat: {$finalChatId}, Usuario: {$userId}, Mensaje: {$messageId}");
        return $messageId;

    } catch (PDOException $e) {
        error_log("Error en sendMessage: " . $e->getMessage());
        throw $e;
    } catch (Exception $e) {
        error_log("Error de validación en sendMessage: " . $e->getMessage());
        throw $e;
    }
}

// ✅ Buscar chat existente entre dos usuarios
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
// En ChatModel.php
private $lastUsedChatId = null;

public function getLastUsedChatId()
{
    return $this->lastUsedChatId;
}


    // ✅ Método auxiliar para agregar usuario a chat
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
    // ✅ Enviar mensaje con verificación/creación de chat
    public function sendMessageWithChatCheck(array $userIds, string $contenido, string $tipo = 'texto', $fileId = null, $chatId = null): int
    {
        try {
            // ✅ Si no se proporciona chatId, buscar o crear uno
            if (!$chatId) {
                $chatId = $this->findOrCreateChat($userIds);
            }

            // ✅ Verificar que el chat existe
            if (!$this->chatExists($chatId)) {
                throw new Exception("El chat {$chatId} no existe después de la creación");
            }

            // ✅ Usar el primer usuario como remitente
            $senderId = $userIds[0];

            // ✅ Enviar el mensaje
            return $this->sendMessage($chatId, $senderId, $contenido, $tipo, $fileId);
        } catch (Exception $e) {
            error_log("Error en sendMessageWithChatCheck: " . $e->getMessage());
            throw $e;
        }
    }

    // ✅ Guardar archivo - VERSIÓN MEJORADA
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

    // ✅ Enviar mensaje con archivo - VERSIÓN UNIFICADA
    public function sendMessageWithFile($chatId, $userId, $contenido, array $fileData): int
    {
        try {
            $this->db->beginTransaction();

            // 1. Guardar archivo
            $fileId = $this->saveFile($fileData);

            // 2. Enviar mensaje con referencia al archivo
            $tipo = strpos($fileData['mime_type'], 'image/') === 0 ? 'imagen' : 'archivo';
            $messageId = $this->sendMessage($chatId, $userId, $contenido, $tipo, $fileId);

            $this->db->commit();
            return $messageId;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Error en sendMessageWithFile: " . $e->getMessage());
            throw $e;
        }
    }

    // ✅ Obtener mensajes - VERSIÓN MEJORADA
    public function getMessages($chatId, $userId = null): array
    {
        try {
            $limit = (int)($_GET['limit'] ?? 50);
            $offset = (int)($_GET['offset'] ?? 0);

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
                WHERE m.chat_id = :chat_id AND leido=0
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

    // ✅ Listar chats del usuario - VERSIÓN MEJORADA
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

    // ✅ Marcar como leído - VERSIÓN MEJORADA
  public function markAsRead($chatId, $userId): bool
{
    try {
        // ✅ Obtener mensajes no leídos que tengan más de 1 minuto de enviados
        $stmt = $this->db->prepare("
            SELECT id 
            FROM mensajes 
            WHERE chat_id = :chat_id 
            AND user_id != :user_id  -- Solo mensajes de otros usuarios
            AND leido = 0  -- Solo no leídos
            AND TIMESTAMPDIFF(MINUTE, enviado_en, NOW()) >= 1  -- Más de 1 minuto
            ORDER BY id DESC
        ");
        $stmt->execute([
            ':chat_id' => $chatId,
            ':user_id' => $userId
        ]);
        $messagesToMark = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($messagesToMark)) {
            error_log("ℹ️ No hay mensajes para marcar como leídos (chat: {$chatId}, usuario: {$userId})");
            return true;
        }

        // ✅ Marcar mensajes como leídos
        $placeholders = str_repeat('?,', count($messagesToMark) - 1) . '?';
        $stmt = $this->db->prepare("
            UPDATE mensajes 
            SET leido = 1, 
                leido_en = NOW()
            WHERE id IN ({$placeholders})
            AND leido = 0
        ");
        $stmt->execute($messagesToMark);
        $updatedRows = $stmt->rowCount();

        // ✅ Actualizar el último mensaje no leído en chat_usuarios
        $stmt = $this->db->prepare("
            UPDATE chat_usuarios 
            SET ultimo_no_leido = (
                SELECT COALESCE(MAX(id), 0) 
                FROM mensajes 
                WHERE chat_id = :chat_id 
                AND leido = 0
                AND user_id != :user_id
            ),
            leido = 1,
            ultima_vista = NOW()
            WHERE chat_id = :chat_id 
            AND user_id = :user_id
        ");
        $stmt->execute([
            ':chat_id' => $chatId,
            ':user_id' => $userId
        ]);

        error_log("✅ Marcados {$updatedRows} mensajes como leídos (chat: {$chatId}, usuario: {$userId})");

        return true;

    } catch (PDOException $e) {
        error_log("❌ Error marcando como leído: " . $e->getMessage());
        throw $e;
    }
}

// En tu ChatModel.php - agrega este método
public function insertMessage(array $messageData): int
{
    try {
        $stmt = $this->db->prepare("
            INSERT INTO mensajes (chat_id, user_id, contenido, tipo, file_id, enviado_en) 
            VALUES (:chat_id, :user_id, :contenido, :tipo, :file_id, :fecha)
        ");
        
        $stmt->execute([
            ':chat_id' => $messageData['chat_id'],
            ':user_id' => $messageData['user_id'],
            ':contenido' => $messageData['contenido'],
            ':tipo' => $messageData['tipo'],
            ':file_id' => $messageData['file_id'] ?? null,
            ':fecha' => $messageData['fecha'] ?? date('Y-m-d H:i:s')
        ]);

        $messageId = (int)$this->db->lastInsertId();
        
        // Actualizar last_message_at del chat
        $this->updateChatLastMessage($messageData['chat_id']);
        
        error_log("✅ Mensaje insertado directamente - ID: {$messageId}, Chat: {$messageData['chat_id']}");
        return $messageId;
        
    } catch (PDOException $e) {
        error_log("Error en insertMessage: " . $e->getMessage());
        throw $e;
    }
}

/**
 * ✅ Obtener la conexión a la base de datos (para FileUploadService)
 */
public function getDb()
{
    return $this->db;
}
    // ✅ Obtener información de un archivo
    public function getFile($fileId): array|false
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM files WHERE id = :file_id");
            $stmt->execute([':file_id' => $fileId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error obteniendo archivo: " . $e->getMessage());
            throw $e;
        }
    }

    // ✅ Eliminar archivo - VERSIÓN MEJORADA
    public function deleteFile($fileId): bool
    {
        try {
            // Primero obtener información del archivo
            $file = $this->getFile($fileId);
            if (!$file) {
                return false;
            }

            // Eliminar el archivo físico
            if (file_exists($file['path'])) {
                unlink($file['path']);
            }

            // Eliminar el registro de la base de datos
            $stmt = $this->db->prepare("DELETE FROM files WHERE id = :file_id");
            $stmt->execute([':file_id' => $fileId]);

            return true;
        } catch (PDOException $e) {
            error_log("Error al eliminar archivo: " . $e->getMessage());
            throw $e;
        }
    }

    // ==================== MÉTODOS PRIVADOS AUXILIARES ====================

    /**
     * Buscar chat existente o crear uno nuevo
     */
    public function findOrCreateChat(array $userIds): int
    {
        // Ordenar IDs para consistencia
        sort($userIds);

        // Buscar chat existente con exactamente estos usuarios
        $existingChat = $this->findChatByUsers($userIds);

        if ($existingChat) {
            error_log("✅ Chat existente encontrado: " . $existingChat);
            return $existingChat;
        }

        // Crear nuevo chat
        error_log("🆕 Creando nuevo chat para usuarios: " . implode(', ', $userIds));
        return $this->createChat($userIds);
    }

    /**
     * Buscar chat por usuarios exactos
     */
    public function findChatByUsers(array $userIds): int|false
    {
        try {
            $userCount = count($userIds);
            $placeholders = str_repeat('?,', $userCount - 1) . '?';

            $stmt = $this->db->prepare("
                SELECT c.id, COUNT(cu.user_id) as user_count
                FROM chats c
                JOIN chat_usuarios cu ON c.id = cu.chat_id
                WHERE cu.user_id IN ($placeholders)
                GROUP BY c.id
                HAVING COUNT(cu.user_id) = ? 
                   AND COUNT(cu.user_id) = (
                       SELECT COUNT(*) FROM chat_usuarios WHERE chat_id = c.id
                   )
            ");

            $params = array_merge($userIds, [$userCount]);
            $stmt->execute($params);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? (int)$result['id'] : false;
        } catch (Exception $e) {
            error_log("Error buscando chat por usuarios: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verificar si el chat existe
     */
    public function chatExists($chatId): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM chats WHERE id = ?");
        $stmt->execute([$chatId]);
        return $stmt->fetch() !== false;
    }
public function getChatIdByUsers($userId1, $userId2)
{
    $stmt = $this->db->prepare("
        SELECT c1.chat_id 
        FROM chat_usuarios c1
        INNER JOIN chat_usuarios c2 ON c1.chat_id = c2.chat_id
        WHERE c1.user_id = ? 
        AND c2.user_id = ?
        AND c1.chat_id IN (
            SELECT chat_id 
            FROM chat_usuarios 
            GROUP BY chat_id 
            HAVING COUNT(DISTINCT user_id) = 2
        )
        LIMIT 1
    ");
    
    $stmt->execute([$userId1, $userId2]);
    $result = $stmt->fetch(PDO::FETCH_OBJ);
    
    return $result ? $result->chat_id : null;
}
public function markMessagesAsRead($chatId, $currentUserId)
{
    $stmt = $this->db->prepare("
        UPDATE mensajes 
        SET leido = 1
           
        WHERE chat_id = ? 
        AND user_id != ? 
        AND leido = 0
    ");

    $stmt->execute([$chatId, $currentUserId]);
    return $stmt->rowCount();
}
    /**
     * Verificar si el usuario pertenece al chat
     */
    public function userInChat($chatId, $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1 FROM chat_usuarios 
            WHERE chat_id = ? AND user_id = ?
        ");
        $stmt->execute([$chatId, $userId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Generar nombre automático para el chat
     */
    private function generateChatName(array $userIds): string
    {
        if (count($userIds) === 2) {
            // Chat 1 a 1 - obtener nombres de usuarios
            $userNames = [];
            foreach ($userIds as $userId) {
                $userName = $this->getUserName($userId);
                if ($userName) {
                    $userNames[] = $userName;
                }
            }
            return count($userNames) >= 2 ? implode(' & ', $userNames) : 'Chat privado';
        } else {
            // Chat grupal
            return 'Grupo (' . count($userIds) . ' personas)';
        }
    }

    /**
     * Obtener nombre de usuario
     */
    private function getUserName($userId): string|null
    {
        try {
            $stmt = $this->db->prepare("SELECT name FROM usuarios WHERE id = ?");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['name'] : null;
        } catch (Exception $e) {
            error_log("Error obteniendo nombre de usuario {$userId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Método auxiliar para actualizar last_message_at
     */
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
