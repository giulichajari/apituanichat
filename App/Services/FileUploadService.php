<?php

namespace App\Services;

use App\Models\ChatModel;
use Exception;

class FileUploadService
{
    private $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'audio/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'audio/mp4' => 'mp4',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav'
    ];

    private $maxFileSize = 10 * 1024 * 1024;
    private $uploadPath = '/var/www/apituanichat/public/uploads/';
    // private $uploadPath = 'D:/pruebaschat/';
private function sendMessageToWebSocket($messageData)
{
    try {
        $chatModel = new ChatModel();
        
        // Determinar tipo
        $wsType = ($messageData['tipo'] === 'imagen') ? 'image_upload' : 'file_upload';
        
        // Guardar notificación en BD
        $notificationId = $chatModel->createWebSocketNotification([
            'chat_id' => $messageData['chat_id'],
            'user_id' => $messageData['user_id'],
            'message_type' => $wsType,
            'message_data' => json_encode([
                'type' => $wsType,
                'chat_id' => $messageData['chat_id'],
                'user_id' => $messageData['user_id'],
                'contenido' => $messageData['contenido'],
                'tipo' => $messageData['tipo'],
                'timestamp' => $messageData['timestamp'],
                'message_id' => $messageData['message_id'],
                'file_id' => $messageData['file_id'],
                'file_url' => $messageData['file_url'],
                'file_name' => $messageData['file_name'],
                'file_original_name' => $messageData['file_original_name'],
                'file_size' => $messageData['file_size'],
                'file_mime_type' => $messageData['file_mime_type'],
                'status' => 'delivered'
            ]),
            'status' => 'pending'
        ]);
        
        error_log("✅ Notificación guardada en BD - ID: {$notificationId}");
        
        // ⭐ OPCIONAL: Despertar el WebSocket server
        $this->wakeUpWebSocketServer();
        
        return true;
        
    } catch (Exception $e) {
        error_log("❌ Error en sendMessageToWebSocket: " . $e->getMessage());
        return false;
    }
}

