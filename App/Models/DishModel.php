<?php

namespace App\Models;

use App\Configs\Database;
use PDO;

class DishModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Obtener todos los platos de un restaurante
     */
    public function getDishesByRestaurant(int $restaurantId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM platos 
            WHERE restaurant_id = ? AND activo = 1 
            ORDER BY categoria, nombre
        ");
        $stmt->execute([$restaurantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtener un plato específico
     */
    public function getDish(int $restaurantId, int $dishId): ?array
    {
        // Primero busca solo por ID para debug
        $stmt = $this->db->prepare("SELECT * FROM platos WHERE id = ?");
        $stmt->execute([$dishId]);
        $dishById = $stmt->fetch(PDO::FETCH_ASSOC);

        error_log("🔍 Buscando solo por ID $dishId: " . ($dishById ? "ENCONTRADO" : "NO ENCONTRADO"));
        if ($dishById) {
            error_log("🔍 restaurant_id en BD: " . $dishById['restaurant_id']);
            error_log("🔍 activo en BD: " . $dishById['activo']);
        }

        // Luego la búsqueda normal
        $stmt = $this->db->prepare("
        SELECT * FROM platos 
        WHERE id = ? AND restaurant_id = ? AND activo = 1
    ");
        $stmt->execute([$dishId, $restaurantId]);
        $dish = $stmt->fetch(PDO::FETCH_ASSOC);

        return $dish ?: null;
    }

    /**
     * Crear nuevo plato
     */
    public function createDish(array $data): ?int
    {
        try {
            $sql = "INSERT INTO platos 
            (restaurant_id, nombre, descripcion, precio, categoria, ingredientes,
             disponible, es_vegano, es_vegetariano, sin_gluten, calorias, tiempo_preparacion, imagenes)
            VALUES 
            (:restaurant_id, :nombre, :descripcion, :precio, :categoria, :ingredientes,
             :disponible, :es_vegano, :es_vegetariano, :sin_gluten, :calorias, :tiempo_preparacion, :imagenes)";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                ':restaurant_id' => $data['restaurant_id'],
                ':nombre' => $data['nombre'],
                ':descripcion' => $data['descripcion'] ?? null,
                ':precio' => $data['precio'] ?? null,
                ':categoria' => $data['categoria'],
                ':ingredientes' => $data['ingredientes'] ?? null,
                ':disponible' => $data['disponible'] ? 1 : 0,
                ':es_vegano' => $data['es_vegano'] ? 1 : 0,
                ':es_vegetariano' => $data['es_vegetariano'] ? 1 : 0,
                ':sin_gluten' => $data['sin_gluten'] ? 1 : 0,
                ':calorias' => $data['calorias'] ?? null,
                ':tiempo_preparacion' => $data['tiempo_preparacion'] ?? null,
                ':imagenes' => $data['imagenes'] ?? null // Agregar el campo imagenes
            ]);

            return $success ? $this->db->lastInsertId() : null;
        } catch (\PDOException $e) {
            error_log("❌ Error al crear plato: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Actualizar plato
     */
    public function updateDish(int $dishId, array $data): bool
    {
        try {
            $fields = [];
            $params = [':id' => $dishId];

            $allowedFields = [
                'nombre',
                'descripcion',
                'precio',
                'categoria',
                'ingredientes',
                'disponible',
                'es_vegano',
                'es_vegetariano',
                'sin_gluten',
                'calorias',
                'tiempo_preparacion',
                'imagenes' // Agregar el campo imagenes
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = :$field";

                    // Manejar valores booleanos
                    if (in_array($field, ['disponible', 'es_vegano', 'es_vegetariano', 'sin_gluten'])) {
                        $params[":$field"] = $data[$field] ? 1 : 0;
                    } else {
                        $params[":$field"] = $data[$field];
                    }
                }
            }

            if (empty($fields)) {
                return false;
            }

            $sql = "UPDATE platos SET " . implode(', ', $fields) . " WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (\PDOException $e) {
            error_log("❌ Error al actualizar plato: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Eliminar plato (borrado lógico)
     */
    public function deleteDish(int $dishId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE platos 
                SET activo = 0 
                WHERE id = ?
            ");
            return $stmt->execute([$dishId]);
        } catch (\PDOException $e) {
            error_log("❌ Error al eliminar plato: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Actualizar imagen del plato
     */
    public function updateDishImage(int $dishId, string $imagePath): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE platos 
                SET imagen_url = ? 
                WHERE id = ?
            ");
            return $stmt->execute([$imagePath, $dishId]);
        } catch (\PDOException $e) {
            error_log("❌ Error al actualizar imagen del plato: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtener platos por categoría
     */
    public function getDishesByCategory(int $restaurantId, string $category): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM platos 
            WHERE restaurant_id = ? AND categoria = ? AND activo = 1 
            ORDER BY nombre
        ");
        $stmt->execute([$restaurantId, $category]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtener categorías de platos de un restaurante
     */
    public function getDishCategories(int $restaurantId): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT categoria 
            FROM platos 
            WHERE restaurant_id = ? AND activo = 1 
            ORDER BY categoria
        ");
        $stmt->execute([$restaurantId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Verificar si el plato pertenece al restaurante
     */
    public function dishBelongsToRestaurant(int $dishId, int $restaurantId): bool
    {
        $stmt = $this->db->prepare("
            SELECT id FROM platos 
            WHERE id = ? AND restaurant_id = ? AND activo = 1
        ");
        $stmt->execute([$dishId, $restaurantId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Obtener platos disponibles de un restaurante
     */
    public function getAvailableDishes(int $restaurantId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM platos 
            WHERE restaurant_id = ? AND disponible = 1 AND activo = 1 
            ORDER BY categoria, nombre
        ");
        $stmt->execute([$restaurantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Buscar platos por nombre o ingredientes
     */
    public function searchDishes(int $restaurantId, string $query): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM platos 
            WHERE restaurant_id = ? 
            AND (nombre LIKE ? OR ingredientes LIKE ? OR descripcion LIKE ?)
            AND activo = 1 
            ORDER BY nombre
        ");
        $searchTerm = "%$query%";
        $stmt->execute([$restaurantId, $searchTerm, $searchTerm, $searchTerm]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
