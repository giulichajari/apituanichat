<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Exception;

/**
 * Envía notificaciones push FCM (solo data) para que la app Android las reciba en segundo plano.
 * Requiere: FIREBASE_CREDENTIALS = ruta al JSON de cuenta de servicio de Firebase.
 */
class FcmService
{
    private static ?array $credentials = null;
    private static ?string $accessToken = null;
    private static ?int $tokenExpiry = 0;

    private static function loadCredentials(): bool
    {
        if (self::$credentials !== null) {
            return true;
        }
        $path = getenv('FIREBASE_CREDENTIALS') ?: (__DIR__ . '/../../firebase-credentials.json');
        if (!is_file($path) || !is_readable($path)) {
            error_log('FcmService: FIREBASE_CREDENTIALS no encontrado o no legible: ' . $path);
            return false;
        }
        $json = file_get_contents($path);
        self::$credentials = json_decode($json, true);
        if (!self::$credentials || empty(self::$credentials['private_key']) || empty(self::$credentials['client_email']) || empty(self::$credentials['project_id'])) {
            error_log('FcmService: JSON de credenciales inválido');
            self::$credentials = null;
            return false;
        }
        return true;
    }

    private static function getAccessToken(): ?string
    {
        if (!self::loadCredentials()) {
            return null;
        }
        $now = time();
        if (self::$accessToken && self::$tokenExpiry > $now + 60) {
            return self::$accessToken;
        }
        try {
            $payload = [
                'iss' => self::$credentials['client_email'],
                'sub' => self::$credentials['client_email'],
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            ];
            $jwt = JWT::encode($payload, self::$credentials['private_key'], 'RS256');

            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => 'grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer&assertion=' . $jwt,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $response = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200 || !$response) {
                error_log('FcmService: OAuth2 error ' . $code . ' ' . $response);
                return null;
            }
            $data = json_decode($response, true);
            self::$accessToken = $data['access_token'] ?? null;
            self::$tokenExpiry = $now + (int)($data['expires_in'] ?? 3600);
            return self::$accessToken;
        } catch (Exception $e) {
            error_log('FcmService: getAccessToken ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Envía un mensaje de solo datos al token FCM dado.
     * @param string $fcmToken Token del dispositivo
     * @param array $data Pares clave-valor (strings). Ej: ['type' => 'new_message', 'chat_id' => '1', 'sender_name' => 'Juan', 'body' => 'Hola']
     * @return bool True si se envió correctamente
     */
    public static function sendDataMessage(string $fcmToken, array $data): bool
    {
        $token = self::getAccessToken();
        if (!$token || !self::loadCredentials()) {
            return false;
        }
        $projectId = self::$credentials['project_id'];
        $url = 'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send';
        $payload = [
            'message' => [
                'token' => $fcmToken,
                'data' => array_map('strval', $data),
            ],
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            error_log('FcmService: FCM send error ' . $code . ' ' . $response);
            return false;
        }
        return true;
    }
}
