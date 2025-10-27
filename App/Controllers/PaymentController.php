<?php

namespace App\Controllers;

use App\Models\PaymentModel;
use App\Models\DriverModel;
use EasyProjects\SimpleRouter\Router;
use Dotenv\Dotenv;

class PaymentController
{
    private PaymentModel $paymentModel;
    private DriverModel $driverModel;

    public function __construct()
    {
        $this->paymentModel = new PaymentModel();
        $this->driverModel = new DriverModel();
    }

    public function createPaymentLink()
    {
        $body = json_decode(file_get_contents('php://input'), true);

        $userId = $body['userId'] ?? null;
        $driverId = $body['driverId'] ?? null;
        $pickup = $body['pickup'] ?? null;
        $destination = $body['destination'] ?? null;
        $pickupAddress = $body['pickupAddress'] ?? '';
        $destinationAddress = $body['destinationAddress'] ?? '';
        $estimatedFare = $body['estimatedFare'] ?? null;
        $currency = $body['currency'] ?? 'USD';

        if (!$userId || !$driverId || !$pickup || !$destination || !$estimatedFare) {
            Router::$response->status(400)->json(["message" => "Campos obligatorios faltantes"]);
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
            'estimated_fare' => $estimatedFare
        ]);

        if (!$rideRequestId) {
            Router::$response->status(500)->json(["message" => "Error al crear la solicitud de viaje"]);
            return;
        }

        // 2️⃣ Crear link de pago en Square
        $accessToken = 'EAAAlxTY0_RvCL8Uef5jmXv3T2XKh_5aS76wre26WvHGUFKJJ0zeMmrsCh1z3fTl';
        $locationId  = 'LVJKV9EVQMVRR';
        $amountCents = (int) round($estimatedFare * 100); // Square espera enteros en centavos
        $idempotencyKey = uniqid('pay_', true);

        $postData = [
            "idempotency_key" => $idempotencyKey,
            "quick_pay" => [
                "name" => "Pago de viaje #$rideRequestId",
                "price_money" => [
                    "amount" => $amountCents,
                    "currency" => $currency
                ],
                "location_id" => $locationId
            ]
        ];

        $ch = curl_init("https://connect.squareupsandbox.com/v2/online-checkout/payment-links");
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
            Router::$response->status($httpcode)->json([
                "message" => "Error creando link de pago",
                "error" => $result
            ]);
            return;
        }

        $paymentLinkUrl = $result['payment_link']['url'] ?? null;

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
            "paymentUrl" => $paymentLinkUrl
        ]);
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
        $payments = $this->paymentModel->findByUserId((int)$userId);

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
        $payments = $this->paymentModel->findByDriverId((int)$driverId);

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

        Router::$response->json($payment);
    }
}
