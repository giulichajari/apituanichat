<?php

namespace App\Models;

use App\Configs\Database;
use PDO;

class RestaurantModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Obtener todos los restaurantes
     */
    public function getAllRestaurants(): array
    {
        $stmt = $this->db->query("
            SELECT * FROM restaurantes 
            WHERE activo = 1 
            ORDER BY nombre
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtener restaurante por ID
     */
    public function getRestaurantById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM restaurantes 
            WHERE id = ? AND activo = 1
        ");
        $stmt->execute([$id]);
        $restaurant = $stmt->fetch(PDO::FETCH_ASSOC);
        return $restaurant ?: null;
    }

    /**
     * Crear nuevo restaurante
     */
    public function createRestaurant(array $data): ?int
    {
        try {
            $sql = "INSERT INTO restaurantes 
                (nombre, ubicacion, tipo_comida, descripcion, telefono, email, 
                 horario_apertura, horario_cierre, precio_promedio, capacidad,
                 mascotas_permitidas, estacionamiento, wifi_gratis, user_id)
                VALUES 
                (:nombre, :ubicacion, :tipo_comida, :descripcion, :telefono, :email,
                 :horario_apertura, :horario_cierre, :precio_promedio, :capacidad,
                 :mascotas_permitidas, :estacionamiento, :wifi_gratis, :user_id)";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                ':nombre' => $data['nombre'],
                ':ubicacion' => $data['ubicacion'],
                ':tipo_comida' => $data['tipo_comida'],
                ':descripcion' => $data['descripcion'] ?? null,
                ':telefono' => $data['telefono'] ?? null,
                ':email' => $data['email'] ?? null,
                ':horario_apertura' => $data['horario_apertura'] ?? null,
                ':horario_cierre' => $data['horario_cierre'] ?? null,
                ':precio_promedio' => $data['precio_promedio'] ?? null,
                ':capacidad' => $data['capacidad'] ?? null,
                ':mascotas_permitidas' => $data['mascotas_permitidas'] ? 1 : 0,
                ':estacionamiento' => $data['estacionamiento'] ? 1 : 0,
                ':wifi_gratis' => $data['wifi_gratis'] ? 1 : 0,
                ':user_id' => $data['user_id']
            ]);

            return $success ? $this->db->lastInsertId() : null;
        } catch (\PDOException $e) {
            error_log("❌ Error al crear restaurante: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Actualizar restaurante
     */
    public function updateRestaurant(int $id, array $data): bool
    {
        try {
            // Construir dinámicamente la consulta UPDATE
            $fields = [];
            $params = [':id' => $id];

            $allowedFields = [
                'nombre', 'ubicacion', 'tipo_comida', 'descripcion', 'telefono', 'email',
                'horario_apertura', 'horario_cierre', 'precio_promedio', 'capacidad',
                'mascotas_permitidas', 'estacionamiento', 'wifi_gratis', 'foto_portada'
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = :$field";
                    $params[":$field"] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $sql = "UPDATE restaurantes SET " . implode(', ', $fields) . " WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (\PDOException $e) {
            error_log("❌ Error al actualizar restaurante: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Eliminar restaurante (borrado lógico)
     */
    public function deleteRestaurant(int $id): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE restaurantes 
                SET activo = 0 
                WHERE id = ?
            ");
            return $stmt->execute([$id]);
        } catch (\PDOException $e) {
            error_log("❌ Error al eliminar restaurante: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtener restaurantes por tipo de comida
     */
    public function getRestaurantsByCategory(string $tipoComida): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM restaurantes 
            WHERE tipo_comida LIKE ? AND activo = 1 
            ORDER BY nombre
        ");
        $stmt->execute(["%$tipoComida%"]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Buscar restaurantes por nombre o ubicación
     */
    public function searchRestaurants(string $query): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM restaurantes 
            WHERE (nombre LIKE ? OR ubicacion LIKE ? OR tipo_comida LIKE ?) 
            AND activo = 1 
            ORDER BY nombre
        ");
        $searchTerm = "%$query%";
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtener restaurantes por ubicación (ciudad)
     */
    public function getRestaurantsByLocation(string $ciudad): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM restaurantes 
            WHERE ubicacion LIKE ? AND activo = 1 
            ORDER BY nombre
        ");
        $stmt->execute(["%$ciudad%"]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Actualizar imagen de portada
     */
    public function updateCoverImage(int $id, string $imagePath): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE restaurantes 
                SET foto_portada = ? 
                WHERE id = ?
            ");
            return $stmt->execute([$imagePath, $id]);
        } catch (\PDOException $e) {
            error_log("❌ Error al actualizar imagen de portada: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtener restaurantes favoritos del usuario
     */
    public function getUserFavorites(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT r.* 
            FROM restaurantes r
            INNER JOIN restaurantes_favoritos rf ON r.id = rf.restaurant_id
            WHERE rf.user_id = ? AND r.activo = 1
            ORDER BY rf.fecha_agregado DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Agregar/eliminar restaurante de favoritos
     */
    public function toggleFavorite(int $userId, int $restaurantId): array
    {
        try {
            // Verificar si ya es favorito
            $checkStmt = $this->db->prepare("
                SELECT id FROM restaurantes_favoritos 
                WHERE user_id = ? AND restaurant_id = ?
            ");
            $checkStmt->execute([$userId, $restaurantId]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // Eliminar de favoritos
                $deleteStmt = $this->db->prepare("
                    DELETE FROM restaurantes_favoritos 
                    WHERE user_id = ? AND restaurant_id = ?
                ");
                $deleteStmt->execute([$userId, $restaurantId]);
                
                return [
                    'added' => false,
                    'message' => 'Restaurante eliminado de favoritos'
                ];
            } else {
                // Agregar a favoritos
                $insertStmt = $this->db->prepare("
                    INSERT INTO restaurantes_favoritos (user_id, restaurant_id, fecha_agregado)
                    VALUES (?, ?, NOW())
                ");
                $insertStmt->execute([$userId, $restaurantId]);
                
                return [
                    'added' => true,
                    'message' => 'Restaurante agregado a favoritos'
                ];
            }
        } catch (\PDOException $e) {
            error_log("❌ Error al gestionar favoritos: " . $e->getMessage());
            return ['added' => false, 'message' => 'Error al gestionar favoritos'];
        }
    }

    /**
     * Obtener restaurantes por usuario propietario
     */
    public function getRestaurantsByOwner(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM restaurantes 
            WHERE user_id = ? AND activo = 1 
            ORDER BY nombre
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Verificar si el usuario es propietario del restaurante
     */
    public function isOwner(int $restaurantId, int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT id FROM restaurantes 
            WHERE id = ? AND user_id = ? AND activo = 1
        ");
        $stmt->execute([$restaurantId, $userId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Obtener restaurantes con mejores valoraciones
     */
    public function getTopRatedRestaurants(int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT r.*, 
                   COALESCE(AVG(v.valoracion), 0) as valoracion_promedio,
                   COUNT(v.id) as total_valoraciones
            FROM restaurantes r
            LEFT JOIN valoraciones_restaurantes v ON r.id = v.restaurant_id
            WHERE r.activo = 1
            GROUP BY r.id
            ORDER BY valoracion_promedio DESC, total_valoraciones DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }



    
}