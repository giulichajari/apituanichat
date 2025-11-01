<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class ChatModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // ✅ Crear chat
    public function createChat(array $userIds): int|false
    {
        try {
            $this->db->beginTransaction();

            $this->db->exec("INSERT INTO chats () VALUES ()");
            $chatId = (int)$this->db->lastInsertId();

            $stmt = $this->db->prepare("INSERT INTO chat_usuarios (chat_id, user_id) VALUES (:chat_id, :user_id)");
            foreach ($userIds as $uid) {
                $stmt->execute([
                    ':chat_id' => $chatId,
                    ':user_id' => $uid
                ]);
            }

            $this->db->commit();
            return $chatId;
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("Error creating chat: " . $e->getMessage());
            return false;
        }
    }

    // ✅ Guardar archivo en tabla files
    public function saveFile($fileData)
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

            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Error al guardar archivo: " . $e->getMessage());
            return false;
        }
    }

    // ✅ Enviar mensaje - VERSIÓN NORMALIZADA
    public function sendMessage($chatId, $userId, $contenido, $tipo = 'texto', $fileId = null)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO mensajes (chat_id, user_id, contenido, tipo, file_id, enviado_en) 
                VALUES (:chat_id, :user_id, :contenido, :tipo, :file_id, NOW())
            ");
            $stmt->execute([
                ':chat_id' => $chatId,
                ':user_id' => $userId,
                ':contenido' => $contenido,
                ':tipo' => $tipo,
                ':file_id' => $fileId
            ]);

            $messageId = $this->db->lastInsertId();

            // Actualizar last_message_at
            $this->updateChatLastMessage($chatId);

            return $messageId;
        } catch (PDOException $e) {
            error_log("Error en sendMessage: " . $e->getMessage());
            throw $e;
        }
    }

    // ✅ AddMessage - VERSIÓN NORMALIZADA
    public function addMessage($messageData)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO mensajes (chat_id, user_id, contenido, tipo, file_id, enviado_en) 
                VALUES (:chat_id, :user_id, :contenido, :tipo, :file_id, NOW())
            ");

            $stmt->execute([
                ':chat_id' => $messageData['chat_id'],
                ':user_id' => $messageData['user_id'],
                ':contenido' => $messageData['contenido'],
                ':tipo' => $messageData['tipo'],
                ':file_id' => $messageData['file_id'] ?? null
            ]);

            $messageId = $this->db->lastInsertId();

            // Actualizar last_message_at del chat
            $this->updateChatLastMessage($messageData['chat_id']);

            return $messageId;
        } catch (\Exception $e) {
            error_log("Error al crear mensaje: " . $e->getMessage());
            return false;
        }
    }

    // ✅ Método auxiliar para actualizar last_message_at
    private function updateChatLastMessage($chatId)
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE chats SET last_message_at = NOW()
                WHERE id = :chat_id
            ");
            $stmt->execute([':chat_id' => $chatId]);
        } catch (PDOException $e) {
            error_log("Error al actualizar last_message_at: " . $e->getMessage());
        }
    }

    // ✅ Obtener mensajes con JOIN a files
    public function getMessages($chatId, $userId = null)
    {
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
            WHERE m.chat_id = :chat_id
            ORDER BY m.id ASC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':chat_id', $chatId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ✅ Marcar como leído
    public function markAsRead($chatId, $userId)
    {
        // Obtener último mensaje
        $stmt = $this->db->prepare("
            SELECT id FROM mensajes 
            WHERE chat_id = :chat_id 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':chat_id' => $chatId]);
        $lastId = $stmt->fetchColumn();

        $stmt = $this->db->prepare("
            UPDATE chat_usuarios 
            SET ultimo_no_leido = :ultimo, leido = 1
            WHERE chat_id = :chat_id AND user_id = :user_id
        ");
        $stmt->execute([
            ':ultimo' => $lastId ?? 0,
            ':chat_id' => $chatId,
            ':user_id' => $userId
        ]);

        return true;
    }

    // ✅ Listar chats del usuario
    public function getChatsByUser($userId)
    {
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
    }

    // ✅ Obtener información de un archivo
    public function getFile($fileId)
    {
        $stmt = $this->db->prepare("
            SELECT * FROM files WHERE id = :file_id
        ");
        $stmt->execute([':file_id' => $fileId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // ✅ Método para enviar mensaje con archivo (conveniencia)
    public function sendMessageWithFile($chatId, $userId, $contenido, $fileData)
    {
        try {
            $this->db->beginTransaction();

            // 1. Guardar archivo
            $fileId = $this->saveFile($fileData);
            if (!$fileId) {
                throw new \Exception("Error al guardar archivo");
            }

            // 2. Enviar mensaje con referencia al archivo
            $messageId = $this->sendMessage($chatId, $userId, $contenido, $fileData['mime_type'] ?? 'archivo', $fileId);

            $this->db->commit();
            return $messageId;
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("Error en sendMessageWithFile: " . $e->getMessage());
            return false;
        }
    }
    // En ChatModel - método opcional para limpieza
    public function deleteFile($fileId)
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
            return false;
        }
    }
}
