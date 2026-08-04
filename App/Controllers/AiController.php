<?php

namespace App\Controllers;

use EasyProjects\SimpleRouter\Router;

/**
 * Proxy de OpenAI: la API key nunca viaja al navegador.
 */
class AiController
{
    public function chat()
    {
        $user = Router::$request->user ?? null;
        if (!$user) {
            Router::$response->status(401)->json(['message' => 'No autenticado']);
            return;
        }

        $body = is_object(Router::$request->body)
            ? (array) Router::$request->body
            : (json_decode(file_get_contents('php://input'), true) ?: []);

        $message = trim((string) ($body['message'] ?? ''));
        if ($message === '') {
            Router::$response->status(400)->json(['message' => 'message es obligatorio']);
            return;
        }

        if (mb_strlen($message) > 2000) {
            Router::$response->status(400)->json(['message' => 'Mensaje demasiado largo']);
            return;
        }

        $apiKey = trim((string) ($_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: ''));
        if ($apiKey === '') {
            Router::$response->status(503)->json([
                'message' => 'Asistente no configurado (OPENAI_API_KEY)',
            ]);
            return;
        }

        $model = (string) ($_ENV['OPENAI_MODEL'] ?? 'gpt-3.5-turbo');
        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are Tuani Assistant, a helpful and friendly chatbot integrated into the Tuani messaging app. Keep responses concise and helpful.',
                ],
                [
                    'role' => 'user',
                    'content' => $message,
                ],
            ],
            'max_tokens' => 150,
            'temperature' => 0.7,
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            error_log('AiController curl: ' . $curlErr);
            Router::$response->status(502)->json(['message' => 'Error al contactar el asistente']);
            return;
        }

        $data = json_decode($raw, true);
        if ($http < 200 || $http >= 300) {
            error_log('AiController OpenAI HTTP ' . $http . ': ' . $raw);
            Router::$response->status(502)->json(['message' => 'El asistente no pudo responder']);
            return;
        }

        $reply = $data['choices'][0]['message']['content'] ?? null;
        if (!$reply) {
            Router::$response->status(502)->json(['message' => 'Respuesta vacía del asistente']);
            return;
        }

        Router::$response->status(200)->json([
            'message' => $reply,
            'reply' => $reply,
        ]);
    }
}
