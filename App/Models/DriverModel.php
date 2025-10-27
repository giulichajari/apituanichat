<?php

namespace App\Models;

use App\Configs\Database;
use PDO;

class DriverModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }
    public function updateAvailabilityByUserId(int $userId, int $isAvailable): bool
    {
        $stmt = $this->db->prepare("UPDATE drivers SET is_available = ? WHERE user_id = ?");
        $ok = $stmt->execute([$isAvailable, $userId]);

        if (!$ok) {
            error_log("Error SQL: " . implode(" | ", $stmt->errorInfo()) . "\n", 3, __DIR__ . '/../../php-error.log');
        }

        return $ok && $stmt->rowCount() > 0;
    }

    public function setDriverAvailable(int $driverUserId, bool $isAvailable): bool
    {
        try {
            $stmt = $this->db->prepare("
            UPDATE drivers SET is_available = :isAvailable WHERE user_id = :driverUserId
        ");
            return $stmt->execute([
                ':isAvailable' => $isAvailable ? 1 : 0,
                ':driverUserId' => $driverUserId
            ]);
        } catch (\PDOException $e) {
            error_log("❌ Error al actualizar disponibilidad: " . $e->getMessage());
            return false;
        }
    }

    public function createOrGetChat(int $userId, int $driverId, array $rideData): ?int
    {
        try {
            // 1️⃣ Verificar si ya existe chat entre ambos
            $stmt = $this->db->prepare("
            SELECT c.id 
            FROM chats c
            JOIN chat_usuarios cu1 ON c.id = cu1.chat_id AND cu1.user_id = :user_id
            JOIN chat_usuarios cu2 ON c.id = cu2.chat_id AND cu2.user_id = :driver_id
            LIMIT 1
        ");
            $stmt->execute([':user_id' => $userId, ':driver_id' => $driverId]);
            $chat = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($chat) {
                $chatId = $chat['id'];
            } else {
                // 2️⃣ Crear nuevo chat
                $stmt = $this->db->prepare("INSERT INTO chats (created_at) VALUES (NOW())");
                $stmt->execute();
                $chatId = $this->db->lastInsertId();

                // Asociar usuarios al chat
                $stmt = $this->db->prepare("
                INSERT INTO chat_usuarios (chat_id, user_id, added_at) 
                VALUES (:chat_id, :user_id, NOW())
            ");
                $stmt->execute([':chat_id' => $chatId, ':user_id' => $userId]);
                $stmt->execute([':chat_id' => $chatId, ':user_id' => $driverId]);
            }

            // 3️⃣ Construir mensaje con direcciones legibles
            $pickupAddress = $rideData['pickupAddress'] ?? 'Ubicación desconocida';
            $destAddress = $rideData['destinationAddress'] ?? 'Ubicación desconocida';
            $fare = number_format($rideData['estimated_fare'], 2);

            $msg = "🚗 Nueva solicitud de viaje.\n📍 Retiro en: {$pickupAddress}\n🏁 Destino: {$destAddress}\n💰 Tarifa estimada: \${$fare}";

            // 4️⃣ Guardar mensaje en la tabla mensajes
            $stmt = $this->db->prepare("
            INSERT INTO mensajes (chat_id, user_id, contenido, tipo, enviado_en)
            VALUES (:chat_id, :user_id, :contenido, 'texto', NOW())
        ");
            $stmt->execute([
                ':chat_id' => $chatId,
                ':user_id' => $userId,
                ':contenido' => $msg
            ]);

            return $chatId;
        } catch (\PDOException $e) {
            error_log("❌ Error al crear chat o mensaje: " . $e->getMessage());
            return null;
        }
    }

    public function createRideRequest(array $data): ?int
    {
        try {
            // Iniciar transacción
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("
    INSERT INTO ride_requests 
    (user_id, driver_id, pickup_lat, pickup_lng, dest_lat, dest_lng, pickup_address, dest_address, estimated_fare, status, created_at)
    VALUES (:user_id, :driver_id, :pickup_lat, :pickup_lng, :dest_lat, :dest_lng, :pickup_address, :dest_address, :estimated_fare, 'pending', NOW())
");

            $stmt->execute([
                ':user_id' => $data['user_id'],
                ':driver_id' => $data['driver_id'],
                ':pickup_lat' => $data['pickup_lat'],
                ':pickup_lng' => $data['pickup_lng'],
                ':dest_lat' => $data['dest_lat'],
                ':dest_lng' => $data['dest_lng'],
                ':pickup_address' => $data['pickup_address'],       // NUEVO
                ':dest_address' => $data['dest_address'],           // NUEVO
                ':estimated_fare' => $data['estimated_fare']
            ]);



            $requestId = $this->db->lastInsertId();

            // 2️⃣ Marcar al chofer como no disponible
            $updateStmt = $this->db->prepare("
            UPDATE drivers 
            SET is_available = 0 
            WHERE user_id = :driver_id
        ");
            $updateStmt->execute([':driver_id' => $data['driver_id']]);

            // 3️⃣ Verificar si ya existe un chat entre ambos
            $chatStmt = $this->db->prepare("
            SELECT c.id 
            FROM chats c
            JOIN chat_usuarios cu1 ON cu1.chat_id = c.id AND cu1.user_id = :user_id
            JOIN chat_usuarios cu2 ON cu2.chat_id = c.id AND cu2.user_id = :driver_id
            LIMIT 1
        ");
            $chatStmt->execute([
                ':user_id' => $data['user_id'],
                ':driver_id' => $data['driver_id']
            ]);

            $chat = $chatStmt->fetch(\PDO::FETCH_ASSOC);
            $chatId = null;

            if ($chat) {
                $chatId = $chat['id'];
            } else {
                // 4️⃣ Crear chat nuevo
                $chatInsert = $this->db->prepare("INSERT INTO chats (name, created_at) VALUES (:name, NOW())");
                $chatInsert->execute([':name' => 'Viaje #' . $requestId]);
                $chatId = $this->db->lastInsertId();

                // Insertar los dos usuarios en la tabla chat_usuarios
                $addUserStmt = $this->db->prepare("INSERT INTO chat_usuarios (chat_id, user_id) VALUES (:chat_id, :user_id)");
                $addUserStmt->execute([':chat_id' => $chatId, ':user_id' => $data['user_id']]);
                $addUserStmt->execute([':chat_id' => $chatId, ':user_id' => $data['driver_id']]);
            }

            // Mensaje con direcciones
            $fare = number_format($data['estimated_fare'], 2);
            $msgData = [
                "ride_id" => $requestId,
                "pickup" => $data['pickup_address'],
                "destination" => $data['dest_address'],
                "fare" => $fare
            ];

            $msg = json_encode($msgData);

            $msgStmt = $this->db->prepare("
            INSERT INTO mensajes (chat_id, user_id, contenido, tipo, enviado_en)
            VALUES (:chat_id, :user_id, :contenido, 'ride_request', NOW())
        ");
            $msgStmt->execute([
                ':chat_id' => $chatId,
                ':user_id' => $data['user_id'],
                ':contenido' => $msg
            ]);

            // 6️⃣ Confirmar todo
            $this->db->commit();

            return $requestId;
        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log("❌ Error al crear ride request: " . $e->getMessage());
            return null;
        }
    }


    /**
     * Obtener choferes disponibles en una ciudad
     *
     * @param string $city Ciudad a filtrar (puede coincidir con el campo 'location')
     * @return array Array de choferes disponibles con sus datos y tarifa
     */
    public function getAvailableDriversByCity(string $city): array
    {
        $stmt = $this->db->prepare("
            SELECT user_id, name, car_model, license_plate, location, pais, preciokm
            FROM drivers
            WHERE is_available = 1 AND location LIKE ?
            ORDER BY name
        ");
        $stmt->execute(["%$city%"]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    // Obtener chofer por ID
    public function getDriver(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM drivers WHERE user_id = ?");
        $stmt->execute([$id]);
        $driver = $stmt->fetch(PDO::FETCH_ASSOC);
        return $driver ?: null;
    }
    public function updateByUserId(int $userId, array $data): bool
    {
        $sql = "UPDATE drivers 
            SET name = ?, phone = ?, email = ?, car_model = ?, license_plate = ?, bio = ?, location = ?,pais=?,preciokm=?
            WHERE user_id = ?";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $data['name'] ?? '',
            $data['phone'] ?? '',
            $data['email'] ?? '',
            $data['car_model'] ?? '',
            $data['license_plate'] ?? '',
            $data['bio'] ?? '',
            $data['location'] ?? '',
            $data['pais'] ?? '',
            $data['preciokm'] ?? '',
            $userId
        ]);
    }

    // Listar todos los choferes
    public function getAllDrivers(): array
    {
        $stmt = $this->db->query("SELECT * FROM drivers ORDER BY name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Crear perfil vacío para un userId
    public function createEmptyProfile(int $userId): bool
    {
        $sql = "INSERT INTO drivers (user_id, name, phone, email, car_model, license_plate, bio, profile_image, is_available, location,pais,preciokm)
                VALUES (:user_id, '', '', '', '', '', '', '', 0, '','',0)";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    // Crear chofer con datos
    public function createDriver(array $data): bool
    {
        $sql = "INSERT INTO drivers (name, phone, email, car_model, license_plate, bio, profile_image, is_available, location,pais,preciokm)
                VALUES (:name, :phone, :email, :car_model, :license_plate, :bio, :profile_image, :is_available, :location,:pais,:preciokm)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($data);
    }

    // Actualizar chofer completo
    public function updateDriver(int $id, array $data): bool
    {
        $sql = "UPDATE drivers SET 
                    name = :name,
                    phone = :phone,
                    email = :email,
                    car_model = :car_model,
                    license_plate = :license_plate,
                    bio = :bio,
                    profile_image = :profile_image,
                    is_available = :is_available,
                    location = :location,
                    pais=:pais,
                    preciokm=:preciokm
                WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $data['id'] = $id;
        return $stmt->execute($data);
    }

    public function updateProfileImage(int $id, string $path): bool
    {
        $stmt = $this->db->prepare("UPDATE drivers SET profile_image = ? WHERE user_id = ?");
        $ok = $stmt->execute([$path, $id]);
        error_log("ID usado para update: $path\n", 3, __DIR__ . '/../../php-error.log');


        if (!$ok) {

            error_log("Error SQL: " . implode(" | ", $stmt->errorInfo()) . "\n", 3, __DIR__ . '/../../php-error.log');
        } else {
            error_log("Filas afectadas: " . $stmt->rowCount());
        }

        return $ok;
    }
}
