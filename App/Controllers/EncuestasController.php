<?php

namespace App\Controllers;

use App\Models\EncuestasModel;
use App\Models\EncuestasPagosModel;
use App\Models\GroupsModel;
use App\Models\PrecioGrupoModel;
use EasyProjects\SimpleRouter\Router;

class EncuestasController
{
    private EncuestasModel $encuestasModel;
    private GroupsModel $groupsModel;
    private PrecioGrupoModel $precioGrupoModel;

    public function __construct(
        ?EncuestasModel $encuestasModel = null,
        ?GroupsModel $groupsModel = null,
        ?PrecioGrupoModel $precioGrupoModel = null
    ) {
        $this->encuestasModel = $encuestasModel ?? new EncuestasModel();
        $this->groupsModel = $groupsModel ?? new GroupsModel();
        $this->precioGrupoModel = $precioGrupoModel ?? new PrecioGrupoModel();
    }

    // Subir la foto de una opcion antes de crear la encuesta
    public function uploadOpcionFoto()
    {
        $idGroup = (int) (Router::$request->params->idGroup ?? 0);
        $userId = (int) (Router::$request->user->id ?? 0);

        if (!$idGroup || !$userId) {
            Router::$response->status(400)->send(["message" => "Datos invalidos"]);
            return;
        }
        if (!$this->groupsModel->isUserInGroup($idGroup, $userId)) {
            Router::$response->status(403)->send(["message" => "No perteneces a este grupo"]);
            return;
        }
        if (!isset($_FILES['file'])) {
            Router::$response->status(400)->send(["message" => "No se recibio ningun archivo"]);
            return;
        }

        $fileUploadService = new \App\Services\FileUploadService();
        $result = $fileUploadService->uploadEncuestaOpcionFoto($_FILES['file'], $idGroup);

        if (!$result['success']) {
            Router::$response->status(500)->send(["message" => $result['message']]);
            return;
        }

        Router::$response->status(201)->send([
            "message" => "Foto subida correctamente",
            "data" => ["foto_url" => $result['foto_url']]
        ]);
    }

    // Crear una encuesta (publica o pagada) con sus opciones
    public function createEncuesta()
    {
        $idGroup = (int) (Router::$request->params->idGroup ?? 0);
        $userId = (int) (Router::$request->user->id ?? 0);
        $body = Router::$request->body;

        $pregunta = trim((string) ($body->pregunta ?? ''));
        $visibilidad = (($body->visibilidad ?? 'publico') === 'privado') ? 'privado' : 'publico';
        $precio = isset($body->precio) ? (float) $body->precio : null;
        $opciones = is_array($body->opciones ?? null) ? $body->opciones : (array) ($body->opciones ?? []);

        if (!$idGroup || !$userId) {
            Router::$response->status(400)->send(["message" => "Datos invalidos"]);
            return;
        }
        if ($pregunta === '') {
            Router::$response->status(400)->send(["message" => "La pregunta es requerida"]);
            return;
        }
        if (count($opciones) < 2) {
            Router::$response->status(400)->send(["message" => "Se requieren al menos 2 opciones"]);
            return;
        }
        if (!$this->groupsModel->isUserInGroup($idGroup, $userId)) {
            Router::$response->status(403)->send(["message" => "No perteneces a este grupo"]);
            return;
        }
        if ($visibilidad === 'privado' && !$this->precioGrupoModel->isMontoValido((float) $precio)) {
            Router::$response->status(400)->send(["message" => "Precio invalido para encuesta privada"]);
            return;
        }

        $opcionesLimpias = [];
        foreach ($opciones as $op) {
            $texto = trim((string) (is_object($op) ? ($op->texto ?? '') : ($op['texto'] ?? '')));
            $fotoUrl = is_object($op) ? ($op->foto_url ?? null) : ($op['foto_url'] ?? null);
            if ($texto === '') continue;
            $opcionesLimpias[] = ['texto' => $texto, 'foto_url' => $fotoUrl ?: null];
        }
        if (count($opcionesLimpias) < 2) {
            Router::$response->status(400)->send(["message" => "Se requieren al menos 2 opciones con texto"]);
            return;
        }

        $encuestaId = $this->encuestasModel->createEncuesta(
            $idGroup,
            $userId,
            $pregunta,
            $visibilidad,
            $visibilidad === 'privado' ? $precio : null,
            $opcionesLimpias
        );

        if (!$encuestaId) {
            Router::$response->status(500)->send(["message" => "Error al crear la encuesta"]);
            return;
        }

        Router::$response->status(201)->send([
            "message" => "Encuesta creada correctamente",
            "data" => ["id" => $encuestaId]
        ]);
    }

    // Listar encuestas de un grupo (con opciones, votos y estado de desbloqueo)
    public function getEncuestas()
    {
        $idGroup = (int) (Router::$request->params->idGroup ?? 0);
        $userId = (int) (Router::$request->user->id ?? 0);

        if (!$idGroup || !$userId) {
            Router::$response->status(400)->send(["message" => "Datos invalidos"]);
            return;
        }
        if (!$this->groupsModel->isUserInGroup($idGroup, $userId)) {
            Router::$response->status(403)->send(["message" => "No perteneces a este grupo"]);
            return;
        }

        $encuestas = $this->encuestasModel->getEncuestasByGroup($idGroup, $userId);
        Router::$response->status(200)->send([
            "data" => $encuestas,
            "message" => "Encuestas listadas correctamente"
        ]);
    }

