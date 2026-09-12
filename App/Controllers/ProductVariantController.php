<?php

namespace App\Controllers;

use App\Models\ProductVariantModel;
use EasyProjects\SimpleRouter\Router;
use App\Services\FileUploadService;

class ProductVariantController
{
    private ProductVariantModel $model;

    public function __construct(?ProductVariantModel $model = null)
    {
        $this->model = $model ?? new ProductVariantModel();
    }

    // Lista publica de variantes activas de un producto (ficha del producto / visor 3D)
    public function getVariants($productId)
    {
        $productId = (int) $productId;
        $variants = $this->model->getVariantsByProductId($productId);

        Router::$response->status(200)->json(["data" => $variants]);
    }

    // Lista completa (incluye inactivas) para el vendedor dueno del producto
    public function getMyVariants($productId)
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $productId = (int) $productId;
        $ownerId = $this->model->getProductOwnerId($productId);
        if ($ownerId === false) {
            Router::$response->status(404)->json(["message" => "Producto no encontrado"]);
            return;
        }
        if ($ownerId !== $userId) {
            Router::$response->status(403)->json(["message" => "No tenés permiso sobre este producto"]);
            return;
        }

        $variants = $this->model->getAllVariantsByProductId($productId);

        Router::$response->status(200)->json(["data" => $variants]);
    }

    // Crea una variante nueva (color/talla/marca fijos, precio opcional, stock propio)
    public function createVariant($productId)
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $productId = (int) $productId;
        $ownerId = $this->model->getProductOwnerId($productId);
        if ($ownerId === false) {
            Router::$response->status(404)->json(["message" => "Producto no encontrado"]);
            return;
        }
        if ($ownerId !== $userId) {
            Router::$response->status(403)->json(["message" => "No tenés permiso sobre este producto"]);
            return;
        }

        $body = Router::$request->body;
        $color = isset($body->color) && trim((string) $body->color) !== '' ? trim((string) $body->color) : null;
        $talla = isset($body->talla) && trim((string) $body->talla) !== '' ? trim((string) $body->talla) : null;
        $marca = isset($body->marca) && trim((string) $body->marca) !== '' ? trim((string) $body->marca) : null;
        $precio = isset($body->precio) && $body->precio !== null && $body->precio !== '' ? (float) $body->precio : null;
        $stockQuantity = isset($body->stock_quantity) ? (int) $body->stock_quantity : 0;

        if ($stockQuantity < 0) {
            Router::$response->status(400)->json(["message" => "El stock no puede ser negativo"]);
            return;
        }

        $newId = $this->model->createVariant($productId, $color, $talla, $marca, $precio, $stockQuantity);
        if ($newId === false) {
            Router::$response->status(500)->json(["message" => "Error al crear la variante"]);
            return;
        }

        Router::$response->status(201)->json(["message" => "Variante creada correctamente", "id" => $newId]);
    }

    // Actualiza una variante existente (color/talla/marca/precio/stock/activo)
    public function updateVariant($id)
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $variantId = (int) $id;
        $variant = $this->model->getVariantById($variantId);
        if (!$variant) {
            Router::$response->status(404)->json(["message" => "Variante no encontrada"]);
            return;
        }

        $ownerId = $this->model->getProductOwnerId((int) $variant['product_id']);
        if ($ownerId !== $userId) {
            Router::$response->status(403)->json(["message" => "No tenés permiso sobre esta variante"]);
            return;
        }

        $body = Router::$request->body;
        $fields = [];

        foreach (['color', 'talla', 'marca'] as $field) {
            if (isset($body->$field)) {
                $value = trim((string) $body->$field);
                $fields[$field] = $value !== '' ? $value : null;
            }
        }
        if (isset($body->precio)) {
            $fields['precio'] = ($body->precio === null || $body->precio === '') ? null : (float) $body->precio;
        }
        if (isset($body->stock_quantity)) {
            $stock = (int) $body->stock_quantity;
            if ($stock < 0) {
                Router::$response->status(400)->json(["message" => "El stock no puede ser negativo"]);
                return;
            }
            $fields['stock_quantity'] = $stock;
        }
        if (isset($body->activo)) {
            $fields['activo'] = ((bool) $body->activo) ? 1 : 0;
        }

        $ok = $this->model->updateVariant($variantId, $fields);
        if (!$ok) {
            Router::$response->status(500)->json(["message" => "Error al actualizar la variante"]);
            return;
        }

        Router::$response->status(200)->json(["message" => "Variante actualizada correctamente"]);
    }

    // Sube el modelo 3D (.glb) de una variante
    public function uploadModel($id)
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $variantId = (int) $id;
        $variant = $this->model->getVariantById($variantId);
        if (!$variant) {
            Router::$response->status(404)->json(["message" => "Variante no encontrada"]);
            return;
        }

        $ownerId = $this->model->getProductOwnerId((int) $variant['product_id']);
        if ($ownerId !== $userId) {
            Router::$response->status(403)->json(["message" => "No tenés permiso sobre esta variante"]);
            return;
        }

        if (empty($_FILES) || !isset($_FILES['model'])) {
            Router::$response->status(400)->json(["message" => "No se subió ningún archivo"]);
            return;
        }

        $file = $_FILES['model'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'glb') {
            Router::$response->status(400)->json(["message" => "Solo se aceptan archivos .glb"]);
            return;
        }

        $uploadService = new FileUploadService();
        $result = $uploadService->uploadProductFile($file, $userId);

        if (!$result['success']) {
            Router::$response->status(400)->json(["message" => $result['message']]);
            return;
        }

        $ok = $this->model->setGlbUrl($variantId, $result['file_url']);
        if (!$ok) {
            Router::$response->status(500)->json(["message" => "Error al guardar el modelo 3D"]);
            return;
        }

        Router::$response->status(200)->json(["message" => "Modelo 3D subido correctamente", "url" => $result['file_url']]);
    }

    // Desactiva una variante (borrado logico, preserva historial de pedidos)
    public function deleteVariant($id)
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $variantId = (int) $id;
        $variant = $this->model->getVariantById($variantId);
        if (!$variant) {
            Router::$response->status(404)->json(["message" => "Variante no encontrada"]);
            return;
        }

        $ownerId = $this->model->getProductOwnerId((int) $variant['product_id']);
        if ($ownerId !== $userId) {
            Router::$response->status(403)->json(["message" => "No tenés permiso sobre esta variante"]);
            return;
        }

        $ok = $this->model->deactivateVariant($variantId);
        if (!$ok) {
            Router::$response->status(500)->json(["message" => "Error al eliminar la variante"]);
            return;
        }

        Router::$response->status(200)->json(["message" => "Variante eliminada correctamente"]);
    }
}
