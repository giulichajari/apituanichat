<?php

namespace App\Controllers;

use App\Models\DeviceTokenModel;
use EasyProjects\SimpleRouter\Router;

class DeviceTokenController
{
    private DeviceTokenModel $deviceTokenModel;

    public function __construct()
    {
        $this->deviceTokenModel = new DeviceTokenModel();
    }

    public function register()
    {
        $userId = Router::$request->user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->json([
                "message" => "Usuario no autenticado"
            ]);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $fcmToken = $input['fcm_token'] ?? null;
        $platform = $input['platform'] ?? null;

        if (!$fcmToken || !in_array($platform, ['android', 'ios', 'web'], true)) {
            Router::$response->status(400)->json([
                "message" => "Faltan datos requeridos: fcm_token y platform (android/ios/web)"
            ]);
            return;
        }

        $ok = $this->deviceTokenModel->upsertToken((int)$userId, $fcmToken, $platform);

        if ($ok) {
            Router::$response->status(200)->json([
                "message" => "Token de dispositivo registrado correctamente"
            ]);
        } else {
            Router::$response->status(500)->json([
                "message" => "Error al registrar el token"
            ]);
        }
    }

    public function unregister()
    {
        $userId = Router::$request->user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->json([
                "message" => "Usuario no autenticado"
            ]);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $fcmToken = $input['fcm_token'] ?? null;

        if (!$fcmToken) {
            Router::$response->status(400)->json([
                "message" => "Falta fcm_token"
            ]);
            return;
        }

        $ok = $this->deviceTokenModel->deactivateToken($fcmToken);

        Router::$response->status(200)->json([
            "message" => $ok ? "Token desactivado" : "No se pudo desactivar el token"
        ]);
    }
}