    // Votar (o cambiar el voto) en una encuesta ya desbloqueada
    public function vote()
    {
        $idGroup = (int) (Router::$request->params->idGroup ?? 0);
        $idEncuesta = (int) (Router::$request->params->idEncuesta ?? 0);
        $userId = (int) (Router::$request->user->id ?? 0);
        $opcionId = (int) (Router::$request->body->opcion_id ?? 0);

        if (!$idGroup || !$idEncuesta || !$userId || !$opcionId) {
            Router::$response->status(400)->send(["message" => "Datos invalidos"]);
            return;
        }
        if (!$this->groupsModel->isUserInGroup($idGroup, $userId)) {
            Router::$response->status(403)->send(["message" => "No perteneces a este grupo"]);
            return;
        }

        $encuesta = $this->encuestasModel->getEncuestaForPayment($idEncuesta, $idGroup);
        if (!$encuesta) {
            Router::$response->status(404)->send(["message" => "Encuesta no encontrada"]);
            return;
        }

        if ($encuesta['visibilidad'] === 'privado') {
            $pagosModel = new EncuestasPagosModel();
            $pagado = false;
            // Reutiliza la misma logica de desbloqueo que getEncuestasByGroup: propio autor o pago completado
            if ((int) $encuesta['group_id'] === $idGroup) {
                $esAutor = $this->encuestasModel->getEncuestasByGroup($idGroup, $userId);
                foreach ($esAutor as $e) {
                    if ((int) $e['id'] === $idEncuesta && $e['desbloqueado']) {
                        $pagado = true;
                        break;
                    }
                }
            }
            if (!$pagado) {
                Router::$response->status(402)->send(["message" => "Debes pagar para votar en esta encuesta"]);
                return;
            }
        }

        if (!$this->encuestasModel->optionBelongsToEncuesta($opcionId, $idEncuesta)) {
            Router::$response->status(400)->send(["message" => "Opcion invalida para esta encuesta"]);
            return;
        }

        $ok = $this->encuestasModel->vote($idEncuesta, $opcionId, $userId);
        if (!$ok) {
            Router::$response->status(500)->send(["message" => "Error al registrar el voto"]);
            return;
        }

        Router::$response->status(200)->send(["message" => "Voto registrado correctamente"]);
    }

    // Crear (o reutilizar) el link de pago de Square para desbloquear una encuesta privada
    public function createEncuestaPayment()
    {
        $groupId = (int) (Router::$request->params->idGroup ?? 0);
        $pollId = (int) (Router::$request->params->idEncuesta ?? 0);
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId || !$this->groupsModel->isUserInGroup($groupId, $userId)) {
            Router::$response->status(403)->send(['message' => 'No tienes acceso a este grupo.']); return;
        }
        $poll = $this->encuestasModel->getEncuestaForPayment($pollId, $groupId);
        if (!$poll || $poll['visibilidad'] !== 'privado' || empty($poll['precio'])) {
            Router::$response->status(400)->send(['message' => 'La encuesta no requiere pago o no existe.']); return;
        }
        try {
            $amount = (float) \App\Services\UsdMoney::decimal(\App\Services\UsdMoney::cents($poll['precio']));
            $result = (new \App\Services\WalletCheckout())->run($userId, 'group-poll', (string) $pollId, ['poll' => $pollId], function () use ($poll, $userId, $pollId, $amount, $groupId) {
                $db = \App\Configs\Database::getInstance()->getConnection();
                $lock = $db->prepare('SELECT id, user_id, group_id, visibilidad, precio FROM encuestas_grupos WHERE id = ? AND group_id = ? FOR UPDATE');
                $lock->execute([$pollId, $groupId]);$poll = $lock->fetch(\PDO::FETCH_ASSOC);
                if (!$poll || !$this->groupsModel->isUserInGroup($groupId, $userId) || $poll['visibilidad'] !== 'privado') throw new \DomainException('Contenido no disponible');
                if (\App\Services\UsdMoney::cents($poll['precio']) !== \App\Services\UsdMoney::cents($amount)) throw new \DomainException('El precio cambió; actualiza el contenido');
                $db = \App\Configs\Database::getInstance()->getConnection();
                $stmt = $db->prepare("SELECT id FROM encuestas_pagos WHERE encuesta_id = ? AND usuario_id = ? AND estado = 'completado' LIMIT 1");
                $stmt->execute([$pollId, $userId]);
                if ($id = $stmt->fetchColumn()) return ['paid' => true, 'alreadyPaid' => true, 'pago_id' => (int) $id];
                $debit = (new \App\Models\WalletModel())->debitForPurchase($userId, $amount, 'group_poll_' . $pollId);
                if (!$debit['success']) throw new \DomainException($debit['message']);
                $model = new EncuestasPagosModel();
                $id = $model->createPaymentRequest($pollId, $userId, $amount, 'wallet_poll_' . $pollId . '_' . $userId, '', 'wallet_poll_' . $pollId . '_' . $userId);
                if (!$id || !$model->markCompleted($id)) throw new \RuntimeException('No se pudo desbloquear la encuesta');
                (new \App\Services\CommerceSettlement())->hold('group_poll_' . $id, $debit['transaction_id'], (int) $poll['user_id']);
                (new \App\Services\CommerceSettlement())->resolve('group_poll_' . $id, 'release', $userId, 'Entrega digital confirmada');
                return ['paid' => true, 'pago_id' => $id, 'new_balance' => $debit['new_balance']];
            });
            Router::$response->status(200)->send($result + ['message' => 'Encuesta desbloqueada con Wallet.']);
        } catch (\Throwable $e) {
            error_log('Poll Wallet checkout: ' . $e->getMessage());
            Router::$response->status($e instanceof \DomainException ? 402 : 503)->send(['message' => $e instanceof \DomainException ? $e->getMessage() : 'No se completó el pago. Puedes reintentar.']);
        }
    }


}
