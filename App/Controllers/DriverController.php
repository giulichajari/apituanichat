<?php

namespace App\Controllers;

use App\Models\DriverModel;
use App\Services\FareCalculator;
use EasyProjects\SimpleRouter\Router;

class DriverController
{
    private DriverModel $driverModel;
    private FareCalculator $fareCalculator;

    public function __construct()
    {
        $this->driverModel = new DriverModel();
        $this->fareCalculator = new FareCalculator();
    }

    public function getDriver()
    {
        $userId = Router::$request->user->id ?? null;

        if (!$userId) {
            Router::$response->status(400)->send([
                "message" => "ID de usuario no válido"
            ]);
            return;
        }

        $user = Router::$request->user;
        $seed = [
            'name' => $user->name ?? '',
            'phone' => $user->phone ?? '',
            'email' => $user->email ?? '',
        ];

        $driver = $this->driverModel->ensureDriverProfile((int) $userId, $seed);

        if ($driver) {
            Router::$response->status(200)->send([
                "data" => $driver,
                "message" => "Perfil del chofer obtenido correctamente"
            ]);
        } else {
            Router::$response->status(500)->send([
                "message" => "No se pudo crear el perfil del conductor"
            ]);
        }
    }


    // Crear chofer
    public function createDriver()
    {
        $data = [
            'name' => Router::$request->body->name ?? '',
            'phone' => Router::$request->body->phone ?? '',
            'email' => Router::$request->body->email ?? '',
            'car_model' => Router::$request->body->car_model ?? '',
            'license_plate' => Router::$request->body->license_plate ?? '',
            'bio' => Router::$request->body->bio ?? '',
            'profile_image' => Router::$request->body->profile_image ?? '',
            'is_available' => Router::$request->body->is_available ?? 0,
            'location' => Router::$request->body->location ?? '',
            'pais' => Router::$request->body->pais ?? '',
            'preciokm' => Router::$request->body->preciokm ?? ''
        ];

        if ($this->driverModel->createDriver($data)) {
            Router::$response->status(201)->json([
                "message" => "Driver created successfully"
            ]);
        } else {
            Router::$response->status(500)->json([
                "message" => "Error creating driver"
            ]);
        }
    }
    public function getAvailableDriversByCity()
    {
        // Obtener la ciudad desde query params
        $city = Router::$request->query->city ?? null;

        if (!$city) {
            Router::$response->status(400)->json([
                "message" => "Falta el parámetro city"
            ]);
            return;
        }

        // Llamar al modelo
        $drivers = $this->driverModel->getAvailableDriversByCity($city);

        Router::$response->status(200)->json([
            "data" => $drivers,
            "message" => count($drivers) . " choferes disponibles encontrados en $city"
        ]);
    }
    public function requestDriver()
    {
        $input = json_decode(file_get_contents('php://input'), true);

        // userId del token — no confiar en el body
        $userId = Router::$request->user->id ?? null;
        $driverId = $input['driverId'] ?? null;
        $pickup = $input['pickup'] ?? null;
        $destination = $input['destination'] ?? null;
        $pickupAddress = $input['pickupAddress'] ?? '';
        $destinationAddress = $input['destinationAddress'] ?? '';
        $serviceType = $input['serviceType'] ?? 'passenger';
        $packageType = $input['packageType'] ?? null;

        if (!$userId || !$driverId || !$pickup || !$destination) {
            Router::$response->status(400)->json([
                "message" => "Faltan datos requeridos"
            ]);
            return;
        }

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
            Router::$response->status(400)->json([
                "message" => "No se pudo calcular la tarifa",
                "error" => $e->getMessage()
            ]);
            return;
        }

        // Crear la solicitud de viaje con coordenadas y direcciones
        $requestId = $this->driverModel->createRideRequest([
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
            'package_type' => $packageType,
        ]);

        if (!$requestId) {
            Router::$response->status(500)->json([
                "message" => "Error al crear la solicitud"
            ]);
            return;
        }

        // Crear o reutilizar chat y enviar mensaje legible
        try {
            $chatId = $this->driverModel->createOrGetChat($userId, $driverId, [
                'pickupAddress' => $pickupAddress,
                'destinationAddress' => $destinationAddress,
                'estimated_fare' => $estimatedFare
            ]);
        } catch (\PDOException $e) {
            error_log("❌ Error al guardar chat/mensaje: " . $e->getMessage());
            $chatId = null;
        }

        // Notificación WebSocket
        try {
            $message = [
                'type' => 'ride_request',
                'session_id' => 'driver_' . $driverId,
                'ride_id' => $requestId,
                'chat_id' => $chatId,
                'message' => 'Tienes una nueva solicitud de viaje',
                'data' => [
                    'pickup' => ['coords' => $pickup, 'address' => $pickupAddress],
                    'destination' => ['coords' => $destination, 'address' => $destinationAddress],
                    'estimated_fare' => $estimatedFare,
                    'user_id' => $userId
                ]
            ];

            $socket = fsockopen('localhost', 8080, $errno, $errstr, 2);
            if ($socket) {
                fwrite($socket, json_encode($message));
                fclose($socket);
            } else {
                error_log("⚠️ No se pudo conectar al WebSocket: $errstr ($errno)");
            }
        } catch (\Exception $e) {
            error_log("❌ Error enviando notificación WS: " . $e->getMessage());
        }

