<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class EncuestasModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // Crea una encuesta con sus opciones dentro de una transaccion
    public function createEncuesta(
        int $groupId,
        int $userId,
        string $pregunta,
        string $visibilidad,
        ?float $precio,
        array $opciones // [['texto' => ..., 'foto_url' => ...], ...]
    ): int|false {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                INSERT INTO encuestas_grupos (group_id, user_id, pregunta, visibilidad, precio)
                VALUES (:group_id, :user_id, :pregunta, :visibilidad, :precio)
            ");
            $stmt->execute([
                ':group_id' => $groupId,
                ':user_id' => $userId,
                ':pregunta' => $pregunta,
                ':visibilidad' => $visibilidad,
                ':precio' => $visibilidad === 'privado' ? $precio : null,
            ]);
            $encuestaId = (int) $this->db->lastInsertId();

            $stmtOpcion = $this->db->prepare("
                INSERT INTO encuestas_opciones (encuesta_id, texto, foto_url, orden)
                VALUES (:encuesta_id, :texto, :foto_url, :orden)
            ");
            foreach ($opciones as $i => $opcion) {
                $stmtOpcion->execute([
                    ':encuesta_id' => $encuestaId,
                    ':texto' => $opcion['texto'],
                    ':foto_url' => $opcion['foto_url'] ?? null,
                    ':orden' => $i,
                ]);
            }

            $this->db->commit();
            return $encuestaId;
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("CreateEncuesta ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Lista las encuestas de un grupo con opciones, conteo de votos y si el usuario ya voto/desbloqueo
    public function getEncuestasByGroup(int $groupId, int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    e.id, e.group_id, e.user_id, e.pregunta, e.visibilidad, e.precio, e.creada_en,
                    u.name AS user_name,
                    (
                        e.visibilidad = 'publico'
                        OR e.user_id = :uid1
                        OR EXISTS (
                            SELECT 1 FROM encuestas_pagos ep
                            WHERE ep.encuesta_id = e.id AND ep.usuario_id = :uid2 AND ep.estado = 'completado'
                        )
                    ) AS desbloqueado
                FROM encuestas_grupos e
                INNER JOIN users u ON u.id = e.user_id
                WHERE e.group_id = :group_id
                ORDER BY e.creada_en DESC
            ");
            $stmt->execute([':uid1' => $userId, ':uid2' => $userId, ':group_id' => $groupId]);
            $encuestas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$encuestas) return [];

            $encuestaIds = array_column($encuestas, 'id');
            $placeholders = implode(',', array_fill(0, count($encuestaIds), '?'));

            // Opciones + conteo de votos por opcion
            $stmtOpciones = $this->db->prepare("
                SELECT
                    o.id, o.encuesta_id, o.texto, o.foto_url, o.orden,
                    COUNT(v.id) AS votos
                FROM encuestas_opciones o
                LEFT JOIN encuestas_votos v ON v.opcion_id = o.id
                WHERE o.encuesta_id IN ($placeholders)
                GROUP BY o.id
                ORDER BY o.orden ASC
            ");
            $stmtOpciones->execute($encuestaIds);
            $opcionesPorEncuesta = [];
            foreach ($stmtOpciones->fetchAll(PDO::FETCH_ASSOC) as $op) {
                $opcionesPorEncuesta[$op['encuesta_id']][] = [
                    'id' => (int) $op['id'],
                    'texto' => $op['texto'],
                    'foto_url' => $op['foto_url'],
                    'votos' => (int) $op['votos'],
                ];
            }

            // Voto propio del usuario por encuesta
            $stmtMiVoto = $this->db->prepare("
                SELECT encuesta_id, opcion_id
                FROM encuestas_votos
                WHERE encuesta_id IN ($placeholders) AND usuario_id = ?
            ");
            $stmtMiVoto->execute([...$encuestaIds, $userId]);
            $miVotoPorEncuesta = [];
            foreach ($stmtMiVoto->fetchAll(PDO::FETCH_ASSOC) as $mv) {
                $miVotoPorEncuesta[$mv['encuesta_id']] = (int) $mv['opcion_id'];
            }

            foreach ($encuestas as &$encuesta) {
                $encuesta['desbloqueado'] = (bool) $encuesta['desbloqueado'];
                $encuesta['opciones'] = $opcionesPorEncuesta[$encuesta['id']] ?? [];
                $encuesta['mi_voto'] = $miVotoPorEncuesta[$encuesta['id']] ?? null;
                if (!$encuesta['desbloqueado']) {
                    // Ocultar conteos de votos si esta bloqueada por pago
                    foreach ($encuesta['opciones'] as &$op) {
                        $op['votos'] = null;
                    }
                    unset($op);
                }
            }
            unset($encuesta);

            return $encuestas;
        } catch (PDOException $e) {
            error_log("GetEncuestasByGroup ERROR: " . $e->getMessage());
            return [];
        }
    }

    public function getEncuestaForPayment(int $encuestaId, int $groupId): array|false
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, group_id, visibilidad, precio
                FROM encuestas_grupos
                WHERE id = :id AND group_id = :group_id
                LIMIT 1
            ");
            $stmt->execute([':id' => $encuestaId, ':group_id' => $groupId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: false;
        } catch (PDOException $e) {
            error_log("GetEncuestaForPayment ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Vota o cambia el voto (un voto activo por usuario por encuesta)
    public function vote(int $encuestaId, int $opcionId, int $usuarioId): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO encuestas_votos (encuesta_id, opcion_id, usuario_id)
                VALUES (:encuesta_id, :opcion_id, :usuario_id)
                ON DUPLICATE KEY UPDATE opcion_id = VALUES(opcion_id), votado_en = NOW()
            ");
            return $stmt->execute([
                ':encuesta_id' => $encuestaId,
                ':opcion_id' => $opcionId,
                ':usuario_id' => $usuarioId,
            ]);
        } catch (PDOException $e) {
            error_log("Vote ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Confirma que una opcion pertenece a la encuesta indicada (evita votar una opcion de otra encuesta)
    public function optionBelongsToEncuesta(int $opcionId, int $encuestaId): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT id FROM encuestas_opciones WHERE id = :opcion_id AND encuesta_id = :encuesta_id LIMIT 1");
            $stmt->execute([':opcion_id' => $opcionId, ':encuesta_id' => $encuestaId]);
            return (bool) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("OptionBelongsToEncuesta ERROR: " . $e->getMessage());
            return false;
        }
    }
}
