<?php

namespace App\Controllers;

use App\Models\OrderModel;
use EasyProjects\SimpleRouter\Router;

/**
 * Webhook de Square para notificaciones de pago.
 * Configura la URL en: Developer Console > Webhooks > Notification URL
 * Ejemplo: https://tudominio.com/webhooks/square
 *
 * Eventos a suscribir: payment.updated
 * En producción: verificar firma con SQUARE_WEBHOOK_SIGNATURE_KEY
 */
class SquareWebhookController
{
    private OrderModel $orderModel;

    public function __construct()
    {
        $this->orderModel = new OrderModel();
    }

    public function handle(): void
    {
        $rawBody = file_get_contents('php://input');
        $payload = json_decode($rawBody, true);

        // Validar firma si está configurada
        $signatureKey = $_ENV['SQUARE_WEBHOOK_SIGNATURE_KEY'] ?? '';
        $notificationUrl = $_ENV['SQUARE_WEBHOOK_NOTIFICATION_URL'] ?? '';
        $signature = $_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE'] ?? '';

        if ($signatureKey && $notificationUrl && $signature) {
            if (!$this->verifySignature($rawBody, $signature, $signatureKey, $notificationUrl)) {
                Router::$response->status(403)->json(['error' => 'Invalid signature']);
                return;
            }
        }

        if (!$payload) {
            Router::$response->status(400)->json(['error' => 'Invalid JSON']);
            return;
        }

        $eventType = $payload['type'] ?? $payload['event_type'] ?? '';
        $data = $payload['data'] ?? $payload;

        // payment.updated o payment.completed
        if (strpos($eventType, 'payment') !== false) {
            $payment = $data['object']['payment'] ?? $data['payment'] ?? null;
            if ($payment && ($payment['status'] ?? '') === 'COMPLETED') {
                $this->processPaymentCompleted($payment);
            }
        }

        Router::$response->status(200)->json(['received' => true]);
    }

    private function processPaymentCompleted(array $payment): void
    {
        $paymentLinkId = $payment['payment_link_id'] ?? null;

        if (!$paymentLinkId) {
            // Intentar obtener desde la API de Square si no viene en el payload
            $paymentId = $payment['id'] ?? null;
            if ($paymentId) {
                $paymentLinkId = $this->fetchPaymentLinkId($paymentId);
            }
        }

        if (!$paymentLinkId) {
            error_log("Square webhook: payment_link_id no encontrado en pago " . ($payment['id'] ?? '?'));
            return;
        }

        $order = $this->orderModel->getBySquarePaymentLinkId($paymentLinkId);
        if (!$order) {
            error_log("Square webhook: pedido no encontrado para payment_link_id $paymentLinkId");
            return;
        }

        $ok = $this->orderModel->markAsPaid((int)$order['id']);
        if ($ok) {
            error_log("Square webhook: Pedido #{$order['id']} marcado como pagado.");
        }
    }

    private function fetchPaymentLinkId(string $paymentId): ?string
    {
        $accessToken = $_ENV['SQUARE_ACCESS_TOKEN'] ?? '';
        if (!$accessToken) return null;

        $ch = curl_init("https://connect.squareupsandbox.com/v2/payments/{$paymentId}");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $accessToken",
            "Square-Version: 2025-03-19"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) return null;

        $result = json_decode($response, true);
        return $result['payment']['payment_link_id'] ?? null;
    }

    private function verifySignature(string $body, string $signature, string $key, string $notificationUrl): bool
    {
        $payload = $notificationUrl . $body;
        $expected = base64_encode(hash_hmac('sha256', $payload, $key, true));
        return hash_equals($expected, $signature);
    }
}
