<?php

namespace App\Controllers;

use App\Models\PaymentModel;
use App\Models\DriverModel;
use App\Services\FareCalculator;
use EasyProjects\SimpleRouter\Router;

class PaymentController
{
    private PaymentModel $paymentModel;
    private DriverModel $driverModel;
    private FareCalculator $fareCalculator;

    public function __construct()
    {
        $this->paymentModel = new PaymentModel();
        $this->driverModel = new DriverModel();
        $this->fareCalculator = new FareCalculator();
    }

    public function createPaymentLink()
    {
        $body = json_decode(file_get_contents('php://input'), true);

        // userId SIEMPRE del token verificado — nunca del body
        $userId = Router::$request->user->id ?? null;
        $driverId = $body['driverId'] ?? null;
        $pickup = $body['pickup'] ?? null;
        $destination = $body['destination'] ?? null;
        $pickupAddress = $body['pickupAddress'] ?? '';
        $destinationAddress = $body['destinationAddress'] ?? '';
        $currency = $body['currency'] ?? 'USD';
        $serviceType = $body['serviceType'] ?? 'passenger';
        $packageWeightKg = $body['packageWeightKg'] ?? null;
        $packageLengthCm = $body['packageLengthCm'] ?? null;
        $packageWidthCm = $body['packageWidthCm'] ?? null;
        $packageHeightCm = $body['packageHeightCm'] ?? null;
        $packageType = $body['packageType'] ?? null;

        if (!$userId || !$driverId || !$pickup || !$destination) {
            Router::$response->status(400)->json(["message" => "Campos obligatorios faltantes"]);
            return;
        }

        if ($serviceType === 'package') {
            if (!$packageWeightKg || !$packageLengthCm || !$packageWidthCm || !$packageHeightCm) {
                Router::$response->status(400)->json(["message" => "Paquete: peso y dimensiones son obligatorios"]);
                return;
            }
            if ((float) $packageWeightKg > 10) {
                Router::$response->status(400)->json([
                    "message" => "El paquete supera 10 kg. Contactá soporte para envíos especiales."
                ]);
                return;
            }
        }

        // Recalcular tarifa en servidor (ignorar estimatedFare del cliente)
        try {
            $fareResult = $this->fareCalculator->calculate(
                (int) $driverId,
                is_array($pickup) ? $pickup : [],
                is_array($destination) ? $destination : [],
                $serviceType,
                $packageType
            );
            $estimatedFare = $fareResult['fare'];
        } catch (\Throwable $e) {
            error_log('PaymentController fare: ' . $e->getMessage());
            Router::$response->status(400)->json([
                "message" => "No se pudo calcular la tarifa",
                "error" => $e->getMessage()
            ]);
            return;
        }

        // 1️⃣ Crear ride request
        $rideRequestId = $this->driverModel->createRideRequest([
            'user_id' => $userId,
            'driver_id' => $driverId,
            'pickup_lat' => $pickup['lat'] ?? null,
            'pickup_lng' => $pickup['lng'] ?? null,
            'dest_lat' => $destination['lat'] ?? null,
            'dest_lng' => $destination['lng'] ?? null,
            'pickup_address' => $pickupAddress,
            'dest_address' => $destinationAddress,
            'estimated_fare' => $estimatedFare,
            'service_type' => $serviceType,
            'package_weight_kg' => $packageWeightKg,
            'package_length_cm' => $packageLengthCm,
            'package_width_cm' => $packageWidthCm,
            'package_height_cm' => $packageHeightCm,
            'package_type' => $packageType,
            'package_details' => $body['packageDetails'] ?? null,
        ]);

        if (!$rideRequestId) {
            Router::$response->status(500)->json(["message" => "Error al crear la solicitud de viaje"]);
            return;
        }

        // 2️⃣ Crear link de pago en Square (credenciales solo desde .env)
        $square = $this->createSquarePaymentLink((int) $rideRequestId, $estimatedFare, $currency);
        if (!empty($square['error'])) {
            Router::$response->status(500)->json([
                "message" => "Error creando link de pago",
                "error" => $square['error']
            ]);
            return;
        }

        $paymentLinkUrl = $square['url'];
        $idempotencyKey = $square['idempotency_key'];

        // 3️⃣ Guardar en payments vinculando ride_request_id
        $paymentId = $this->paymentModel->create([
            'ride_request_id' => $rideRequestId,
            'user_id' => $userId,
            'driver_id' => $driverId,
            'amount' => $estimatedFare,
            'currency' => $currency,
            'status' => 'pending',
            'payment_link_url' => $paymentLinkUrl,
            'idempotency_key' => $idempotencyKey
        ]);

        Router::$response->status(201)->json([
            "message" => "Solicitud y link de pago creados correctamente",
            "rideRequestId" => $rideRequestId,
            "payment_id" => $paymentId,
            "paymentUrl" => $paymentLinkUrl,
            "estimatedFare" => $estimatedFare,
            "fareDetails" => $fareResult,
        ]);
    }

