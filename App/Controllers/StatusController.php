<?php

namespace App\Controllers;
use EasyProjects\SimpleRouter\Router;

use App\Models\StatusModel;
use Exception;

class StatusController
{
    private $statusModel;

    public function __construct()
    {
        $this->statusModel = new StatusModel();
    }

    /**
     * Subir un nuevo estado
     */
    public function uploadStatus()
    {
        try {
            // El usuario ya está autenticado por el middleware
            $userId = Router::$request->user->id;

            // Verificar si se ha subido un archivo
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                Router::$response->status(400)->send([
                    "error" => "No se ha subido ningún archivo"
                ]);
            }

            $file = $_FILES['file'];
            $textContent = $_POST['text'] ?? '';

            // Validar tipo de archivo
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'video/mp4', 'video/quicktime'];
            if (!in_array($file['type'], $allowedTypes)) {
                Router::$response->status(400)->send([
                    "error" => "Tipo de archivo no permitido. Solo se permiten imágenes y videos."
                ]);
            }

            // Validar tamaño (máximo 50MB)
            $maxSize = 50 * 1024 * 1024; // 50MB
            if ($file['size'] > $maxSize) {
                Router::$response->status(400)->send([
                    "error" => "El archivo es demasiado grande. El tamaño máximo es 50MB."
                ]);
            }

            // Determinar tipo (image o video)
            $fileType = strpos($file['type'], 'image/') === 0 ? 'image' : 'video';

            // Crear directorio de uploads si no existe
            $uploadDir = __DIR__ . '/../../public/uploads/statuses/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            // Generar nombre único
            $fileName = uniqid('status_', true) . '_' . time();
            $fileExt = pathinfo($file['name'], PATHINFO_EXTENSION);
            $fullFileName = $fileName . '.' . $fileExt;
            $filePath = $uploadDir . $fullFileName;

            // Mover archivo
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                Router::$response->status(500)->send([
                    "error" => "Error al guardar el archivo en el servidor"
                ]);
            }

            // URL accesible
            $baseUrl = $_ENV['BASE_URL'] ?? 'http://localhost:3000';
            $fileUrl = $baseUrl . '/uploads/statuses/' . $fullFileName;

            // Insertar en la base de datos
            $statusId = $this->statusModel->createStatus($userId, $fileType, $fileUrl, $textContent);

            if (!$statusId) {
                Router::$response->status(500)->send([
                    "error" => "Error al crear el estado en la base de datos"
                ]);
            }

            // Obtener el estado recién creado
            $status = $this->statusModel->getStatusById($statusId, $userId);

            Router::$response->status(201)->send([
                "success" => true,
                "message" => "Estado subido exitosamente",
                "status" => $status
            ]);

        } catch (Exception $e) {
            Router::$response->status(500)->send([
                "error" => "Error interno del servidor: " . $e->getMessage()
            ]);
        }
    }

    /**
     * Obtener todos los estados activos
     */
    public function getAllStatuses()
    {
        try {
            $userId = Router::$request->user->id;

            $page = Router::$request->query->page ?? 1;
            $limit = Router::$request->query->limit ?? 20;
            $offset = ($page - 1) * $limit;

            $statuses = $this->statusModel->getActiveStatuses($userId, $limit, $offset);
            $total = $this->statusModel->getTotalActiveStatuses();

            Router::$response->send([
                "success" => true,
                "statuses" => $statuses,
                "pagination" => [
                    "page" => (int)$page,
                    "limit" => (int)$limit,
                    "total" => $total,
                    "pages" => ceil($total / $limit)
                ]
            ]);

        } catch (Exception $e) {
            Router::$response->status(500)->send([
                "error" => "Error interno del servidor: " . $e->getMessage()
            ]);
        }
    }

    /**
     * Obtener mis estados activos
     */
    public function getMyStatuses()
    {
        try {
            $userId = Router::$request->user->id;

            $statuses = $this->statusModel->getUserStatuses($userId);

            Router::$response->send([
                "success" => true,
                "statuses" => $statuses
            ]);

        } catch (Exception $e) {
            Router::$response->status(500)->send([
                "error" => "Error interno del servidor: " . $e->getMessage()
            ]);
        }
    }

    /**
     * Marcar un estado como visto
     */
    public function markAsViewed()
    {
        try {
            $userId = Router::$request->user->id;
            $statusId = Router::$request->params->id;

            if (empty($statusId)) {
                Router::$response->status(400)->send([
                    "error" => "ID de estado no especificado"
                ]);
            }

            $success = $this->statusModel->addView($statusId, $userId);

            Router::$response->send([
                "success" => $success,
                "message" => $success ? "Vista registrada" : "La vista ya estaba registrada"
            ]);

        } catch (Exception $e) {
            Router::$response->status(500)->send([
                "error" => "Error interno del servidor: " . $e->getMessage()
            ]);
        }
    }

    /**
     * Eliminar un estado
     */
    public function deleteStatus()
    {
        try {
            $userId = Router::$request->user->id;
            $statusId = Router::$request->params->id;

            if (empty($statusId)) {
                Router::$response->status(400)->send([
                    "error" => "ID de estado no especificado"
                ]);
            }

            $success = $this->statusModel->deleteStatus($statusId, $userId);

            if (!$success) {
                Router::$response->status(404)->send([
                    "error" => "Estado no encontrado o no tienes permisos para eliminarlo"
                ]);
            }

            Router::$response->send([
                "success" => true,
                "message" => "Estado eliminado exitosamente"
            ]);

        } catch (Exception $e) {
            Router::$response->status(500)->send([
                "error" => "Error interno del servidor: " . $e->getMessage()
            ]);
        }
    }

    /**
     * Obtener un estado específico
     */
    public function getStatus()
    {
        try {
            $userId = Router::$request->user->id;
            $statusId = Router::$request->params->id;

            if (empty($statusId)) {
                Router::$response->status(400)->send([
                    "error" => "ID de estado no especificado"
                ]);
            }

            $status = $this->statusModel->getStatusById($statusId, $userId);

            if (!$status) {
                Router::$response->status(404)->send([
                    "error" => "Estado no encontrado"
                ]);
            }

            Router::$response->send([
                "success" => true,
                "status" => $status
            ]);

        } catch (Exception $e) {
            Router::$response->status(500)->send([
                "error" => "Error interno del servidor: " . $e->getMessage()
            ]);
        }
    }
}