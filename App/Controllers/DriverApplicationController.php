<?php

namespace App\Controllers;

use App\Models\DriverApplicationModel;
use App\Models\DriverModel;
use App\Models\UsersModel;
use EasyProjects\SimpleRouter\Router;

class DriverApplicationController
{
    private DriverApplicationModel $model;
    private UsersModel $usersModel;
    private DriverModel $driverModel;

    private array $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
    ];

    private int $maxFileSize = 10 * 1024 * 1024;

    public function __construct()
    {
        $this->model = new DriverApplicationModel();
        $this->usersModel = new UsersModel();
        $this->driverModel = new DriverModel();
    }

    /** POST /drivers/applications — público (formulario Become a Driver) */
    public function submitApplication()
    {
        $payloadRaw = $_POST['payload'] ?? null;
        if (!$payloadRaw) {
            Router::$response->status(400)->json(['message' => 'Falta el payload del formulario']);
            return;
        }

        $payload = json_decode($payloadRaw, true);
        if (!is_array($payload)) {
            Router::$response->status(400)->json(['message' => 'Payload inválido']);
            return;
        }

        $fullName = trim($payload['fullName'] ?? '');
        $email = trim($payload['email'] ?? '');
        $phone = trim($payload['phone'] ?? '');

        if ($fullName === '' || $email === '' || $phone === '') {
            Router::$response->status(400)->json(['message' => 'Nombre, email y teléfono son obligatorios']);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Router::$response->status(400)->json(['message' => 'Email inválido']);
            return;
        }

        $terms = $payload['terms'] ?? [];
        $requiredTerms = ['confirmTruth', 'acceptPolicies', 'understandContractor', 'authorizeChecks', 'acceptFinancialTerms'];
        foreach ($requiredTerms as $key) {
            if (empty($terms[$key])) {
                Router::$response->status(400)->json(['message' => 'Debe aceptar todos los términos y condiciones']);
                return;
            }
        }

        if (empty($payload['signatureName']) || empty($payload['signatureData'])) {
            Router::$response->status(400)->json(['message' => 'La firma digital es obligatoria']);
            return;
        }

        $userId = null;
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
            try {
                $decoded = \Firebase\JWT\JWT::decode($m[1], new \Firebase\JWT\Key('TU_SECRET_KEY', 'HS256'));
                $userId = $decoded->user_id ?? null;
            } catch (\Throwable $e) {
                // Token opcional — continuar sin user_id
            }
        }

        $formData = $this->sanitizeFormData($payload);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $applicationId = $this->model->create([
            'user_id' => $userId,
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'form_data' => $formData,
            'documents' => [],
            'signature_name' => $payload['signatureName'],
            'signature_date' => date('Y-m-d'),
            'ip_address' => $ip,
        ]);

        if (!$applicationId) {
            Router::$response->status(500)->json(['message' => 'Error al guardar la solicitud']);
            return;
        }

        $uploadDir = $this->getUploadDir($applicationId);
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $documents = [];
        $fileFields = [
            'licenseFront' => 'license_front',
            'licenseBack' => 'license_back',
            'selfie' => 'selfie',
            'vehicleRegistration' => 'vehicle_registration',
            'insuranceProof' => 'insurance_proof',
        ];

        foreach ($fileFields as $postKey => $docKey) {
            if (!empty($_FILES[$postKey]) && $_FILES[$postKey]['error'] === UPLOAD_ERR_OK) {
                $saved = $this->saveUploadedFile($_FILES[$postKey], $uploadDir, $docKey);
                if ($saved) {
                    $documents[$docKey] = $saved;
                }
            }
        }

        $signaturePath = $this->saveSignatureImage($payload['signatureData'], $uploadDir);
        if ($signaturePath) {
            $documents['signature'] = $signaturePath;
            $this->model->updateSignature($applicationId, $signaturePath);
        }

        if (!empty($documents)) {
            $this->model->updateDocuments($applicationId, $documents);
        }

        $this->sendConfirmationEmail($email, $fullName, $applicationId);

        Router::$response->status(201)->json([
            'message' => 'Solicitud enviada correctamente',
            'data' => [
                'applicationId' => $applicationId,
                'status' => 'pending',
            ],
        ]);
    }

    /** GET /drivers/admin/dashboard — admin: stats, gráficos y aprobados */
    public function getAdminDashboard()
    {
        if (!$this->requireAdmin()) {
            return;
        }

        Router::$response->status(200)->json([
            'data' => [
                'stats' => $this->model->getDashboardStats(),
                'charts' => $this->model->getChartData(),
                'approvedDrivers' => $this->model->getApprovedDriversWithStats(100),
            ],
        ]);
    }

    /** GET /drivers/applications — admin */
    public function listApplications()
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $status = Router::$request->query->status ?? null;
        $applications = $this->model->findAll($status ?: null);

        Router::$response->status(200)->json([
            'data' => $applications,
            'message' => count($applications) . ' solicitudes encontradas',
        ]);
    }

    /** GET /drivers/applications/{id} — admin */
    public function getApplication()
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $id = (int) (Router::$request->params->id ?? 0);
        $application = $this->model->findById($id);

        if (!$application) {
            Router::$response->status(404)->json(['message' => 'Solicitud no encontrada']);
            return;
        }

        Router::$response->status(200)->json(['data' => $application]);
    }

    /** PATCH /drivers/applications/{id}/status — admin approve/reject */
    public function updateStatus()
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $id = (int) (Router::$request->params->id ?? 0);
        $status = Router::$request->body->status ?? '';

        if (!in_array($status, ['approved', 'rejected', 'pending'], true)) {
            Router::$response->status(400)->json(['message' => 'Estado inválido']);
            return;
        }

        $application = $this->model->findById($id);
        if (!$application) {
            Router::$response->status(404)->json(['message' => 'Solicitud no encontrada']);
            return;
        }

        if (!$this->model->updateStatus($id, $status)) {
            Router::$response->status(500)->json(['message' => 'Error al actualizar estado']);
            return;
        }

        if ($status === 'approved') {
            $this->activateDriverFromApplication($application);
        }

        Router::$response->status(200)->json([
            'message' => 'Estado actualizado correctamente',
            'data' => ['id' => $id, 'status' => $status],
        ]);
    }

    private function activateDriverFromApplication(array $application): void
    {
        $userId = $application['user_id'] ?? null;
        $formData = $application['form_data'] ?? [];

        if ($userId) {
            $vehicle = $formData['vehicle'] ?? [];
            $this->driverModel->updateByUserId((int) $userId, [
                'name' => $application['full_name'],
                'phone' => $application['phone'],
                'email' => $application['email'],
                'car_model' => trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '')),
                'license_plate' => $vehicle['plate'] ?? '',
                'location' => ($formData['personal']['city'] ?? '') . ', ' . ($formData['personal']['state'] ?? ''),
            ]);
        }
    }

    private function requireAdmin(): bool
    {
        $user = Router::$request->user ?? null;
        if (!$user || strtoupper($user->rol ?? '') !== 'ADMIN') {
            Router::$response->status(403)->json(['message' => 'Acceso denegado. Se requiere rol ADMIN']);
            return false;
        }
        return true;
    }

    private function getUploadDir(int $applicationId): string
    {
        $base = __DIR__ . '/../../uploads/drivers/' . $applicationId;
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

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'bin';
        $filename = $prefix . '_' . uniqid() . '.' . strtolower($ext);
        $target = $dir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return null;
        }

        return '/uploads/drivers/' . basename(rtrim($dir, '/\\')) . '/' . $filename;
    }

    private function saveSignatureImage(string $dataUrl, string $dir): ?string
    {
        if (!preg_match('/^data:image\/(\w+);base64,/', $dataUrl, $matches)) {
            return null;
        }

        $imageData = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1));
        if ($imageData === false) {
            return null;
        }

        $ext = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
        $filename = 'signature_' . uniqid() . '.' . $ext;
        $target = $dir . $filename;

        if (file_put_contents($target, $imageData) === false) {
            return null;
        }

        return '/uploads/drivers/' . basename(rtrim($dir, '/\\')) . '/' . $filename;
    }

    private function sanitizeFormData(array $payload): array
    {
        return [
            'personal' => [
                'fullName' => $payload['fullName'] ?? '',
                'dateOfBirth' => $payload['dateOfBirth'] ?? '',
                'phone' => $payload['phone'] ?? '',
                'email' => $payload['email'] ?? '',
                'address' => $payload['address'] ?? '',
                'city' => $payload['city'] ?? '',
                'state' => $payload['state'] ?? '',
                'zipCode' => $payload['zipCode'] ?? '',
            ],
            'identity' => [
                'licenseNumber' => $payload['licenseNumber'] ?? '',
                'licenseState' => $payload['licenseState'] ?? '',
                'licenseExpiry' => $payload['licenseExpiry'] ?? '',
            ],
            'legal' => $payload['legal'] ?? [],
            'vehicle' => $payload['vehicle'] ?? [],
            'insurance' => $payload['insurance'] ?? [],
            'experience' => $payload['experience'] ?? [],
            'availability' => $payload['availability'] ?? [],
            'payments' => [
                'bankName' => $payload['payments']['bankName'] ?? '',
                'routingNumber' => $payload['payments']['routingNumber'] ?? '',
                'accountNumber' => $payload['payments']['accountNumber'] ?? '',
                'paymentMethod' => $payload['payments']['paymentMethod'] ?? '',
            ],
            'emergency' => $payload['emergency'] ?? [],
            'terms' => $payload['terms'] ?? [],
        ];
    }

    private function sendConfirmationEmail(string $to, string $name, int $applicationId): void
    {
        $from = $_ENV['MAIL_FROM'] ?? 'noreply@tuanichat.com';
        $subject = 'TuaniChat — Solicitud de Driver recibida';
        $body = "Hola {$name},\n\n"
            . "Hemos recibido tu solicitud para convertirte en driver (#{$applicationId}).\n\n"
            . "Próximos pasos:\n"
            . "1. Nuestro equipo revisará tu documentación.\n"
            . "2. Recibirás un correo para revisar y firmar el contrato.\n"
            . "3. Una vez aprobado, podrás activar tu perfil de driver.\n\n"
            . "Gracias por unirte a TuaniChat.\n";

        $smtpHost = $_ENV['SMTP_HOST'] ?? null;

        if ($smtpHost && class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->CharSet = 'UTF-8';
                $mail->isSMTP();
                $mail->Host = $smtpHost;
                $mail->SMTPAuth = true;
                $mail->Username = $_ENV['SMTP_USER'] ?? '';
                $mail->Password = $_ENV['SMTP_PASS'] ?? '';
                $mail->SMTPSecure = $_ENV['SMTP_SECURE'] ?? 'tls';
                $mail->Port = (int) ($_ENV['SMTP_PORT'] ?? 587);
                $mail->setFrom($from, 'TuaniChat Drivers');
                $mail->addAddress($to, $name);
                $mail->Subject = $subject;
                $mail->Body = $body;
                $mail->send();
                return;
            } catch (\Throwable $e) {
                error_log('DriverApplication email error: ' . $e->getMessage());
            }
        }

        $headers = "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        @mail($to, $subject, $body, $headers);
    }
}
