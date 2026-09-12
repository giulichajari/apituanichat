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
        $idGroup = (int) (Router::$request->params->idGroup ?? 0);
        $idEncuesta = (int) (Router::$request->params->idEncuesta ?? 0);
        $userId = (int) (Router::$request->user->id ?? 0);

        if (!$idGroup || !$idEncuesta || !$userId) {
            Router::$response->status(400)->send(["message" => "Datos invalidos"]);
            return;
        }
        if (!$this->groupsModel->isUserInGroup($idGroup, $userId)) {
            Router::$response->status(403)->send(["message" => "No perteneces a este grupo"]);
            return;
        }

        $pagosModel = new EncuestasPagosModel();

        $pending = $pagosModel->getPendingForEncuestaAndUser($idEncuesta, $userId);
        if ($pending && !empty($pending['payment_link_url'])) {
            Router::$response->status(200)->send([
                "paymentUrl" => $pending['payment_link_url'],
                "pago_id" => (int) $pending['id']
            ]);
            return;
        }

        $encuesta = $this->encuestasModel->getEncuestaForPayment($idEncuesta, $idGroup);
        if (!$encuesta) {
            Router::$response->status(404)->send(["message" => "Encuesta no encontrada"]);
            return;
        }
        if ($encuesta['visibilidad'] !== 'privado' || empty($encuesta['precio'])) {
            Router::$response->status(400)->send(["message" => "Esta encuesta no requiere pago"]);
            return;
        }

        $monto = (float) $encuesta['precio'];
        $link = $this->createEncuestaPaymentLink($idEncuesta, $monto);

        if (empty($link['url']) || empty($link['payment_link_id'])) {
            Router::$response->status(500)->send([
                "message" => $link['error'] ?? "No se pudo crear el link de pago"
            ]);
            return;
        }

        $pagoId = $pagosModel->createPaymentRequest(
            $idEncuesta,
            $userId,
            $monto,
            $link['payment_link_id'],
            $link['url'],
            $link['idempotency_key']
        );

        Router::$response->status(201)->send([
            "paymentUrl" => $link['url'],
            "pago_id" => $pagoId
        ]);
    }

    private function createEncuestaPaymentLink(int $encuestaId, float $amount): array
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
                'payment_link_id' => null,
                'idempotency_key' => null,
                'error' => empty($accessToken) ? 'SQUARE_ACCESS_TOKEN no configurado' : 'SQUARE_LOCATION_ID no configurado'
            ];
        }

        $amountCents = (int) round($amount * 100);
        $idempotencyKey = uniqid('encuesta_', true);
        $postData = [
            "idempotency_key" => $idempotencyKey,
            "quick_pay" => [
                "name" => "Encuesta de grupo #{$encuestaId}",
                "price_money" => [
                    "amount" => $amountCents,
                    "currency" => "USD"
                ],
                "location_id" => $locationId
            ]
        ];

        $ch = curl_init($squareBaseUrl . "/v2/online-checkout/payment-links");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer $accessToken",
            "Square-Version: 2024-11-20"
        ]);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        $result = is_string($response) ? json_decode($response, true) : [];
        $paymentLinkUrl = $result['payment_link']['url'] ?? null;
        $squarePaymentLinkId = $result['payment_link']['id'] ?? null;

        return [
            'url' => $paymentLinkUrl,
            'payment_link_id' => $squarePaymentLinkId,
            'idempotency_key' => $idempotencyKey,
            'error' => $curlError ?: ($paymentLinkUrl ? null : 'Square no devolvio un link de pago')
        ];
    }
}
