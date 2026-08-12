<?php
namespace App\Controllers;

use App\Models\VerificationRequestModel;
use App\Models\UsersModel;
use App\Models\VerificationPaymentModel;
use EasyProjects\SimpleRouter\Router;

class VerificationController
{
    private VerificationRequestModel $verificationModel;
    private UsersModel $usersModel;
    private VerificationPaymentModel $verificationPaymentModel;

    private array $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];
    private int $maxFileSize = 10 * 1024 * 1024;

    public function __construct()
    {
        $this->verificationModel = new VerificationRequestModel();
        $this->usersModel = new UsersModel();
        $this->verificationPaymentModel = new VerificationPaymentModel();
    }

    public function submitRequest()
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }
        if (!$this->verificationPaymentModel->hasCompletedPayment((int)$userId)) {
            Router::$response->status(402)->json(["message" => "Debés completar el pago de la membresía anual antes de enviar la solicitud"]);
            return;
        }

        $category = $_POST['category'] ?? null;
        $alias = $_POST['alias'] ?? null;
        $fullName = $_POST['full_name'] ?? null;
        $documentNumber = $_POST['document_number'] ?? null;
        $message = $_POST['message'] ?? '';

        $validCategories = ['influencer', 'empresario', 'empresa', 'gobierno'];
        if (!in_array($category, $validCategories, true)) {
            Router::$response->status(400)->json(["message" => "Categoría inválida"]);
            return;
        }
        if (!$fullName || !$documentNumber) {
            Router::$response->status(400)->json(["message" => "Faltan datos requeridos: nombre completo y número de documento"]);
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

        $uploadDir = $this->getUploadDir($userId);

        $documentPhotoPath = $this->saveUploadedFile($_FILES['document_photo'], $uploadDir, 'document');
        if (!$documentPhotoPath) {
            Router::$response->status(400)->json(["message" => "Archivo de documento inválido (tipo o tamaño no permitido)"]);
            return;
        }

        $selfiePhotoPath = $this->saveUploadedFile($_FILES['selfie_photo'], $uploadDir, 'selfie');
        if (!$selfiePhotoPath) {
            Router::$response->status(400)->json(["message" => "Archivo de selfie inválido (tipo o tamaño no permitido)"]);
            return;
        }

        $ok = $this->verificationModel->create([
            'user_id' => $userId,
            'category' => $category,
            'alias' => $alias,
            'full_name' => $fullName,
            'document_number' => $documentNumber,
            'document_photo' => $documentPhotoPath,
            'selfie_photo' => $selfiePhotoPath,
            'message' => $message,
        ]);

        if (!$ok) {
            Router::$response->status(500)->json(["message" => "Error al guardar la solicitud"]);
            return;
        }

        Router::$response->status(201)->json(["message" => "Solicitud de verificación enviada"]);
    }

    public function getMyStatus()
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }
        $status = $this->verificationModel->getStatusForUser((int)$userId);
        Router::$response->status(200)->json(["status" => $status]);
    }

    public function listPending()
    {
        $user = Router::$request->user ?? null;
        if (!$user || ($user->rol ?? '') !== 'ADMIN') {
            Router::$response->status(403)->json(["message" => "Solo administradores"]);
            return;
        }
        $requests = $this->verificationModel->getPending();
        Router::$response->status(200)->json(["data" => $requests]);
    }

    public function approve()
    {
        $user = Router::$request->user ?? null;
        if (!$user || ($user->rol ?? '') !== 'ADMIN') {
            Router::$response->status(403)->json(["message" => "Solo administradores"]);
            return;
        }
        $requestId = Router::$request->params->id ?? null;
        if (!$requestId) {
            Router::$response->status(400)->json(["message" => "Falta el id de la solicitud"]);
            return;
        }
        $ok = $this->verificationModel->approve((int)$requestId, (int)$user->id);
        Router::$response->status($ok ? 200 : 500)->json([
            "message" => $ok ? "Solicitud aprobada" : "Error al aprobar la solicitud"
        ]);
    }

    public function reject()
    {
        $user = Router::$request->user ?? null;
        if (!$user || ($user->rol ?? '') !== 'ADMIN') {
            Router::$response->status(403)->json(["message" => "Solo administradores"]);
            return;
        }
        $requestId = Router::$request->params->id ?? null;
        if (!$requestId) {
            Router::$response->status(400)->json(["message" => "Falta el id de la solicitud"]);
            return;
        }
        $ok = $this->verificationModel->reject((int)$requestId, (int)$user->id);
        Router::$response->status($ok ? 200 : 500)->json([
            "message" => $ok ? "Solicitud rechazada" : "Error al rechazar la solicitud"
        ]);
    }

    private function getUploadDir(int $userId): string
    {
        $base = __DIR__ . '/../../public/uploads/verification/' . $userId . '/';
        if (!is_dir($base)) {
            mkdir($base, 0755, true);
        }
        $resolved = realpath($base);
        return ($resolved ?: $base) . DIRECTORY_SEPARATOR;
    }

    private function saveUploadedFile(array $file, string $dir, string $prefix): ?string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
        if ($file['size'] > $this->maxFileSize) {
            return null;
        }
        $mime = mime_content_type($file['tmp_name']);
        if (!in_array($mime, $this->allowedMimeTypes, true)) {
            return null;
        }
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $filename = $prefix . '_' . uniqid() . '.' . strtolower($ext);
        $target = $dir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return null;
        }
        return '/uploads/verification/' . basename(rtrim($dir, '/\\')) . '/' . $filename;
    }

    public function searchUsers()
    {
        $user = Router::$request->user ?? null;
        if (!$user || ($user->rol ?? '') !== 'ADMIN') {
            Router::$response->status(403)->json(["message" => "Solo administradores"]);
            return;
        }
        $query = $_GET['q'] ?? '';
        if (trim($query) === '') {
            Router::$response->status(200)->json(["data" => []]);
            return;
        }
        $results = $this->usersModel->searchUsers($query);
        Router::$response->status(200)->json(["data" => $results]);
    }

    public function forceVerify()
    {
        $user = Router::$request->user ?? null;
        if (!$user || ($user->rol ?? '') !== 'ADMIN') {
            Router::$response->status(403)->json(["message" => "Solo administradores"]);
            return;
        }
        $targetUserId = Router::$request->params->id ?? null;
        if (!$targetUserId) {
            Router::$response->status(400)->json(["message" => "Falta el id del usuario"]);
            return;
        }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $type = $input['type'] ?? 'admin';
        $alias = $input['alias'] ?? null;

        $ok = $this->verificationModel->forceVerify((int)$targetUserId, $type, $alias);
        Router::$response->status($ok ? 200 : 500)->json([
            "message" => $ok ? "Usuario verificado manualmente" : "Error al verificar el usuario"
        ]);
    }

    public function unverify()
    {
        $user = Router::$request->user ?? null;
        if (!$user || ($user->rol ?? '') !== 'ADMIN') {
            Router::$response->status(403)->json(["message" => "Solo administradores"]);
            return;
        }
        $targetUserId = Router::$request->params->id ?? null;
        if (!$targetUserId) {
            Router::$response->status(400)->json(["message" => "Falta el id del usuario"]);
            return;
        }
        $ok = $this->verificationModel->unverify((int)$targetUserId);
        Router::$response->status($ok ? 200 : 500)->json([
            "message" => $ok ? "Verificación removida" : "Error al remover la verificación"
        ]);
    }
}
