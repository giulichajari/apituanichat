<?php

namespace App\Controllers;

use App\Models\ProfileModel;
use App\Models\UsersModel;
use EasyProjects\SimpleRouter\Router;

class ProfileController
{
    private ProfileModel $profileModel;
    private UsersModel $usersModel;

    public function __construct()
    {
        $this->profileModel = new ProfileModel();
        $this->usersModel = new UsersModel();
    }

    // Obtener perfil por user_id
    public function getProfile()
    {
        $userId = Router::$request->params->userId;
        $profile = $this->profileModel->getProfile($userId);

        if ($profile) {
            Router::$response->status(200)->send([
                "data" => $profile,
                "message" => "Profile retrieved successfully"
            ]);
        } else if (is_array($profile) && count($profile) === 0) {
            // ✅ Auto-crear perfil vacío para usuarios existentes (evita 404 en frontend)
            $user = $this->usersModel->getUser((int)$userId);
            $email = is_array($user) ? ($user['email'] ?? '') : '';
            $avatar = is_array($user) ? ($user['avatar'] ?? '') : '';

            $created = $this->profileModel->createProfile((int)$userId, [
                'bio' => '',
                'email' => $email,
                'website' => '',
                'instagram' => '',
                'facebook' => '',
                'twitter' => '',
                'linkedin' => '',
                'tiktok' => '',
                'avatar' => $avatar,
            ]);

            if ($created) {
                $profile = $this->profileModel->getProfile((int)$userId);
                Router::$response->status(200)->send([
                    "data" => $profile ?: [],
                    "message" => "Profile created automatically"
                ]);
            } else {
                Router::$response->status(500)->send([
                    "message" => "Error creating profile automatically"
                ]);
            }
        } else {
            Router::$response->status(500)->send([
                "message" => "An error occurred"
            ]);
        }
    }

    // Crear perfil
    public function createProfile()
    {
        $userId = Router::$request->body->userId;
        $data = [
            'bio' => Router::$request->body->bio ?? '',
            'email' => Router::$request->body->email ?? '',
            'website' => Router::$request->body->website ?? '',
            'instagram' => Router::$request->body->instagram ?? '',
            'facebook' => Router::$request->body->facebook ?? '',
            'twitter' => Router::$request->body->twitter ?? '',
            'linkedin' => Router::$request->body->linkedin ?? '',
            'tiktok' => Router::$request->body->tiktok ?? '',
            'avatar' => Router::$request->body->avatar ?? ''
        ];

        if ($this->profileModel->createProfile($userId, $data)) {
            Router::$response->status(201)->send([
                "message" => "Profile created successfully"
            ]);
        } else {
            Router::$response->status(500)->send([
                "message" => "An error occurred"
            ]);
        }
    }

    // Actualizar perfil
    public function updateProfile()
    {
        $userId = Router::$request->params->userId;
        $data = [
            'bio' => Router::$request->body->bio ?? '',
            'email' => Router::$request->body->email ?? '',
            'website' => Router::$request->body->website ?? '',
            'instagram' => Router::$request->body->instagram ?? '',
            'facebook' => Router::$request->body->facebook ?? '',
            'twitter' => Router::$request->body->twitter ?? '',
            'linkedin' => Router::$request->body->linkedin ?? '',
            'tiktok' => Router::$request->body->tiktok ?? '',
            'avatar' => Router::$request->body->avatar ?? ''
        ];

        if ($this->profileModel->updateProfile($userId, $data)) {
            Router::$response->status(200)->send([
                "message" => "Profile updated successfully"
            ]);
        } else {
            Router::$response->status(500)->send([
                "message" => "An error occurred"
            ]);
        }
    }

  public function updateAvatar()
    {
        $userId = Router::$request->params->userId;

        if (!isset($_FILES['avatar'])) {
            Router::$response->status(400)->json([
                "message" => "No avatar file uploaded"
            ]);
            return;
        }

        $file = array_map('trim', $_FILES['avatar']);

        // Guardar en public/uploads/avatars para que sea accesible vía web:
        // https://tuanichat.com/apituanichat/public/uploads/avatars/...
        $targetDir = __DIR__ . '/../../public/uploads/avatars/';

        // ✅ Crear directorio si no existe
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $filename = uniqid() . "_" . basename($file['name']);
        $targetFile = $targetDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $targetFile)) {
            $avatarPath = "/uploads/avatars/" . $filename;
            
            // ✅ CORREGIDO: Llamar SOLO UNA VEZ al método
            // Guardar en profiles y también en users para compatibilidad con listas/headers
            $okProfile = $this->profileModel->updateAvatar((int)$userId, $avatarPath);
            $okUser = $this->usersModel->updateUserAvatar((int)$userId, $avatarPath);

            if ($okProfile && $okUser) {
                Router::$response->status(200)->json([
                    "message" => "Avatar updated successfully",
                    "avatar" => $avatarPath
                ]);
            } else {
                Router::$response->status(500)->json([
                    "message" => "Error saving avatar path in database"
                ]);
            }
        } else {
            Router::$response->status(500)->json([
                "message" => "Error uploading avatar"
            ]);
        }
    }

    /**
     * Registrar token FCM del dispositivo para notificaciones push (llamadas y mensajes en segundo plano).
     * POST /profile/fcm-token con body { "fcm_token": "..." }
     */
    public function registerFcmToken()
    {
        $user = Router::$request->user ?? null;
        if (!$user || empty($user->id)) {
            Router::$response->status(401)->send(["message" => "No autenticado"]);
            return;
        }
        $body = Router::$request->body;
        $fcmToken = is_object($body) ? ($body->fcm_token ?? null) : ($body['fcm_token'] ?? null);
        if (!$fcmToken || !is_string($fcmToken) || trim($fcmToken) === '') {
            Router::$response->status(400)->send(["message" => "fcm_token requerido"]);
            return;
        }
        $ok = $this->usersModel->updateFcmToken((int)$user->id, trim($fcmToken));
        if ($ok) {
            Router::$response->status(200)->send(["message" => "Token FCM registrado"]);
        } else {
            Router::$response->status(500)->send(["message" => "Error al guardar token"]);
        }
    }

    /**
     * Servir imagen de avatar (busca en public/uploads y en uploads legacy)
     */
    public function serveAvatar($filename): void
    {
        try {
            $filename = basename(urldecode((string)$filename));
            if (empty($filename) || preg_match('/\.\./', $filename)) {
                Router::$response->status(400)->json(["message" => "Filename inválido"]);
                return;
            }
            $base = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
            $paths = [
                $base . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR . $filename,
                $base . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR . $filename,
            ];
            foreach ($paths as $filePath) {
                if (is_file($filePath) && is_readable($filePath)) {
                    $mime = @mime_content_type($filePath) ?: 'image/jpeg';
                    if (ob_get_level()) {
                        ob_end_clean();
                    }
                    header('Content-Type: ' . $mime);
                    header('Cache-Control: public, max-age=86400');
                    readfile($filePath);
                    exit(0);
                }
            }
            Router::$response->status(404)->json(["message" => "Avatar no encontrado"]);
        } catch (\Throwable $e) {
            error_log("serveAvatar error: " . $e->getMessage());
            Router::$response->status(500)->json(["message" => "Error al cargar avatar"]);
        }
    }
}
