<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class ShopStoreModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getStoreByUserId(int $userId): array|false
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM shop_stores WHERE user_id = :user_id LIMIT 1");
            $stmt->execute([':user_id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: false;
        } catch (PDOException $e) {
            error_log("ShopStoreModel getStoreByUserId ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function getStoreById(int $id): array|false
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM shop_stores WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: false;
        } catch (PDOException $e) {
            error_log("ShopStoreModel getStoreById ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Crea la tienda del vendedor, o la actualiza si ya existe (una por vendedor).
    // Si viene lat/lng nuevo, vuelve a quedar pendiente de aprobacion (igual que
    // restaurantes: cualquier cambio de ubicacion se re-revisa). $profile es un
    // array asociativo opcional con: telefono, email, descripcion,
    // horario_apertura, horario_cierre, website (todas opcionales, no tocan lo
    // que ya haya si no vienen).
    public function createOrUpdateStore(
        int $userId,
        string $storeName,
        ?string $direccion,
        ?float $lat,
        ?float $lng,
        array $profile = []
    ): bool {
        try {
            $existing = $this->getStoreByUserId($userId);
            $hasNewLocation = ($lat !== null && $lng !== null);

            // Si no viene una ubicacion nueva, preservar la que ya habia (no
            // pisar lat/lng con null solo porque este POST fue para actualizar
            // otro campo, como el perfil del negocio).
            if (!$hasNewLocation && $existing) {
                $lat = $existing['lat'] !== null ? (float) $existing['lat'] : null;
                $lng = $existing['lng'] !== null ? (float) $existing['lng'] : null;
            }

            $telefono = $profile['telefono'] ?? ($existing['telefono'] ?? null);
            $email = $profile['email'] ?? ($existing['email'] ?? null);
            $descripcion = $profile['descripcion'] ?? ($existing['descripcion'] ?? null);
            $horarioApertura = $profile['horario_apertura'] ?? ($existing['horario_apertura'] ?? null);
            $horarioCierre = $profile['horario_cierre'] ?? ($existing['horario_cierre'] ?? null);
            $website = $profile['website'] ?? ($existing['website'] ?? null);

            if ($existing) {
                $locationChanged = $hasNewLocation && (
                    (float) $existing['lat'] !== $lat || (float) $existing['lng'] !== $lng
                );
                $stmt = $this->db->prepare("
                    UPDATE shop_stores
                    SET store_name = :store_name,
                        direccion = :direccion,
                        lat = :lat,
                        lng = :lng,
                        telefono = :telefono,
                        email = :email,
                        descripcion = :descripcion,
                        horario_apertura = :horario_apertura,
                        horario_cierre = :horario_cierre,
                        website = :website,
                        location_approved = CASE WHEN :location_changed THEN 0 ELSE location_approved END
                    WHERE user_id = :user_id
                ");
                return $stmt->execute([
                    ':store_name' => $storeName,
                    ':direccion' => $direccion,
                    ':lat' => $lat,
                    ':lng' => $lng,
                    ':telefono' => $telefono,
                    ':email' => $email,
                    ':descripcion' => $descripcion,
                    ':horario_apertura' => $horarioApertura,
                    ':horario_cierre' => $horarioCierre,
                    ':website' => $website,
                    ':location_changed' => $locationChanged ? 1 : 0,
                    ':user_id' => $userId,
                ]);
            }

            $stmt = $this->db->prepare("
                INSERT INTO shop_stores (
                    user_id, store_name, direccion, lat, lng, location_approved,
                    telefono, email, descripcion, horario_apertura, horario_cierre, website
                )
                VALUES (
                    :user_id, :store_name, :direccion, :lat, :lng, 0,
                    :telefono, :email, :descripcion, :horario_apertura, :horario_cierre, :website
                )
            ");
            return $stmt->execute([
                ':user_id' => $userId,
                ':store_name' => $storeName,
                ':direccion' => $direccion,
                ':lat' => $lat,
                ':lng' => $lng,
                ':telefono' => $telefono,
                ':email' => $email,
                ':descripcion' => $descripcion,
                ':horario_apertura' => $horarioApertura,
                ':horario_cierre' => $horarioCierre,
                ':website' => $website,
            ]);
        } catch (PDOException $e) {
            error_log("ShopStoreModel createOrUpdateStore ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Guarda la URL de la foto de portada (subida por separado via FileUploadService)
    public function setFotoPortada(int $userId, string $url): bool
    {
        try {
            $stmt = $this->db->prepare("UPDATE shop_stores SET foto_portada = :url WHERE user_id = :user_id");
            return $stmt->execute([':url' => $url, ':user_id' => $userId]);
        } catch (PDOException $e) {
            error_log("ShopStoreModel setFotoPortada ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function countStoreImages(int $storeId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM shop_store_images WHERE store_id = :store_id");
        $stmt->execute([':store_id' => $storeId]);
        return (int) $stmt->fetchColumn();
    }

    public function addStoreImage(int $storeId, string $url): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO shop_store_images (store_id, image_url, display_order)
                VALUES (:store_id, :url, (SELECT next_order FROM (SELECT COALESCE(MAX(display_order), -1) + 1 AS next_order FROM shop_store_images WHERE store_id = :store_id2) t)
            )");
            return $stmt->execute([':store_id' => $storeId, ':url' => $url, ':store_id2' => $storeId]);
        } catch (PDOException $e) {
            error_log("ShopStoreModel addStoreImage ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function getStoreImages(int $storeId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM shop_store_images WHERE store_id = :store_id ORDER BY display_order ASC
        ");
        $stmt->execute([':store_id' => $storeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Borra una imagen, solo si pertenece a la tienda del usuario dueno (chequeado en el controller)
    public function deleteStoreImage(int $imageId, int $storeId): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM shop_store_images WHERE id = :id AND store_id = :store_id");
            $stmt->execute([':id' => $imageId, ':store_id' => $storeId]);
            return $stmt->rowCount() === 1;
        } catch (PDOException $e) {
            error_log("ShopStoreModel deleteStoreImage ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Puntos GPS pendientes de aprobacion admin (tienen lat/lng y location_approved = 0)
    public function getPendingLocationApprovals(): array
    {
        $stmt = $this->db->query("
            SELECT s.*, u.name AS owner_name, u.email AS owner_email
            FROM shop_stores s
            LEFT JOIN users u ON s.user_id = u.id
            WHERE s.activo = 1
              AND s.lat IS NOT NULL
              AND s.lng IS NOT NULL
              AND s.location_approved = 0
            ORDER BY s.id DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Aprobar o rechazar punto GPS. Al rechazar se limpian lat/lng (igual que restaurantes).
    public function updateLocationApproval(int $id, bool $approved): bool
    {
        try {
            if ($approved) {
                $stmt = $this->db->prepare("
                    UPDATE shop_stores
                    SET location_approved = 1
                    WHERE id = ? AND activo = 1 AND lat IS NOT NULL AND lng IS NOT NULL
                ");
                return $stmt->execute([$id]);
            }

            $stmt = $this->db->prepare("
                UPDATE shop_stores
                SET location_approved = 0, lat = NULL, lng = NULL
                WHERE id = ? AND activo = 1
            ");
            return $stmt->execute([$id]);
        } catch (PDOException $e) {
            error_log("ShopStoreModel updateLocationApproval ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Tiendas con ubicacion aprobada, para el mapa publico (etapa futura)
    public function getApprovedStores(): array
    {
        $stmt = $this->db->query("
            SELECT id, user_id, store_name, direccion, lat, lng
            FROM shop_stores
            WHERE activo = 1 AND location_approved = 1 AND lat IS NOT NULL AND lng IS NOT NULL
            ORDER BY store_name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
