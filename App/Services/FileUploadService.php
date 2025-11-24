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

    private $maxFileSize = 10 * 1024 * 1024;
    private $uploadPath = '/var/www/apituanichat/public/uploads/';

    public function uploadToConversation($file, $userId, $otherUserId)
    {
        try {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                return ['success' => false, 'message' => 'Error en la subida del archivo'];
            }

            if (!isset($this->allowedTypes[$file['type']])) {
                return ['success' => false, 'message' => 'Tipo de archivo no permitido'];
            }

            $chatModel = new ChatModel();
            
            $chatId = $chatModel->findChatBetweenUsers($userId, $otherUserId);
            
            if (!$chatId) {
                $userIds = [$userId, $otherUserId];
                $chatId = $chatModel->createChat($userIds);
            }

            $chatPath = $this->uploadPath . 'chats/' . $chatId . '/';
            if (!is_dir($chatPath) && !mkdir($chatPath, 0755, true)) {
                return ['success' => false, 'message' => 'No se pudo crear el directorio'];
            }

            $extension = $this->allowedTypes[$file['type']];
            $fileName = uniqid() . '_' . $userId . '.' . $extension;
            $filePath = $chatPath . $fileName;

            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                return ['success' => false, 'message' => 'Error al guardar el archivo'];
            }

            $tipo = strpos($file['type'], 'image/') === 0 ? 'imagen' : 'archivo';
            $fileUrl = '/uploads/chats/' . $chatId . '/' . $fileName;

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

            $messageId = $chatModel->sendMessage(
                $chatId,
                $userId,
                $file['name'],
                $tipo,
                $fileId,
                $otherUserId
            );

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
                'chat_id' => $chatId,
                'tipo' => $tipo
            ];

        } catch (\Exception $e) {
            error_log("Error en uploadToConversation: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error durante la subida: ' . $e->getMessage()];
        }
    }

    public function validateFile($file)
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'message' => 'Error al subir el archivo: ' . $this->getUploadError($file['error'])
            ];
        }

        if ($file['size'] > $this->maxFileSize) {
            return [
                'success' => false,
                'message' => 'El archivo es demasiado grande. Máximo ' . ($this->maxFileSize / 1024 / 1024) . 'MB permitido.'
            ];
        }

        if (!array_key_exists($file['type'], $this->allowedTypes)) {
            return [
                'success' => false,
                'message' => 'Tipo de archivo no permitido.'
            ];
        }

        return ['success' => true];
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
}