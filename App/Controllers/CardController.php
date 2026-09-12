<?php

namespace App\Controllers;

use App\Models\CardModel;
use EasyProjects\SimpleRouter\Router;

class CardController
{
    private CardModel $cardModel;

    public function __construct()
    {
        $this->cardModel = new CardModel();
    }

    private function squareConfig(): array
    {
        $appEnv = isset($_ENV['APP_ENV']) ? strtolower((string) $_ENV['APP_ENV']) : '';
        $isProd = ($appEnv === 'production');

        if ($isProd) {
            $accessToken = trim((string) ($_ENV['SQUARE_ACCESS_TOKEN_PROD'] ?? $_ENV['SQUARE_ACCESS_TOKEN'] ?? ''));
            $locationId = (string) ($_ENV['SQUARE_LOCATION_ID_PROD'] ?? $_ENV['SQUARE_LOCATION_ID'] ?? '');
            $baseUrl = 'https://connect.squareup.com';
        } else {
            $accessToken = trim((string) ($_ENV['SQUARE_ACCESS_TOKEN_SANDBOX'] ?? $_ENV['SQUARE_ACCESS_TOKEN'] ?? ''));
            $locationId = (string) ($_ENV['SQUARE_LOCATION_ID_SANDBOX'] ?? $_ENV['SQUARE_LOCATION_ID'] ?? '');
            $baseUrl = 'https://connect.squareupsandbox.com';
        }
        return [
            'accessToken' => $accessToken,
            'locationId' => preg_replace('/[^a-zA-Z0-9_-]/', '', trim($locationId)),
            'baseUrl' => $baseUrl,
        ];
    }

    private function squareRequest(string $method, string $path, array $body = []): array
    {
        $cfg = $this->squareConfig();
        $ch = curl_init($cfg['baseUrl'] . $path);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer {$cfg['accessToken']}",
            "Square-Version: 2025-03-19"
        ]);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result = json_decode($response, true) ?? [];
        return ['code' => $httpcode, 'body' => $result];
    }

    private function getOrCreateSquareCustomer(int $userId, ?string $name = null, ?string $email = null): ?string
    {
        $existing = $this->cardModel->getSquareCustomerId($userId);
        if ($existing) {
            return $existing;
        }

        $payload = [];
        if ($name) $payload['given_name'] = $name;
        if ($email) $payload['email_address'] = $email;
        $payload['reference_id'] = (string) $userId;

        $res = $this->squareRequest('POST', '/v2/customers', $payload);
        $customerId = $res['body']['customer']['id'] ?? null;

        if (!$customerId) {
            error_log('CardController: error creando customer Square - ' . json_encode($res['body']));
            return null;
        }

        $this->cardModel->saveSquareCustomerId($userId, $customerId);
        return $customerId;
    }

    // El frontend usa el Web Payments SDK para tokenizar la tarjeta (nunca pasa el número de tarjeta por nuestro servidor).
    // Aquí solo recibimos el token temporal ("source_id") y lo guardamos como tarjeta en archivo.
    public function addCard()
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true);
        $sourceId = $body['source_id'] ?? null;
        $setDefault = !empty($body['set_default']);
        $userName = $body['name'] ?? null;
        $userEmail = $body['email'] ?? null;

        if (!$sourceId) {
            Router::$response->status(400)->json(["message" => "Falta el token de la tarjeta (source_id)"]);
            return;
        }

        $customerId = $this->getOrCreateSquareCustomer((int) $userId, $userName, $userEmail);
        if (!$customerId) {
            Router::$response->status(500)->json(["message" => "No se pudo crear el cliente en Square"]);
            return;
        }

        $idempotencyKey = uniqid('card_', true);
        $res = $this->squareRequest('POST', '/v2/cards', [
            'idempotency_key' => $idempotencyKey,
            'source_id' => $sourceId,
            'card' => [
                'customer_id' => $customerId,
            ],
        ]);

        $card = $res['body']['card'] ?? null;
        if (!$card) {
            Router::$response->status(400)->json([
                "message" => "No se pudo guardar la tarjeta",
                "error" => $res['body']['errors'] ?? $res['body'],
            ]);
            return;
        }

        $cardId = $this->cardModel->saveCard([
            'user_id' => (int) $userId,
            'square_card_id' => $card['id'],
            'card_brand' => $card['card_brand'] ?? null,
            'last_4' => $card['last_4'] ?? null,
            'exp_month' => $card['exp_month'] ?? null,
            'exp_year' => $card['exp_year'] ?? null,
            'is_default' => $setDefault ? 1 : 0,
        ]);

        if ($setDefault && $cardId) {
            $this->cardModel->setDefault($cardId, (int) $userId);
        }

        Router::$response->status(201)->json([
            "message" => "Tarjeta agregada",
            "card" => [
                "id" => $cardId,
                "card_brand" => $card['card_brand'] ?? null,
                "last_4" => $card['last_4'] ?? null,
                "exp_month" => $card['exp_month'] ?? null,
                "exp_year" => $card['exp_year'] ?? null,
            ],
        ]);
    }

    public function listCards()
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $cards = $this->cardModel->getCardsByUser((int) $userId);
        Router::$response->status(200)->json($cards);
    }

    public function deleteCard($id)
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $card = $this->cardModel->getCardById((int) $id, (int) $userId);
        if (!$card) {
            Router::$response->status(404)->json(["message" => "Tarjeta no encontrada"]);
            return;
        }

        // Deshabilitar en Square (no se puede eliminar del todo, pero se desactiva)
        $this->squareRequest('POST', "/v2/cards/{$card['square_card_id']}/disable", []);
        $this->cardModel->disableCard((int) $id, (int) $userId);

        Router::$response->status(200)->json(["message" => "Tarjeta eliminada"]);
    }

    public function setDefaultCard($id)
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $ok = $this->cardModel->setDefault((int) $id, (int) $userId);
        if (!$ok) {
            Router::$response->status(400)->json(["message" => "No se pudo actualizar"]);
            return;
        }

        Router::$response->status(200)->json(["message" => "Tarjeta predeterminada actualizada"]);
    }

    // Devuelve el Application ID + Location ID que necesita el frontend para inicializar el Web Payments SDK.
    public function getSquareConfig()
    {
        $cfg = $this->squareConfig();
        $appEnv = isset($_ENV['APP_ENV']) ? strtolower((string) $_ENV['APP_ENV']) : '';
        $isProd = ($appEnv === 'production');
        $applicationId = $isProd
            ? ($_ENV['SQUARE_APPLICATION_ID_PROD'] ?? '')
            : ($_ENV['SQUARE_APPLICATION_ID_SANDBOX'] ?? '');

        Router::$response->status(200)->json([
            "application_id" => $applicationId,
            "location_id" => $cfg['locationId'],
            "environment" => $isProd ? "production" : "sandbox",
        ]);
    }
}
