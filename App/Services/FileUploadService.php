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
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
    ];

    private $maxFileSize = 10 * 1024 * 1024;
    private $uploadPath = '/var/www/apituanichat/public/uploads/';
   // private $uploadPath = 'D:/pruebaschat/';

    /**
     * ✅ MÉTODO PRINCIPAL - Usa EXACTAMENTE la misma lógica que mensajes de texto
     */
    public function uploadToConversation($file, $userId, $otherUserId)
    {
        try {
            error_log("🔄 Iniciando uploadToConversation - User: {$userId}, Other: {$otherUserId}");

            // Validaciones básicas
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Error en la subida del archivo: ' . $file['error']);
            }

            if (!isset($this->allowedTypes[$file['type']])) {
                throw new Exception('Tipo de archivo no permitido: ' . $file['type']);
            }

            $chatModel = new ChatModel();
            
            // ✅ PASO 1: DETERMINAR EL CHAT (igual que mensajes de texto)
            // Esto automáticamente crea el chat si no existe
            $chatId = $this->determineChatId($chatModel, $userId, $otherUserId);
            
            if (!$chatId) {
                throw new Exception('No se pudo crear o encontrar el chat');
            }

            error_log("✅ Chat determinado: {$chatId}");

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

            // ✅ PASO 4: CREAR MENSAJE (igual que mensajes de texto)
            $tipo = strpos($file['type'], 'image/') === 0 ? 'imagen' : 'archivo';
            
            $messageId = $chatModel->sendMessage(
                $chatId,      // chat_id
                $userId,      // user_id
                $file['name'], // contenido (nombre del archivo)
                $tipo,        // tipo (imagen/archivo)
                $fileId,      // file_id
                $otherUserId  // other_user_id (para lógica de chat)
            );

            if (!$messageId) {
                // Si falla el mensaje, limpiar todo
                unlink($fileInfo['filePath']);
                $chatModel->deleteFile($fileId);
                throw new Exception('Error al crear el mensaje en el chat');
            }

            error_log("✅ Mensaje creado - Message ID: {$messageId}");

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
                'file_mime_type' => $file['type']
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

        // Generar nombre único
        $extension = $this->allowedTypes[$file['type']];
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

            if (!isset($this->allowedTypes[$file['type']])) {
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
            $tipo = strpos($file['type'], 'image/') === 0 ? 'imagen' : 'archivo';
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
     private function updateMessageFileId($messageId, $fileId): bool
    {
        try {
            $chatModel = new ChatModel();
            $stmt = $chatModel->getDb()->prepare("
                UPDATE mensajes SET file_id = ? WHERE id = ?
            ");
            return $stmt->execute([$fileId, $messageId]);
        } catch (\Exception $e) {
            error_log("Error actualizando file_id del mensaje: " . $e->getMessage());
            return false;
        }
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
