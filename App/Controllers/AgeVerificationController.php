<?php

namespace App\Controllers;

use App\Models\AgeVerificationModel;
use EasyProjects\SimpleRouter\Router;

class AgeVerificationController
{
    private AgeVerificationModel $model;

    private array $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    private int $maxFileSize = 10 * 1024 * 1024;

    public function __construct()
    {
        $this->model = new AgeVerificationModel();
    }

    public function submit()
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $fullName = $_POST['full_name'] ?? null;
        $documentNumber = $_POST['document_number'] ?? null;

        if (!$fullName || !$documentNumber) {
            Router::$response->status(400)->json(["message" => "Faltan datos requeridos: nombre completo y numero de documento"]);
            return;
        }
        if (empty($_FILES['document_photo']) || $_FILES['document_photo']['error'] !== UPLOAD_ERR_OK) {
            Router::$response->status(400)->json(["message" => "Falta la foto del documento"]);
            return;
        }
        if (empty($_FILES['selfie_photo']) || $_FILES['selfie_photo']['error'] !== UPLOAD_ERR_OK) {
            Router::$response->status(400)->json(["message" => "Falta la selfie"]);
            return;
        }

        $uploadDir = $this->getUploadDir((int) $userId);

        $documentPhotoPath = $this->saveUploadedFile($_FILES['document_photo'], $uploadDir, 'document');
        if (!$documentPhotoPath) {
            Router::$response->status(400)->json(["message" => "Archivo de documento invalido (tipo o tamano no permitido)"]);
            return;
        }

        $selfiePhotoPath = $this->saveUploadedFile($_FILES['selfie_photo'], $uploadDir, 'selfie');
        if (!$selfiePhotoPath) {
            Router::$response->status(400)->json(["message" => "Archivo de selfie invalido (tipo o tamano no permitido)"]);
            return;
        }

        $ok = $this->model->create([
            'user_id'         => $userId,
            'full_name'       => $fullName,
            'document_number' => $documentNumber,
            'document_photo'  => $documentPhotoPath,
            'selfie_photo'    => $selfiePhotoPath,
        ]);

        if (!$ok) {
            Router::$response->status(500)->json(["message" => "Error al guardar la solicitud"]);
            return;
        }

        Router::$response->status(201)->json(["message" => "Solicitud de verificacion de edad enviada"]);
    }

    public function getMyStatus()
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }
        $status = $this->model->getMyStatus((int) $userId);
        Router::$response->status(200)->json(["status" => $status]);
    }

    public function listPending()
    {
        $user = Router::$request->user ?? null;
        if (!$user || strtoupper($user->rol ?? '') !== 'ADMIN') {
            Router::$response->status(403)->json(["message" => "Solo administradores"]);
            return;
        }
        $requests = $this->model->listPending();
        Router::$response->status(200)->json(["data" => $requests]);
    }

    public function approve()
    {
        $user = Router::$request->user ?? null;
        if (!$user || strtoupper($user->rol ?? '') !== 'ADMIN') {
            Router::$response->status(403)->json(["message" => "Solo administradores"]);
            return;
        }
        $requestId = Router::$request->params->id ?? null;
        if (!$requestId) {
            Router::$response->status(400)->json(["message" => "Falta el id de la solicitud"]);
            return;
        }
        $ok = $this->model->approve((int) $requestId, (int) $user->id);
        Router::$response->status($ok ? 200 : 500)->json(["message" => $ok ? "Aprobado" : "Error al aprobar"]);
    }

    public function reject()
    {
        $user = Router::$request->user ?? null;
        if (!$user || strtoupper($user->rol ?? '') !== 'ADMIN') {
            Router::$response->status(403)->json(["message" => "Solo administradores"]);
            return;
        }
        $requestId = Router::$request->params->id ?? null;
        if (!$requestId) {
            Router::$response->status(400)->json(["message" => "Falta el id de la solicitud"]);
            return;
        }
        $ok = $this->model->reject((int) $requestId, (int) $user->id);
        Router::$response->status($ok ? 200 : 500)->json(["message" => $ok ? "Rechazado" : "Error al rechazar"]);
    }

    private function getUploadDir(int $userId): string
    {
        $base = __DIR__ . '/../../public/uploads/age-verification/' . $userId . '/';
        if (!is_dir($base)) {
            mkdir($base, 0755, true);
        }
        $resolved = realpath($base);
        return ($resolved ?: $base) . DIRECTORY_SEPARATOR;
    }

    private function saveUploadedFile(array $file, string $dir, string $prefix): ?string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) return null;
        if ($file['size'] > $this->maxFileSize) return null;
        $mime = mime_content_type($file['tmp_name']);
        if (!in_array($mime, $this->allowedMimeTypes, true)) return null;
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $filename = $prefix . '_' . uniqid() . '.' . strtolower($ext);
        $target = $dir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $target)) return null;
        return '/uploads/age-verification/' . basename(rtrim($dir, '/\\')) . '/' . $filename;
    }
}
