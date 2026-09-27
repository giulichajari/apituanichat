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
            $userId = Router::$request->user->id ?? null;
            if (!$userId) {
                Router::$response->status(401)->send(['error'=>'No autenticado']);
                return;
            }
            $file = $_FILES['file'] ?? [];
            try {
                $media = \App\Services\UploadedMedia::validate($file, true);
            } catch (\InvalidArgumentException $e) {
                Router::$response->status(400)->send(['error'=>$e->getMessage()]);
                return;
            }
            $textContent = $_POST['text'] ?? '';
            if (!is_string($textContent) || strlen($textContent) > 10000) {
                Router::$response->status(400)->send(['error'=>'Texto de estado inválido']);
                return;
            }
            $fileType = $media['type'];

            // Crear directorio de uploads si no existe
            $uploadDir = __DIR__ . '/../../public/uploads/statuses/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $fullFileName = $media['filename'];
            $filePath = $uploadDir . $fullFileName;

            // Mover archivo
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                Router::$response->status(500)->send([
                    "error" => "Error al guardar el archivo en el servidor"
                ]);
                return;
            }

            // URL accesible (evitar localhost en VPS).
            // Si hay proxy (nginx), usar X-Forwarded-Proto.
            $proto = 'http';
            if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
                $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'];
            } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
                $proto = 'https';
            }

            $host = $_SERVER['HTTP_HOST'] ?? 'tuanichat.com';
            $baseUrl = $proto . '://' . $host;

            // En este proyecto, los archivos se guardan bajo /apituanichat/public/uploads/...
            $fileUrl = $baseUrl . '/apituanichat/public/uploads/statuses/' . $fullFileName;

            // Insertar en la base de datos
            $statusId = $this->statusModel->createStatus($userId, $fileType, $fileUrl, $textContent);

            if (!$statusId) {
                @unlink($filePath);
                Router::$response->status(500)->send([
                    "error" => "Error al crear el estado en la base de datos"
                ]);
                return;
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
                "error" => "Error interno del servidor"
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
                "error" => "Error interno del servidor"
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
                "error" => "Error interno del servidor"
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
                "error" => "Error interno del servidor"
            ]);
        }
    }

    /**
     * Eliminar un estado
     */
    public function deleteStatus($routeId = null)
    {
        $userId = filter_var(Router::$request->user->id ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$userId) {
            Router::$response->status(401)->send(['error' => 'No autenticado']);
            return;
        }
        $statusId = filter_var(Router::$request->params->id ?? $routeId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$statusId) {
            Router::$response->status(400)->send(['error' => 'ID de estado inválido']);
            return;
        }
        try {
            if (!$this->statusModel->deleteStatus($statusId, $userId)) {
                Router::$response->status(404)->send(['error' => 'Estado no encontrado o no tienes permisos para eliminarlo']);
                return;
            }
            Router::$response->send(['success' => true, 'message' => (string)$statusId]);
        } catch (\Throwable $e) {
            error_log('Status delete failed: ' . get_class($e));
            Router::$response->status(500)->send(['error' => 'Error interno del servidor']);
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
                "error" => "Error interno del servidor"
            ]);
        }
    }
}