<?php

namespace App\Controllers;

use App\Models\E2eeModel;
use App\Models\ChatModel;
use EasyProjects\SimpleRouter\Router;

class E2eeController
{
    private E2eeModel $model;
    private ChatModel $chatModel;

    public function __construct(?E2eeModel $model = null, ?ChatModel $chatModel = null)
    {
        $this->model = $model ?? new E2eeModel();
        $this->chatModel = $chatModel ?? new ChatModel();
    }

    // Sube (o reemplaza por completo) el bundle de llaves publicas del usuario logueado
    public function uploadBundle()
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        $body = Router::$request->body;

        $identityPubKey = trim((string) ($body->identity_pub_key ?? ''));
        $registrationId = (int) ($body->registration_id ?? 0);
        $deviceLabel = isset($body->device_label) ? trim((string) $body->device_label) : null;
        $signedPrekey = $body->signed_prekey ?? null;
        $oneTimePrekeysRaw = is_array($body->one_time_prekeys ?? null) ? $body->one_time_prekeys : [];

        if (!$userId || $identityPubKey === '' || !$registrationId || !$signedPrekey) {
            Router::$response->status(400)->send(["message" => "Datos invalidos"]);
            return;
        }

        $signedPrekeyId = (int) ($signedPrekey->key_id ?? 0);
        $signedPrekeyPub = trim((string) ($signedPrekey->pub_key ?? ''));
        $signedPrekeySignature = trim((string) ($signedPrekey->signature ?? ''));

        if (!$signedPrekeyId || $signedPrekeyPub === '' || $signedPrekeySignature === '') {
            Router::$response->status(400)->send(["message" => "Signed prekey invalida"]);
            return;
        }

        $oneTimePrekeys = [];
        foreach ($oneTimePrekeysRaw as $prekey) {
            $keyId = (int) (is_object($prekey) ? ($prekey->key_id ?? 0) : ($prekey['key_id'] ?? 0));
            $pubKey = trim((string) (is_object($prekey) ? ($prekey->pub_key ?? '') : ($prekey['pub_key'] ?? '')));
            if ($keyId && $pubKey !== '') {
                $oneTimePrekeys[] = ['key_id' => $keyId, 'pub_key' => $pubKey];
            }
        }

        if (count($oneTimePrekeys) < 1) {
            Router::$response->status(400)->send(["message" => "Se requiere al menos una prekey de un solo uso"]);
            return;
        }

        $ok = $this->model->uploadBundle(
            $userId,
            $identityPubKey,
            $registrationId,
            $deviceLabel,
            $signedPrekeyId,
            $signedPrekeyPub,
            $signedPrekeySignature,
            $oneTimePrekeys
        );

        if (!$ok) {
            Router::$response->status(500)->send(["message" => "Error al guardar el bundle de llaves"]);
            return;
        }

        Router::$response->status(201)->send(["message" => "Bundle de llaves guardado correctamente"]);
    }

    // Agrega mas prekeys de un solo uso sin reemplazar identidad
    public function replenishPrekeys()
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        $body = Router::$request->body;
        $oneTimePrekeysRaw = is_array($body->one_time_prekeys ?? null) ? $body->one_time_prekeys : [];

        if (!$userId || count($oneTimePrekeysRaw) < 1) {
            Router::$response->status(400)->send(["message" => "Datos invalidos"]);
            return;
        }

        $oneTimePrekeys = [];
        foreach ($oneTimePrekeysRaw as $prekey) {
            $keyId = (int) (is_object($prekey) ? ($prekey->key_id ?? 0) : ($prekey['key_id'] ?? 0));
            $pubKey = trim((string) (is_object($prekey) ? ($prekey->pub_key ?? '') : ($prekey['pub_key'] ?? '')));
            if ($keyId && $pubKey !== '') {
                $oneTimePrekeys[] = ['key_id' => $keyId, 'pub_key' => $pubKey];
            }
        }

        $ok = $this->model->addOneTimePrekeys($userId, $oneTimePrekeys);
        if (!$ok) {
            Router::$response->status(500)->send(["message" => "Error al reponer prekeys"]);
            return;
        }

        Router::$response->status(200)->send(["message" => "Prekeys repuestas correctamente"]);
    }

    // Cuenta cuantas prekeys de un solo uso le quedan al usuario logueado (para saber si reponer)
    public function getMyPrekeyCount()
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId) {
            Router::$response->status(400)->send(["message" => "Datos invalidos"]);
            return;
        }

        $count = $this->model->countUnusedOneTimePrekeys($userId);
        Router::$response->status(200)->send(["data" => ["count" => $count]]);
    }

    // Trae el bundle publico de otro usuario para iniciar una sesion X3DH con el
    public function getBundle()
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        $targetUserId = (int) (Router::$request->params->idUser ?? 0);

        if (!$userId || !$targetUserId) {
            Router::$response->status(400)->send(["message" => "Datos invalidos"]);
            return;
        }

        if (!$this->model->hasBundle($targetUserId)) {
            Router::$response->status(404)->send(["message" => "Este usuario no tiene cifrado configurado"]);
            return;
        }

        $bundle = $this->model->consumeBundleForSession($targetUserId);
        if (!$bundle) {
            Router::$response->status(500)->send(["message" => "Error al obtener el bundle"]);
            return;
        }

        Router::$response->status(200)->send(["data" => $bundle]);
    }

    // Activa el cifrado E2EE para una conversacion 1-a-1 existente
    public function enableForChat()
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        $chatId = (int) (Router::$request->params->idChat ?? 0);

        if (!$userId || !$chatId) {
            Router::$response->status(400)->send(["message" => "Datos invalidos"]);
            return;
        }

        if (!$this->chatModel->chatExists($chatId)) {
            Router::$response->status(404)->send(["message" => "Chat no encontrado"]);
            return;
        }

        $ok = $this->model->enableForChat($chatId, $userId);
        if (!$ok) {
            Router::$response->status(500)->send(["message" => "Error al activar el cifrado"]);
            return;
        }

        Router::$response->status(200)->send(["message" => "Cifrado activado para esta conversacion"]);
    }

    public function getChatStatus()
    {
        $chatId = (int) (Router::$request->params->idChat ?? 0);
        if (!$chatId) {
            Router::$response->status(400)->send(["message" => "Datos invalidos"]);
            return;
        }

        $enabled = $this->model->isEnabledForChat($chatId);
        Router::$response->status(200)->send(["data" => ["enabled" => $enabled]]);
    }
}