    /**
     * @return array{url:?string,idempotency_key:?string,error:?string}
     */
    private function createSquarePaymentLink(int $rideRequestId, float $amount, string $currency): array
    {
        $appEnv = isset($_ENV['APP_ENV']) ? strtolower((string) $_ENV['APP_ENV']) : '';
        $isProd = ($appEnv === 'production');

        if ($isProd) {
            $accessToken = trim((string) ($_ENV['SQUARE_ACCESS_TOKEN_PROD'] ?? $_ENV['SQUARE_ACCESS_TOKEN'] ?? ''));
            $locationId = (string) ($_ENV['SQUARE_LOCATION_ID_PROD'] ?? $_ENV['SQUARE_LOCATION_ID'] ?? '');
        } else {
            $accessToken = trim((string) ($_ENV['SQUARE_ACCESS_TOKEN_SANDBOX'] ?? $_ENV['SQUARE_ACCESS_TOKEN'] ?? ''));
            $locationId = (string) ($_ENV['SQUARE_LOCATION_ID_SANDBOX'] ?? $_ENV['SQUARE_LOCATION_ID'] ?? '');
        }
        $locationId = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($locationId));

        $sandboxEnv = $_ENV['SQUARE_SANDBOX'] ?? '';
        $sandbox = ($sandboxEnv === 'true' || $sandboxEnv === '1') ? true : !$isProd;
        $squareBaseUrl = $sandbox ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';

        if (empty($accessToken) || empty($locationId)) {
            return [
                'url' => null,
                'idempotency_key' => null,
                'error' => empty($accessToken) ? 'SQUARE_ACCESS_TOKEN no configurado' : 'SQUARE_LOCATION_ID no configurado'
            ];
        }

        $amountCents = (int) round($amount * 100);
        if ($amountCents <= 0) {
            return ['url' => null, 'idempotency_key' => null, 'error' => 'Monto inválido'];
        }

        $idempotencyKey = uniqid('pay_', true);
        $postData = [
            "idempotency_key" => $idempotencyKey,
            "quick_pay" => [
                "name" => "Pago de viaje #$rideRequestId",
                "price_money" => [
                    "amount" => $amountCents,
                    "currency" => strtoupper($currency)
                ],
                "location_id" => $locationId
            ]
        ];

        $ch = curl_init($squareBaseUrl . "/v2/online-checkout/payment-links");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer $accessToken",
            "Square-Version: 2025-03-19"
        ]);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($httpcode !== 200 && $httpcode !== 201) {
            return [
                'url' => null,
                'idempotency_key' => $idempotencyKey,
                'error' => $result ?? "HTTP $httpcode"
            ];
        }

        return [
            'url' => $result['payment_link']['url'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'error' => null
        ];
    }

    public function updateStatus($idempotencyKey)
    {
        $body = json_decode(file_get_contents('php://input'), true);
        $status = $body['status'] ?? null;
        $squarePaymentId = $body['square_payment_id'] ?? null;

        if (!$status) {
            Router::$response->status(400)->json(["message" => "Falta el estado"]);
            return;
        }

        $updated = $this->paymentModel->updateStatus($idempotencyKey, $status, $squarePaymentId);

        if (!$updated) {
            Router::$response->status(404)->json(["message" => "No se encontró el pago"]);
            return;
        }

        Router::$response->json([
            "message" => "Estado actualizado correctamente",
            "idempotency_key" => $idempotencyKey,
            "status" => $status
        ]);
    }

    public function getPaymentsByUser($userId)
    {
        $authUser = Router::$request->user ?? null;
        $authId = (int) ($authUser->id ?? 0);
        $requestedId = (int) $userId;
        $isAdmin = $authUser && strtoupper($authUser->rol ?? '') === 'ADMIN';

        if (!$isAdmin && $authId !== $requestedId) {
            Router::$response->status(403)->json([
                "message" => "No autorizado a ver pagos de otro usuario"
            ]);
            return;
        }

        $payments = $this->paymentModel->findByUserId($requestedId);

        if (empty($payments)) {
            Router::$response->status(404)->json([
                "message" => "No se encontraron pagos para este usuario"
            ]);
            return;
        }

        Router::$response->json($payments);
    }

    public function getPaymentsByDriver($driverId)
    {
        $authUser = Router::$request->user ?? null;
        $authId = (int) ($authUser->id ?? 0);
        $requestedId = (int) $driverId;
        $isAdmin = $authUser && strtoupper($authUser->rol ?? '') === 'ADMIN';

        if (!$isAdmin && $authId !== $requestedId) {
            Router::$response->status(403)->json([
                "message" => "No autorizado a ver pagos de otro chofer"
            ]);
            return;
        }

        $payments = $this->paymentModel->findByDriverId($requestedId);

        if (empty($payments)) {
            Router::$response->status(404)->json([
                "message" => "No se encontraron pagos para este chofer"
            ]);
            return;
        }

        Router::$response->json($payments);
    }

    public function getByRide($rideId)
    {
        $payment = $this->paymentModel->findByRideId($rideId);

        if (!$payment) {
            Router::$response->status(404)->json(["message" => "Pago no encontrado"]);
            return;
        }

        $authUser = Router::$request->user ?? null;
        $authId = (int) ($authUser->id ?? 0);
        $isAdmin = $authUser && strtoupper($authUser->rol ?? '') === 'ADMIN';
        $ownerId = (int) ($payment['user_id'] ?? 0);
        $driverId = (int) ($payment['driver_id'] ?? 0);

        if (!$isAdmin && $authId !== $ownerId && $authId !== $driverId) {
            Router::$response->status(403)->json(["message" => "No autorizado"]);
            return;
        }

        Router::$response->json($payment);
    }
}