        Router::$response->status(201)->json([
            "message" => "Solicitud enviada al chofer",
            "requestId" => $requestId,
            "chatId" => $chatId,
            "estimatedFare" => $estimatedFare,
            "fareDetails" => $fareResult,
        ]);
    }

    public function updateAvailability()
    {
        // Obtener ID del usuario autenticado desde el token
        $userId = Router::$request->user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->json([
                "message" => "Usuario no autenticado"
            ]);
            return;
        }

        // Leer el body (PATCH con JSON)
        $rawInput = file_get_contents("php://input");
        $data = json_decode($rawInput, true);

        if (!isset($data['isAvailable'])) {
            Router::$response->status(400)->json([
                "message" => "Falta el campo isAvailable"
            ]);
            return;
        }



        $ok = $this->driverModel->updateAvailabilityByUserId($userId, (int) $data['isAvailable']);

        if (!$ok) {
            $this->driverModel->ensureDriverProfile((int) $userId);
            $ok = $this->driverModel->updateAvailabilityByUserId($userId, (int) $data['isAvailable']);
        }

        if ($ok) {
            Router::$response->json([
                "message" => "Disponibilidad actualizada correctamente ✅",
                "data" => [
                    "isAvailable" => (int) $data['isAvailable']
                ]
            ]);
        } else {
            Router::$response->status(500)->json([
                "message" => "Error al actualizar disponibilidad"
            ]);
        }
    }

    // Actualizar chofer
    public function updateDriver()
    {
        $userId = Router::$request->user->id ?? null;

        $rawInput = file_get_contents("php://input");
        $data = json_decode($rawInput, true);


        // Validar datos mínimamente
        if (!isset($data['name']) || !isset($data['phone'])) {
            Router::$response->status(400)->json([
                "message" => "Faltan campos obligatorios"
            ]);
            return;
        }

        $driverModel = new DriverModel();

        $user = Router::$request->user;
        $driverModel->ensureDriverProfile((int) $userId, [
            'name' => $user->name ?? '',
            'phone' => $user->phone ?? '',
            'email' => $user->email ?? '',
        ]);

        $ok = $driverModel->updateByUserId($userId, $data);

        if ($ok) {
            Router::$response->json([
                "message" => "Perfil actualizado correctamente ✅",
                "data" => $driverModel->getDriver($userId)
            ]);
        } else {
            Router::$response->status(500)->json([
                "message" => "Error al actualizar perfil"
            ]);
        }
    }


    public function updateProfileImage()
    {
        // Obtener el user_id del token JWT en lugar de params
        $userId = Router::$request->user->id;

        if (!$userId) {
            Router::$response->status(401)->json([
                "message" => "Usuario no autenticado"
            ]);
            return;
        }

        if (!isset($_FILES['profile_image'])) {
            Router::$response->status(400)->json([
                "message" => "No profile image uploaded"
            ]);
            return;
        }

        $file = $_FILES['profile_image'];
        $targetDir = realpath(__DIR__ . '/../../uploads/avatars/drivers') . DIRECTORY_SEPARATOR;

        // Crear directorio si no existe
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        // Generar nombre único para el archivo
        $filename = uniqid() . "_" . basename($file['name']);
        $targetFile = $targetDir . $filename;

        // Validar tipo de archivo
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $fileType = mime_content_type($file['tmp_name']);

        if (!in_array($fileType, $allowedTypes)) {
            Router::$response->status(400)->json([
                "message" => "Tipo de archivo no permitido. Solo se permiten JPEG, PNG, GIF y WebP"
            ]);
            return;
        }

        // Validar tamaño del archivo (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            Router::$response->status(400)->json([
                "message" => "El archivo es demasiado grande. Máximo 5MB permitidos"
            ]);
            return;
        }

        if (move_uploaded_file($file['tmp_name'], $targetFile)) {
            $imagePath = $filename; // 👈 CAMBIO IMPORTANTE: Solo el nombre del archivo

            if ($this->driverModel->updateProfileImage($userId, $imagePath)) {
                Router::$response->status(200)->json([
                    "message" => "Profile image updated successfully",
                    "profile_image" => $imagePath // 👈 Solo el nombre del archivo
                ]);
            } else {
                // Si falla la BD, eliminar el archivo subido
                unlink($targetFile);
                Router::$response->status(500)->json([
                    "message" => "Error saving image path to database"
                ]);
            }
        } else {
            Router::$response->status(500)->json([
                "message" => "Error uploading image"
            ]);
        }
    }

    // Listar todos los choferes
    public function listDrivers()
    {
        $drivers = $this->driverModel->getAllDrivers();

        Router::$response->status(200)->json([
            "data" => $drivers
        ]);
    }
}
