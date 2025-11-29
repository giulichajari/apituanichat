<?php

namespace App\Controllers;

use App\Models\ProfileModel;
use EasyProjects\SimpleRouter\Router;

class ProfileController
{
    private ProfileModel $profileModel;

    public function __construct()
    {
        $this->profileModel = new ProfileModel();
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
            Router::$response->status(404)->send([
                "message" => "Profile not found"
            ]);
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
        $targetDir = realpath(__DIR__ . '/../../uploads/avatars') . DIRECTORY_SEPARATOR;

        // ✅ Crear directorio si no existe
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $filename = uniqid() . "_" . basename($file['name']);
        $targetFile = $targetDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $targetFile)) {
            $avatarPath = "/uploads/avatars/" . $filename;
            
            // ✅ CORREGIDO: Llamar SOLO UNA VEZ al método
            if ($this->profileModel->updateAvatar($userId, $filename)) {
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
}
