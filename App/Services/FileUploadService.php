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

    public function upload($file, $chatId, $userId)
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

            // Crear directorio si no existe
            $chatPath = $this->uploadPath . 'chats/' . $chatId . '/';
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
            } elseif (strpos($file['type'], 'video/') === 0) {
                $tipo = 'archivo'; // O 'video' si lo agregas al ENUM
            } elseif (strpos($file['type'], 'audio/') === 0) {
                $tipo = 'archivo'; // O 'audio' si lo agregas al ENUM
            }

            // URL accesible (ajustar según tu configuración)
            $fileUrl = '/uploads/chats/' . $chatId . '/' . $fileName;

            // Instanciar ChatModel
            $chatModel = new ChatModel();

            // 1. Primero guardar el archivo en la tabla files
            $fileData = [
                'name' => $fileName, // Nombre único generado
                'original_name' => $file['name'], // Nombre original del archivo
                'path' => $filePath, // Ruta física completa
                'url' => $fileUrl, // URL accesible
                'size' => $file['size'],
                'mime_type' => $file['type'],
                'chat_id' => $chatId,
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

            // 2. Luego crear el mensaje con referencia al file_id
            $messageData = [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'contenido' => $file['name'], // Nombre original como contenido del mensaje
                'tipo' => $tipo,
                'file_id' => $fileId
            ];

            $messageId = $chatModel->addMessage($messageData);

            if (!$messageId) {
                // Si falla el mensaje, eliminar el archivo y el registro de files
                unlink($filePath);
                // Opcional: eliminar el registro de files también
                // $chatModel->deleteFile($fileId);
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