private function wakeUpWebSocketServer()
{
    // Enviar señal simple al WebSocket server
    // Puede ser un archivo flag, una llamada HTTP, etc.
    try {
        // Opción 1: Archivo flag
        touch('/tmp/websocket_wakeup.flag');
        
        // Opción 2: Llamada HTTP ligera (si el WS server tiene endpoint)
        // file_get_contents('http://localhost:8081/wakeup');
        
        error_log("🔔 Señal enviada a WebSocket server");
    } catch (Exception $e) {
        // No es crítico si falla
    }
}

    private function broadcastViaSharedMemory($chatId, $message)
    {
        try {
            // Guardar mensaje en un archivo que el WebSocket puede leer
            $messageFile = '/tmp/websocket_broadcast_' . uniqid() . '.json';
            $data = [
                'chat_id' => $chatId,
                'message' => $message,
                'timestamp' => time()
            ];

            file_put_contents($messageFile, json_encode($data));
            error_log("✅ Mensaje guardado en {$messageFile} para WebSocket");

            return true;
        } catch (Exception $e) {
            error_log("❌ Error en broadcastViaSharedMemory: " . $e->getMessage());
            return false;
        }
    }
    public function uploadToConversation($file, $userId, $otherUserId = null, $chatId = null)
    {
        try {
            error_log("🔄 Iniciando uploadToConversation - User: {$userId}, Chat ID: {$chatId}");

            // Validaciones básicas
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Error en la subida del archivo: ' . $file['error']);
            }

            // Normalizar tipo (trim, minúsculas; quitar codecs)
            $rawType = isset($file['type']) ? trim((string)$file['type']) : '';
            $mimeForCheck = trim(strtolower(explode(';', $rawType)[0]));
            $isAudio = strpos($mimeForCheck, 'audio/') === 0;
            $isAllowed = isset($this->allowedTypes[$rawType])
                || isset($this->allowedTypes[$file['type']])
                || isset($this->allowedTypes[$mimeForCheck])
                || $isAudio;
            if (!$isAllowed) {
                throw new Exception('Tipo de archivo no permitido: ' . $rawType);
            }

            $chatModel = new ChatModel();

            // ✅ PASO 1: VALIDAR QUE EL CHAT EXISTA Y EL USUARIO PERTENEZCA A ÉL
            if ($chatId) {
                // Verificar que el chat existe y el usuario es participante
                if (!$this->validateChatAndUser($chatModel, $chatId, $userId)) {
                    throw new Exception('Chat no válido o usuario no pertenece al chat');
                }
                error_log("✅ Chat validado: {$chatId}");
            } else {
                // Si no hay chatId, usar la lógica anterior (crear/find)
                error_log("🔍 Buscando chat entre usuario: {$userId} y otro: {$otherUserId}");
                $chatId = $this->determineChatId($chatModel, $userId, $otherUserId);

                if (!$chatId) {
                    throw new Exception('No se pudo crear o encontrar el chat entre los usuarios');
                }
                error_log("✅ Chat determinado: {$chatId}");
            }

            // ✅ PASO 2: SUBIR EL ARCHIVO FÍSICO
            $fileInfo = $this->uploadPhysicalFile($file, $chatId, $userId);

            // ✅ PASO 3: GUARDAR EN BD (file_id)
            $fileId = $chatModel->saveFile([
                'name' => $fileInfo['fileName'],
                'original_name' => $file['name'],
                'path' => $fileInfo['filePath'],
                'url' => $fileInfo['fileUrl'],
                'size' => $file['size'],
                'mime_type' => $file['type'],
                'chat_id' => $chatId,
                'user_id' => $userId
            ]);

            if (!$fileId) {
                // Limpiar archivo físico si falla BD
                unlink($fileInfo['filePath']);
                throw new Exception('Error al guardar archivo en la base de datos');
            }

            error_log("✅ Archivo guardado en BD - File ID: {$fileId}");

            // ✅ PASO 4: CREAR MENSAJE
            $tipo = strpos($file['type'], 'image/') === 0 ? 'imagen' : (strpos($file['type'], 'audio/') === 0 ? 'audio' : 'archivo');

            // Si no hay otherUserId, obtenerlo del chat
            if (!$otherUserId) {
                $otherUserId = $chatModel->getOtherUserIdInChat($chatId, $userId);
            }

            $messageId = $chatModel->sendMessage(
                $chatId,      // chat_id
                $userId,      // user_id
                $file['name'], // contenido (nombre del archivo)
                $tipo,        // tipo (imagen/archivo)
                $fileId,      // file_id
                $otherUserId  // other_user_id (para notificaciones)
            );

            if (!$messageId) {
                // Si falla el mensaje, limpiar todo
                unlink($fileInfo['filePath']);
                $chatModel->deleteFile($fileId);
                throw new Exception('Error al crear el mensaje en el chat');
            }

            // ⭐⭐ PREPARAR DATOS COMPLETOS PARA EL WEBSOCKET
            $fullMessageData = [
                'type' => 'chat_message', // Este tipo es para referencia interna, el WebSocket lo cambiará
                'message_id' => $messageId,
                'chat_id' => $chatId,
                'user_id' => $userId,
                'contenido' => $file['name'],
                'tipo' => $tipo, // 'imagen' o 'archivo'
                'timestamp' => date('Y-m-d H:i:s'),
                'leido' => 0,
                // Campos de archivo
                'file_name' => $fileInfo['fileName'],
                'file_url' => $fileInfo['fileUrl'],
                'file_original_name' => $file['name'],
                'file_size' => $file['size'],
                'file_mime_type' => $file['type'],
                'file_id' => $fileId
            ];

            // ⭐⭐ ENVIAR AL WEBSOCKET
            $this->sendMessageToWebSocket($fullMessageData);

            error_log("✅ Mensaje completo preparado para WebSocket: " . json_encode([
                'message_id' => $messageId,
                'file_id' => $fileId,
                'tipo' => $tipo
            ]));

            return [
                'success' => true,
                'file_url' => $fileInfo['fileUrl'],
                'file_id' => $fileId,
                'message_id' => $messageId,
                'chat_id' => $chatId,
                'tipo' => $tipo,
                'file_name' => $fileInfo['fileName'],
                'file_original_name' => $file['name'],
                'file_size' => $file['size'],
                'file_mime_type' => $file['type'],
                // ⭐ AGREGAR MENSAJE COMPLETO PARA EL FRONTEND
                'message' => $fullMessageData
            ];
        } catch (Exception $e) {
            error_log("❌ Error en uploadToConversation: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }


    /**
     * ⭐ MÉTODO ALTERNATIVO usando tu WebSocket server existente
     */
    private function broadcastToChat($chatId, $messageData)
    {
        try {
            // Esta función depende de cómo tengas configurado tu WebSocket server
            // Si usas Ratchet o similar, necesitas acceder a las conexiones activas

            // Opción 1: Si tienes acceso global a las conexiones
            global $clients;
            if (isset($clients)) {
                foreach ($clients as $client) {
                    if (isset($client->chat_id) && $client->chat_id == $chatId) {
                        $client->send(json_encode($messageData));
                    }
                }
            }



            error_log("✅ Mensaje broadcast a chat {$chatId}");
        } catch (Exception $e) {
            error_log("❌ Error en broadcastToChat: " . $e->getMessage());
        }
    }

    // Método para validar chat y usuario
    private function validateChatAndUser($chatModel, $chatId, $userId)
    {
        try {
            // Verificar si el usuario es participante del chat
            $sql = "SELECT COUNT(*) as count 
                FROM chat_usuarios 
                WHERE chat_id = ? AND user_id = ?";

            $result = $chatModel->query($sql, [$chatId, $userId]);

            return ($result && $result[0]['count'] > 0);
        } catch (Exception $e) {
            error_log("❌ Error en validateChatAndUser: " . $e->getMessage());
            return false;
        }
    }
// En FileUploadService.php, agrega estos métodos:

    /**
     * ✅ OBTENER RUTA COMPLETA DEL ARCHIVO
     */
    public function getFullPath($relativePath)
    {
        return $this->uploadPath . ltrim($relativePath, '/');
    }

    /**
     * ✅ OBTENER URL PÚBLICA
     */
    public function getPublicUrl($relativePath)
    {
        // Ajustar según tu configuración
        return '/uploads/' . ltrim($relativePath, '/');
    }

    /**
     * ✅ VERIFICAR SI EL USUARIO TIENE ACCESO AL ARCHIVO
     */
    public function userHasAccessToFile($fileId, $userId)
    {
        try {
            // Esto depende de tu estructura de BD
            // Asumiendo que tienes una tabla de archivos con relación a chats
            $chatModel = new ChatModel();
            return $chatModel->userHasAccessToFile($fileId, $userId);
        } catch (Exception $e) {
            error_log("Error verificando acceso a archivo: " . $e->getMessage());
            return false;
        }
    }
    /**
     * ✅ DETERMINAR CHAT ID - Misma lógica que mensajes de texto
     */
    private function determineChatId($chatModel, $userId, $otherUserId)
    {
        try {
            // Buscar chat existente entre estos usuarios
            $existingChatId = $chatModel->findChatBetweenUsers($userId, $otherUserId);

            if ($existingChatId) {
                error_log("✅ Chat existente encontrado: {$existingChatId}");
                return $existingChatId;
            }

            // Crear nuevo chat
            error_log("🆕 Creando nuevo chat entre {$userId} y {$otherUserId}");
            $userIds = [$userId, $otherUserId];
            $newChatId = $chatModel->createChat($userIds);

            error_log("✅ Nuevo chat creado: {$newChatId}");
            return $newChatId;
        } catch (Exception $e) {
            error_log("❌ Error determinando chat: " . $e->getMessage());
            return null;
        }
    }

    /**
     * ✅ SUBIR ARCHIVO FÍSICO
     */
    private function uploadPhysicalFile($file, $chatId, $userId)
    {
        // Crear directorio
        $chatPath = $this->uploadPath . 'chats/' . $chatId . '/';
        if (!is_dir($chatPath)) {
            if (!mkdir($chatPath, 0755, true)) {
                throw new Exception('No se pudo crear el directorio: ' . $chatPath);
            }
        }

        // Verificar permisos
        if (!is_writable($chatPath)) {
            throw new Exception('El directorio no tiene permisos de escritura: ' . $chatPath);
        }

        // Generar nombre único (soporta audio con codecs, ej. audio/webm;codecs=opus)
        $rawType = isset($file['type']) ? trim($file['type']) : '';
        $mimeType = trim(strtolower(explode(';', $rawType)[0]));
        $extension = $this->allowedTypes[$file['type']] ?? $this->allowedTypes[$rawType] ?? $this->allowedTypes[$mimeType] ?? null;
        if (!$extension && strpos($mimeType, 'audio/') === 0) {
            $extension = ($mimeType === 'audio/ogg' || strpos($mimeType, 'ogg') !== false) ? 'ogg' : 'webm';
        }
        if (!$extension) {
            throw new Exception('Tipo de archivo no permitido: ' . $rawType);
        }
        $fileName = uniqid() . '_' . $userId . '.' . $extension;
        $filePath = $chatPath . $fileName;

        // Mover archivo
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            throw new Exception('Error al guardar el archivo en: ' . $filePath);
        }

        // URL accesible
        $fileUrl = '/uploads/chats/' . $chatId . '/' . $fileName;

        return [
            'fileName' => $fileName,
            'filePath' => $filePath,
            'fileUrl' => $fileUrl
        ];
    }

    /**
     * ✅ MÉTODO SIMPLIFICADO PARA CASOS ESPECÍFICOS
     */
    public function uploadFileSimple($file, $chatId, $userId)
    {
        try {
            // Validaciones
            if ($file['error'] !== UPLOAD_ERR_OK) {
                return ['success' => false, 'message' => 'Error en la subida del archivo'];
            }

            $mimeForCheck = isset($file['type']) ? explode(';', $file['type'])[0] : '';
            $allowed = isset($this->allowedTypes[$file['type']]) || isset($this->allowedTypes[$mimeForCheck]) || strpos($mimeForCheck, 'audio/') === 0;
            if (!$allowed) {
                return ['success' => false, 'message' => 'Tipo de archivo no permitido'];
            }

            $chatModel = new ChatModel();

            // Verificar que el chat existe
            if (!$chatModel->chatExists($chatId)) {
                return ['success' => false, 'message' => 'El chat no existe'];
            }

            // Subir archivo físico
            $fileInfo = $this->uploadPhysicalFile($file, $chatId, $userId);

            // Guardar en BD
            $fileId = $chatModel->saveFile([
                'name' => $fileInfo['fileName'],
                'original_name' => $file['name'],
                'path' => $fileInfo['filePath'],
                'url' => $fileInfo['fileUrl'],
                'size' => $file['size'],
                'mime_type' => $file['type'],
                'chat_id' => $chatId,
                'user_id' => $userId
            ]);

            if (!$fileId) {
                unlink($fileInfo['filePath']);
                return ['success' => false, 'message' => 'Error al guardar archivo en BD'];
            }

            // Crear mensaje directamente
            $tipo = strpos($file['type'], 'image/') === 0 ? 'imagen' : (strpos($file['type'], 'audio/') === 0 ? 'audio' : 'archivo');
            $messageId = $chatModel->insertMessage([
                'chat_id' => $chatId,
                'user_id' => $userId,
                'contenido' => $file['name'],
                'tipo' => $tipo,
                'file_id' => $fileId,
                'fecha' => date('Y-m-d H:i:s')
            ]);

            return [
                'success' => true,
                'file_url' => $fileInfo['fileUrl'],
                'file_id' => $fileId,
                'message_id' => $messageId,
                'tipo' => $tipo,
                'file_name' => $fileInfo['fileName'],
                'file_original_name' => $file['name'],
                'file_size' => $file['size'],
                'file_mime_type' => $file['type']
            ];
        } catch (Exception $e) {
            error_log("Error en uploadFileSimple: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error durante la subida: ' . $e->getMessage()];
        }
    }
    // ✅ AGREGAR ESTE MÉTODO QUE FALTA
    public function validateFile($file)
    {
        // Verificar errores de subida
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'message' => 'Error al subir el archivo: ' . $this->getUploadError($file['error'])
            ];
        }

        // Verificar tamaño
        if ($file['size'] > $this->maxFileSize) {
            return [
                'success' => false,
                'message' => 'El archivo es demasiado grande. Máximo ' . ($this->maxFileSize / 1024 / 1024) . 'MB permitido.'
            ];
        }

        // Verificar tipo
        if (!array_key_exists($file['type'], $this->allowedTypes)) {
            return [
                'success' => false,
                'message' => 'Tipo de archivo no permitido. Formatos aceptados: ' . implode(', ', array_keys($this->allowedTypes))
            ];
        }

        // Verificar seguridad básica
        if (!$this->isFileSafe($file)) {
            return [
                'success' => false,
                'message' => 'El archivo no es seguro'
            ];
        }

        return ['success' => true];
    }

    public function uploadProductFile($file, $userId, $productId = null)
    {
        try {
            // Validar el archivo
            $validation = $this->validateFile($file);
            if (!$validation['success']) {
                return $validation;
            }

            // Crear directorio para productos
            $productsPath = $this->uploadPath . 'products/';
            if (!is_dir($productsPath)) {
                if (!mkdir($productsPath, 0755, true)) {
                    return [
                        'success' => false,
                        'message' => 'No se pudo crear el directorio para productos'
                    ];
                }
            }

            // Directorio específico del producto si existe ID
            if ($productId) {
                $productPath = $productsPath . $productId . '/';
                if (!is_dir($productPath)) {
                    if (!mkdir($productPath, 0755, true)) {
                        return [
                            'success' => false,
                            'message' => 'No se pudo crear el directorio para el producto'
                        ];
                    }
                }
            } else {
                $productPath = $productsPath;
            }

            // Verificar permisos
            if (!is_writable($productPath)) {
                return [
                    'success' => false,
                    'message' => 'El directorio no tiene permisos de escritura'
                ];
            }

            // Generar nombre único
            $extension = $this->allowedTypes[$file['type']];
            $fileName = uniqid() . '_' . $userId . '.' . $extension;
            $filePath = $productPath . $fileName;

            // Mover archivo
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                return [
                    'success' => false,
                    'message' => 'Error al guardar el archivo en el servidor'
                ];
            }

            // Generar URL accesible
            if ($productId) {
                $fileUrl = '/uploads/products/' . $productId . '/' . $fileName;
            } else {
                $fileUrl = '/uploads/products/' . $fileName;
            }

            return [
                'success' => true,
                'file_name' => $fileName,
                'file_path' => $filePath,
                'file_url' => $fileUrl,
                'original_name' => $file['name'],
                'size' => $this->formatFileSize($file['size']),
                'mime_type' => $file['type']
            ];
        } catch (\Exception $e) {
            error_log("Error en uploadProductFile: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error durante la subida: ' . $e->getMessage()
            ];
        }
    }
    // Método auxiliar para formatear tamaño de archivo (si no lo tienes)
    private function formatFileSize($bytes)
    {
        if ($bytes == 0) return '0 B';

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
    public function deleteFile($filePath)
    {
        try {
            if (file_exists($filePath)) {
                unlink($filePath);
                return ['success' => true];
            }
            return ['success' => false, 'message' => 'Archivo no encontrado'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error al eliminar archivo: ' . $e->getMessage()];
        }
    }

    private function getUploadError($errorCode)
    {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'El archivo excede el tamaño máximo permitido',
            UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo del formulario',
            UPLOAD_ERR_PARTIAL => 'El archivo fue subido parcialmente',
            UPLOAD_ERR_NO_FILE => 'No se subió ningún archivo',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta el directorio temporal',
            UPLOAD_ERR_CANT_WRITE => 'Error al escribir en el disco',
            UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la subida'
        ];

        return $errors[$errorCode] ?? 'Error desconocido';
    }

    private function isFileSafe($file)
    {
        // Verificaciones básicas de seguridad
        $blacklistedExtensions = ['php', 'exe', 'js', 'html', 'htm'];
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (in_array($fileExtension, $blacklistedExtensions)) {
            return false;
        }

        return true;
    }
}
