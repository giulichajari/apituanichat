<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class PrecioGrupoModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // Lista los montos activos permitidos, ordenados
    public function getPreciosActivos(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, monto, orden FROM configuracion_precios_grupos WHERE activo = 1 ORDER BY orden ASC, monto ASC"
            );
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("GetPreciosActivos ERROR: " . $e->getMessage());
            return [];
        }
    }

    // Valida que el monto pedido sea uno de los precios activos configurados
    public function isMontoValido(float $monto): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id FROM configuracion_precios_grupos WHERE monto = :monto AND activo = 1 LIMIT 1"
            );
            $stmt->execute([':monto' => $monto]);
            return (bool) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("IsMontoValido ERROR: " . $e->getMessage());
            return false;
        }
    }
}
