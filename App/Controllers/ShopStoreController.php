<?php

namespace App\Controllers;

use App\Models\ShopStoreModel;
use EasyProjects\SimpleRouter\Router;
use App\Services\FileUploadService;

class ShopStoreController
{
    private ShopStoreModel $model;

    public function __construct(?ShopStoreModel $model = null)
    {
        $this->model = $model ?? new ShopStoreModel();
    }

    // Trae la tienda del vendedor logueado (o null si todavia no creo una)
    public function getMyStore()
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $store = $this->model->getStoreByUserId($userId);

        Router::$response->status(200)->json([
            "data" => $store ?: null,
        ]);
    }

    // Crea o actualiza la tienda del vendedor logueado. Enviar lat/lng la
    // deja pendiente de aprobacion admin (o re-pendiente si cambio de lugar).
    public function createOrUpdateStore()
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $body = Router::$request->body;
        $storeName = trim((string) ($body->store_name ?? ''));
        $direccion = isset($body->direccion) ? trim((string) $body->direccion) : null;
        $lat = isset($body->lat) && $body->lat !== null ? (float) $body->lat : null;
        $lng = isset($body->lng) && $body->lng !== null ? (float) $body->lng : null;

        if ($storeName === '') {
            Router::$response->status(400)->json(["message" => "Falta el nombre de la tienda"]);
            return;
        }

        if (($lat === null) !== ($lng === null)) {
            Router::$response->status(400)->json(["message" => "lat y lng deben enviarse juntos"]);
            return;
        }

        $profile = [];
        foreach (['telefono', 'email', 'descripcion', 'horario_apertura', 'horario_cierre', 'website'] as $field) {
            if (isset($body->$field)) {
                $value = trim((string) $body->$field);
                $profile[$field] = $value !== '' ? $value : null;
            }
        }

        $ok = $this->model->createOrUpdateStore($userId, $storeName, $direccion, $lat, $lng, $profile);
        if (!$ok) {
            Router::$response->status(500)->json(["message" => "Error al guardar la tienda"]);
            return;
        }

        Router::$response->status(200)->json(["message" => "Tienda guardada correctamente"]);
    }

    // Sube la foto de portada de la tienda del vendedor logueado
    public function uploadCoverPhoto()
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        if (empty($_FILES) || !isset($_FILES['image'])) {
            Router::$response->status(400)->json(["message" => "No se subió ninguna imagen"]);
            return;
        }

        $store = $this->model->getStoreByUserId($userId);
        if (!$store) {
            Router::$response->status(404)->json(["message" => "Primero creá tu tienda"]);
            return;
        }

        $uploadService = new FileUploadService();
        $result = $uploadService->uploadProductFile($_FILES['image'], $userId);

        if (!$result['success']) {
            Router::$response->status(400)->json(["message" => $result['message']]);
            return;
        }

        $ok = $this->model->setFotoPortada($userId, $result['file_url']);
        if (!$ok) {
            Router::$response->status(500)->json(["message" => "Error al guardar la foto de portada"]);
            return;
        }

        Router::$response->status(200)->json(["message" => "Foto de portada actualizada", "url" => $result['file_url']]);
    }

    // Sube una foto adicional del local/producto (maximo 8 por tienda)
    public function uploadStoreImage()
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        if (empty($_FILES) || !isset($_FILES['image'])) {
            Router::$response->status(400)->json(["message" => "No se subió ninguna imagen"]);
            return;
        }

        $store = $this->model->getStoreByUserId($userId);
        if (!$store) {
            Router::$response->status(404)->json(["message" => "Primero creá tu tienda"]);
            return;
        }

        if ($this->model->countStoreImages((int) $store['id']) >= 8) {
            Router::$response->status(400)->json(["message" => "Ya tenés el máximo de 8 fotos"]);
            return;
        }

        $uploadService = new FileUploadService();
        $result = $uploadService->uploadProductFile($_FILES['image'], $userId);

        if (!$result['success']) {
            Router::$response->status(400)->json(["message" => $result['message']]);
            return;
        }

        $ok = $this->model->addStoreImage((int) $store['id'], $result['file_url']);
        if (!$ok) {
            Router::$response->status(500)->json(["message" => "Error al guardar la imagen"]);
            return;
        }

        Router::$response->status(201)->json(["message" => "Imagen agregada", "url" => $result['file_url']]);
    }

    // Lista las fotos adicionales de la tienda del vendedor logueado
    public function getMyStoreImages()
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $store = $this->model->getStoreByUserId($userId);
        if (!$store) {
            Router::$response->status(200)->json(["data" => []]);
            return;
        }

        $images = $this->model->getStoreImages((int) $store['id']);

        Router::$response->status(200)->json(["data" => $images]);
    }

    // Borra una foto adicional propia
    public function deleteStoreImage($imageId)
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $store = $this->model->getStoreByUserId($userId);
        if (!$store) {
            Router::$response->status(404)->json(["message" => "No tenés una tienda"]);
            return;
        }

        $ok = $this->model->deleteStoreImage((int) $imageId, (int) $store['id']);
        if (!$ok) {
            Router::$response->status(404)->json(["message" => "Imagen no encontrada"]);
            return;
        }

        Router::$response->status(200)->json(["message" => "Imagen eliminada"]);
    }

    // Puntos GPS pendientes de aprobacion (solo ADMIN)
    public function getPendingLocationApprovals()
    {
        $user = Router::$request->user ?? null;
        if (!$user || strtoupper($user->rol ?? '') !== 'ADMIN') {
            Router::$response->status(403)->json(["message" => "Acceso denegado. Se requiere rol ADMIN"]);
            return;
        }

        $pending = $this->model->getPendingLocationApprovals();

        Router::$response->status(200)->json([
            "data" => $pending,
            "message" => "Puntos pendientes de aprobación obtenidos correctamente",
        ]);
    }

    // Aprobar o rechazar el punto GPS de una tienda (solo ADMIN)
    public function updateLocationApproval($id)
    {
        $user = Router::$request->user ?? null;
        if (!$user || strtoupper($user->rol ?? '') !== 'ADMIN') {
            Router::$response->status(403)->json(["message" => "Acceso denegado. Se requiere rol ADMIN"]);
            return;
        }

        $storeId = (int) $id;
        if ($storeId <= 0) {
            Router::$response->status(400)->json(["message" => "ID de tienda no válido"]);
            return;
        }

        $body = Router::$request->body ?? null;
        $approvedRaw = $body->location_approved ?? null;
        if ($approvedRaw === null) {
            Router::$response->status(400)->json(["message" => "Falta location_approved"]);
            return;
        }

        $approved = filter_var($approvedRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($approved === null) {
            $approved = (bool) ((int) $approvedRaw);
        }

        $store = $this->model->getStoreById($storeId);
        if (!$store) {
            Router::$response->status(404)->json(["message" => "Tienda no encontrada"]);
            return;
        }

        $ok = $this->model->updateLocationApproval($storeId, $approved);
        if (!$ok) {
            Router::$response->status(500)->json(["message" => "Error al actualizar la aprobación"]);
            return;
        }

        Router::$response->status(200)->json(["message" => "Aprobación actualizada correctamente"]);
    }

    // Lista publica de tiendas con ubicacion aprobada (para el mapa, etapa futura)
    public function getApprovedStores()
    {
        $stores = $this->model->getApprovedStores();

        Router::$response->status(200)->json(["data" => $stores]);
    }
}
