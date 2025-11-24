<?php

namespace App\Services;

use App\Models\ChatModel;

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

    private $maxFileSize = 10 * 1024 * 1024; // 10MB
    private $uploadPath =  '/var/www/apituanichat/public/uploads/';

    public function upload($file, $chatId, $userId, $otherUserId = null)
    {
        try {
            // Validar que el archivo se subió correctamente
            if ($file['error'] !== UPLOAD_ERR_OK) {
                return [
                    'success' => false,
                    'message' => 'Error en la subida del archivo: ' . $file['error']
                ];
            }

            // Validar tipo de archivo
            if (!isset($this->allowedTypes[$file['type']])) {
                return [
                    'success' => false,
                    'message' => 'Tipo de archivo no permitido'
                ];
            }

            // Instanciar ChatModel PRIMERO para determinar el chat correcto
            $chatModel = new ChatModel();

            // ✅ DETERMINAR EL CHAT_ID CORRECTO (misma lógica que mensajes de texto)
            $finalChatId = $this->determineChatId($chatModel, $chatId, $userId, $otherUserId);
            
            if (!$finalChatId) {
                return [
                    'success' => false,
                    'message' => 'No se pudo determinar o crear el chat para la conversación'
                ];
            }

            // Crear directorio si no existe (usando el chatId final)
            $chatPath = $this->uploadPath . 'chats/' . $finalChatId . '/';
            if (!is_dir($chatPath)) {
                if (!mkdir($chatPath, 0755, true)) {
                    return [
                        'success' => false,
                        'message' => 'No se pudo crear el directorio para el chat'
                    ];
                }
            }

            // Verificar permisos de escritura
            if (!is_writable($chatPath)) {
                return [
                    'success' => false,
                    'message' => 'El directorio no tiene permisos de escritura'
                ];
            }

            // Generar nombre único
            $extension = $this->allowedTypes[$file['type']];
            $fileName = uniqid() . '_' . $userId . '.' . $extension;
            $filePath = $chatPath . $fileName;

            // Mover archivo
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                return [
                    'success' => false,
                    'message' => 'Error al guardar el archivo en el servidor'
                ];
            }

            // Determinar tipo de mensaje
            $tipo = 'archivo';
            if (strpos($file['type'], 'image/') === 0) {
                $tipo = 'imagen';
            }

            // URL accesible
            $fileUrl = '/uploads/chats/' . $finalChatId . '/' . $fileName;

            // 1. Primero guardar el archivo en la tabla files
            $fileData = [
                'name' => $fileName,
                'original_name' => $file['name'],
                'path' => $filePath,
                'url' => $fileUrl,
                'size' => $file['size'],
                'mime_type' => $file['type'],
                'chat_id' => $finalChatId, // Usar el chatId final
                'user_id' => $userId
            ];

            $fileId = $chatModel->saveFile($fileData);

            if (!$fileId) {
                // Si falla guardar el archivo, eliminar el archivo subido
                unlink($filePath);
                return [
                    'success' => false,
                    'message' => 'Error al guardar la información del archivo en la base de datos'
                ];
            }

            // 2. ✅ USAR LA MISMA LÓGICA QUE LOS MENSAJES DE TEXTO
            // Si no tenemos otherUserId, intentar determinarlo
            if (!$otherUserId) {
                $otherUserId = $chatModel->getOtherUserFromChat($finalChatId, $userId);
            }

            // Enviar mensaje usando sendMessage (igual que texto)
            $messageId = $chatModel->sendMessage(
                $finalChatId,  // Usar el chatId final determinado
                $userId,
                $file['name'], // Nombre original como contenido
                $tipo,
                $fileId,
                $otherUserId   // Pasar otherUserId para la lógica de creación de chat
            );

            if (!$messageId) {
                // Si falla el mensaje, eliminar el archivo y el registro de files
                unlink($filePath);
                $chatModel->deleteFile($fileId);
                return [
                    'success' => false,
                    'message' => 'Error al guardar el mensaje en la base de datos'
                ];
            }

            return [
                'success' => true,
                'file_name' => $fileName,
                'file_path' => $filePath,
                'file_url' => $fileUrl,
                'file_id' => $fileId,
                'message_id' => $messageId,
                'chat_id' => $finalChatId, // Devolver el chatId usado
                'tipo' => $tipo,
                'original_name' => $file['name'],
                'size' => $this->formatFileSize($file['size'])
            ];
        } catch (\Exception $e) {
            // Log del error
            error_log("Error en upload: " . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Error durante la subida: ' . $e->getMessage()
            ];
        }
    }

    /**
     * ✅ MÉTODO CLAVE: Determinar el chatId correcto usando la misma lógica que los mensajes de texto
     */
    private function determineChatId($chatModel, $chatId, $userId, $otherUserId = null)
    {
        try {
            // Si el chat existe y es válido, usarlo
            if ($chatId && $chatModel->chatExists($chatId) && $chatModel->userInChat($chatId, $userId)) {
                return $chatId;
            }

            // Si tenemos otherUserId, buscar o crear chat entre estos usuarios
            if ($otherUserId && $otherUserId != $userId) {
                $existingChatId = $chatModel->findChatBetweenUsers($userId, $otherUserId);
                
                if ($existingChatId) {
                    error_log("✅ Chat existente encontrado: " . $existingChatId);
                    return $existingChatId;
                } else {
                    // Crear nuevo chat
                    $userIds = [$userId, $otherUserId];
                    $newChatId = $chatModel->createChat($userIds);
                    error_log("🆕 Nuevo chat creado: " . $newChatId);
                    return $newChatId;
                }
            }

            // Si no tenemos otherUserId pero tenemos un chatId que no existe, intentar determinar otherUserId
            if ($chatId && !$chatModel->chatExists($chatId)) {
                $otherUserId = $chatModel->getOtherUserFromChat($chatId, $userId);
                if ($otherUserId) {
                    return $this->determineChatId($chatModel, null, $userId, $otherUserId);
                }
            }

            return null;
            
        } catch (\Exception $e) {
            error_log("Error determinando chatId: " . $e->getMessage());
            return null;
        }
    }

    // MÉTODO ALTERNATIVO SIMPLIFICADO PARA SUBIR ARCHIVOS
    public function uploadSimple($file, $chatId, $userId)
    {
        try {
            // Validaciones básicas
            if ($file['error'] !== UPLOAD_ERR_OK) {
                return ['success' => false, 'message' => 'Error en la subida del archivo'];
            }

            if (!isset($this->allowedTypes[$file['type']])) {
                return ['success' => false, 'message' => 'Tipo de archivo no permitido'];
            }

            // Crear directorio
            $chatPath = $this->uploadPath . 'chats/' . $chatId . '/';
            if (!is_dir($chatPath) && !mkdir($chatPath, 0755, true)) {
                return ['success' => false, 'message' => 'No se pudo crear el directorio'];
            }

            // Generar nombre único y mover archivo
            $extension = $this->allowedTypes[$file['type']];
            $fileName = uniqid() . '_' . $userId . '.' . $extension;
            $filePath = $chatPath . $fileName;

            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                return ['success' => false, 'message' => 'Error al guardar el archivo'];
            }

            // Determinar tipo
            $tipo = strpos($file['type'], 'image/') === 0 ? 'imagen' : 'archivo';
            $fileUrl = '/uploads/chats/' . $chatId . '/' . $fileName;

            // Guardar en base de datos
            $chatModel = new ChatModel();
            
            $fileData = [
                'name' => $fileName,
                'original_name' => $file['name'],
                'path' => $filePath,
                'url' => $fileUrl,
                'size' => $file['size'],
                'mime_type' => $file['type'],
                'chat_id' => $chatId,
                'user_id' => $userId
            ];

            $fileId = $chatModel->saveFile($fileData);
            
            if (!$fileId) {
                unlink($filePath);
                return ['success' => false, 'message' => 'Error al guardar archivo en BD'];
            }

            // Enviar mensaje de forma simplificada
             // ✅ ACTUALIZAR EL MENSAJE CON EL FILE_ID
            $this->updateMessageFileId($messageId, $fileId);

            if (!$messageId) {
                unlink($filePath);
                $chatModel->deleteFile($fileId);
                return ['success' => false, 'message' => 'Error al guardar mensaje'];
            }

            return [
                'success' => true,
                'file_url' => $fileUrl,
                'file_id' => $fileId,
                'message_id' => $messageId,
                'tipo' => $tipo
            ];

        } catch (\Exception $e) {
            error_log("Error en uploadSimple: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error durante la subida'];
        }
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
