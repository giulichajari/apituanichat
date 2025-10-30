<?php

namespace App\Controllers;

use App\Models\RestaurantModel;
use App\Models\DishModel;
use EasyProjects\SimpleRouter\Router;
use Exception;
class RestaurantController
{
    private RestaurantModel $restaurantModel;
    private DishModel $dishModel;

    public function __construct()
    {
        $this->restaurantModel = new RestaurantModel();
        $this->dishModel = new DishModel();
    }

    // =============================================
    // MÉTODOS DE SUBIDA DE ARCHIVOS
    // =============================================

    public function uploadFile()
    {
        ini_set('log_errors', 1);
        ini_set('error_log', __DIR__ . '/../../php-error.log');
        try {
            // Verificar que se haya subido un archivo
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                Router::$response->status(400)->json([
                    'success' => false,
                    'message' => 'No se subió ningún archivo o hubo un error en la subida'
                ]);
                return;
            }

            $uploadedFile = $_FILES['file'];

            // Validar tipo de archivo
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $fileType = mime_content_type($uploadedFile['tmp_name']);

            if (!in_array($fileType, $allowedTypes)) {
                Router::$response->status(400)->json([
                    'success' => false,
                    'message' => 'Tipo de archivo no permitido. Solo se permiten imágenes JPEG, PNG, GIF y WebP'
                ]);
                return;
            }

            // Validar tamaño (máximo 5MB)
            $maxSize = 5 * 1024 * 1024; // 5MB
            if ($uploadedFile['size'] > $maxSize) {
                Router::$response->status(400)->json([
                    'success' => false,
                    'message' => 'El archivo es demasiado grande. Tamaño máximo: 5MB'
                ]);
                return;
            }

            // Crear directorio de uploads si no existe
            $uploadDir = __DIR__ . '/../../public/uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generar nombre único para el archivo
            $fileExtension = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
            $fileName = uniqid() . '_' . time() . '.' . $fileExtension;
            $filePath = $uploadDir . $fileName;

            // Mover el archivo subido
            // Mover el archivo subido con manejo de errores detallado
            try {
                $result = move_uploaded_file($uploadedFile['tmp_name'], $filePath);

                if (!$result) {
                    // Obtener último error de PHP
                    $lastError = error_get_last();

                    // Verificar permisos y existencia
                    $checks = [
                        'tmp_exists' => file_exists($uploadedFile['tmp_name']),
                        'tmp_readable' => is_readable($uploadedFile['tmp_name']),
                        'dir_exists' => is_dir($uploadDir),
                        'dir_writable' => is_writable($uploadDir),
                        'disk_space' => disk_free_space($uploadDir)
                    ];

                    error_log("move_uploaded_file failed - Checks: " . print_r($checks, true));
                    error_log("Last error: " . print_r($lastError, true));

                    throw new Exception(
                        "No se pudo mover el archivo. " .
                            ($lastError ? $lastError['message'] : 'Error desconocido')
                    );
                }
            } catch (\Exception $e) {
                Router::$response->status(500)->json([
                    'success' => false,
                    'message' => 'Error al guardar el archivo en el servidor',
                    'error' => $e->getMessage(),
                    'debug_info' => [
                        'tmp_file' => $uploadedFile['tmp_name'],
                        'destination' => $filePath,
                        'file_size' => $uploadedFile['size']
                    ]
                ]);
                return;
            }

            // URL accesible del archivo
            $baseUrl = $this->getBaseUrl();
            $fileUrl = $baseUrl . '/uploads/' . $fileName;

            Router::$response->status(200)->json([
                'success' => true,
                'message' => 'Archivo subido correctamente',
                'url' => $fileUrl,
                'filename' => $fileName,
                'path' => '/uploads/' . $fileName,
                'size' => $uploadedFile['size'],
                'type' => $fileType
            ]);
        } catch (\Exception $e) {
            error_log("Error en uploadFile: " . $e->getMessage());
            Router::$response->status(500)->json([
                'success' => false,
                'message' => 'Error interno del servidor al subir el archivo'
            ]);
        }
    }

    /**
     * Obtener la URL base del servidor
     */
    private function getBaseUrl()
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        return $protocol . "://" . $host;
    }

    /**
     * Actualizar imagen de plato existente
     */
    public function updateRestaurantDishImage($restaurantId, $dishId)
    {
        try {
            // Verificar que se haya subido un archivo
            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                Router::$response->status(400)->json([
                    'success' => false,
                    'message' => 'No se subió ninguna imagen del plato'
                ]);
                return;
            }

            $uploadedFile = $_FILES['image'];

            // Validaciones del archivo
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $fileType = mime_content_type($uploadedFile['tmp_name']);

            if (!in_array($fileType, $allowedTypes)) {
                Router::$response->status(400)->json([
                    'success' => false,
                    'message' => 'Tipo de archivo no permitido'
                ]);
                return;
            }

            $maxSize = 5 * 1024 * 1024;
            if ($uploadedFile['size'] > $maxSize) {
                Router::$response->status(400)->json([
                    'success' => false,
                    'message' => 'El archivo es demasiado grande. Tamaño máximo: 5MB'
                ]);
                return;
            }

            // Verificar que el plato existe y pertenece al usuario
            $dish = $this->dishModel->getDish($restaurantId, $dishId);
            if (!$dish) {
                Router::$response->status(404)->json([
                    'success' => false,
                    'message' => 'Plato no encontrado'
                ]);
                return;
            }

            $restaurant = $this->restaurantModel->getRestaurantById($restaurantId);
            $userId = Router::$request->user->id ?? null;
            if ($restaurant['user_id'] != $userId) {
                Router::$response->status(403)->json([
                    'success' => false,
                    'message' => 'No tienes permisos para editar este plato'
                ]);
                return;
            }

            // Crear directorio para imágenes de platos
            $uploadDir = __DIR__ . '/../../public/uploads/dishes/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generar nombre único
            $fileExtension = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
            $fileName = 'dish_' . $dishId . '_' . uniqid() . '.' . $fileExtension;
            $filePath = $uploadDir . $fileName;

            // Mover el archivo
            if (!move_uploaded_file($uploadedFile['tmp_name'], $filePath)) {
                Router::$response->status(500)->json([
                    'success' => false,
                    'message' => 'Error al guardar la imagen en el servidor'
                ]);
                return;
            }

            // URL de la imagen
            $baseUrl = $this->getBaseUrl();
            $imageUrl = $baseUrl . '/uploads/dishes/' . $fileName;

            // Obtener imágenes actuales y agregar la nueva
            $currentImages = [];
            if (!empty($dish['imagenes'])) {
                $currentImages = json_decode($dish['imagenes'], true) ?? [];
            }
            $currentImages[] = $imageUrl;

            // Actualizar en la base de datos
            $success = $this->dishModel->updateDish($dishId, [
                'imagenes' => json_encode($currentImages)
            ]);

            if ($success) {
                Router::$response->status(200)->json([
                    'success' => true,
                    'message' => 'Imagen del plato actualizada correctamente',
                    'data' => [
                        'imagenes' => $currentImages,
                        'nueva_imagen' => $imageUrl
                    ]
                ]);
            } else {
                Router::$response->status(500)->json([
                    'success' => false,
                    'message' => 'Error al guardar la imagen en la base de datos'
                ]);
            }
        } catch (\Exception $e) {
            error_log("Error en updateRestaurantDishImage: " . $e->getMessage());
            Router::$response->status(500)->json([
                'success' => false,
                'message' => 'Error al actualizar la imagen del plato'
            ]);
        }
    }

    // =============================================
    // MÉTODOS DE PLATOS (DISHES) - FALTANTES
    // =============================================

    public function updateRestaurantDish($restaurantId, $dishId)
    {
        $restaurantId = (int)$restaurantId;
        $dishId = (int)$dishId;

        if ($restaurantId <= 0 || $dishId <= 0) {
            Router::$response->status(400)->json([
                "message" => "IDs no válidos"
            ]);
            return;
        }

        $rawInput = file_get_contents("php://input");
        $data = json_decode($rawInput, true);

        $existingDish = $this->dishModel->getDish($restaurantId, $dishId);
        if (!$existingDish) {
            Router::$response->status(404)->json([
                "message" => "Plato no encontrado"
            ]);
            return;
        }

        $restaurant = $this->restaurantModel->getRestaurantById($restaurantId);
        $userId = Router::$request->user->id ?? null;
        if ($restaurant['user_id'] != $userId) {
            Router::$response->status(403)->json([
                "message" => "No tienes permisos para editar platos de este restaurante"
            ]);
            return;
        }

        $success = $this->dishModel->updateDish($dishId, $data);

        if ($success) {
            Router::$response->status(200)->json([
                "message" => "Plato actualizado correctamente ✅",
                "data" => $this->dishModel->getDish($restaurantId, $dishId)
            ]);
        } else {
            Router::$response->status(500)->json([
                "message" => "Error al actualizar el plato"
            ]);
        }
    }


    public function getRestaurant($id)
    {
        $restaurantId = (int)$id;

        if ($restaurantId <= 0) {
            Router::$response->status(400)->json([
                "message" => "ID de restaurante no válido"
            ]);
            return;
        }

        $restaurant = $this->restaurantModel->getRestaurantById($restaurantId);

        if ($restaurant) {
            Router::$response->status(200)->json([
                "data" => $restaurant,
                "message" => "Restaurante obtenido correctamente"
            ]);
        } else {
            Router::$response->status(404)->json([
                "message" => "Restaurante no encontrado"
            ]);
        }
    }

    public function getRestaurantsByOwner()
    {
        $userId = Router::$request->user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->json([
                "message" => "Usuario no autenticado"
            ]);
            return;
        }

        $restaurants = $this->restaurantModel->getRestaurantsByOwner($userId);

        Router::$response->status(200)->json([
            "data" => $restaurants,
            "message" => "Restaurantes del usuario obtenidos correctamente"
        ]);
    }

    public function listRestaurants()
    {
        $restaurants = $this->restaurantModel->getAllRestaurants();

        Router::$response->status(200)->json([
            "data" => $restaurants,
            "message" => "Restaurantes obtenidos correctamente"
        ]);
    }

    public function createRestaurant()
    {
        $data = [
            'nombre' => Router::$request->body->nombre ?? '',
            'ubicacion' => Router::$request->body->ubicacion ?? '',
            'tipo_comida' => Router::$request->body->tipo_comida ?? '',
            'descripcion' => Router::$request->body->descripcion ?? '',
            'telefono' => Router::$request->body->telefono ?? '',
            'email' => Router::$request->body->email ?? '',
            'horario_apertura' => Router::$request->body->horario_apertura ?? null,
            'horario_cierre' => Router::$request->body->horario_cierre ?? null,
            'precio_promedio' => Router::$request->body->precio_promedio ?? null,
            'capacidad' => Router::$request->body->capacidad ?? null,
            'mascotas_permitidas' => Router::$request->body->mascotas_permitidas ?? false,
            'estacionamiento' => Router::$request->body->estacionamiento ?? false,
            'wifi_gratis' => Router::$request->body->wifi_gratis ?? false,
            'user_id' => Router::$request->user->id ?? null
        ];

        if (empty($data['nombre']) || empty($data['ubicacion']) || empty($data['tipo_comida'])) {
            Router::$response->status(400)->json([
                "message" => "Nombre, ubicación y tipo de comida son obligatorios"
            ]);
            return;
        }

        $restaurantId = $this->restaurantModel->createRestaurant($data);

        if ($restaurantId) {
            Router::$response->status(201)->json([
                "message" => "Restaurante creado exitosamente",
                "data" => [
                    "id" => $restaurantId,
                    ...$data
                ]
            ]);
        } else {
            Router::$response->status(500)->json([
                "message" => "Error al crear el restaurante"
            ]);
        }
    }

    public function updateRestaurant($id)
    {
        $restaurantId = (int)$id;

        if ($restaurantId <= 0) {
            Router::$response->status(400)->json([
                "message" => "ID de restaurante no válido"
            ]);
            return;
        }

        $rawInput = file_get_contents("php://input");
        $data = json_decode($rawInput, true);

        $existingRestaurant = $this->restaurantModel->getRestaurantById($restaurantId);
        if (!$existingRestaurant) {
            Router::$response->status(404)->json([
                "message" => "Restaurante no encontrado"
            ]);
            return;
        }

        $userId = Router::$request->user->id ?? null;
        if ($existingRestaurant['user_id'] != $userId) {
            Router::$response->status(403)->json([
                "message" => "No tienes permisos para editar este restaurante"
            ]);
            return;
        }

        $success = $this->restaurantModel->updateRestaurant($restaurantId, $data);

        if ($success) {
            Router::$response->json([
                "message" => "Restaurante actualizado correctamente ✅",
                "data" => $this->restaurantModel->getRestaurantById($restaurantId)
            ]);
        } else {
            Router::$response->status(500)->json([
                "message" => "Error al actualizar el restaurante"
            ]);
        }
    }

    public function partialUpdateRestaurant($id)
    {
        $restaurantId = (int)$id;

        if ($restaurantId <= 0) {
            Router::$response->status(400)->json([
                "message" => "ID de restaurante no válido"
            ]);
            return;
        }

        $rawInput = file_get_contents("php://input");
        $data = json_decode($rawInput, true);

        $existingRestaurant = $this->restaurantModel->getRestaurantById($restaurantId);
        if (!$existingRestaurant) {
            Router::$response->status(404)->json([
                "message" => "Restaurante no encontrado"
            ]);
            return;
        }

        $userId = Router::$request->user->id ?? null;
        if ($existingRestaurant['user_id'] != $userId) {
            Router::$response->status(403)->json([
                "message" => "No tienes permisos para editar este restaurante"
            ]);
            return;
        }

        $success = $this->restaurantModel->updateRestaurant($restaurantId, $data);

        if ($success) {
            Router::$response->json([
                "message" => "Restaurante actualizado parcialmente ✅",
                "data" => $this->restaurantModel->getRestaurantById($restaurantId)
            ]);
        } else {
            Router::$response->status(500)->json([
                "message" => "Error al actualizar el restaurante"
            ]);
        }
    }

    public function deleteRestaurant($id)
    {
        $restaurantId = (int)$id;

        if ($restaurantId <= 0) {
            Router::$response->status(400)->json([
                "message" => "ID de restaurante no válido"
            ]);
            return;
        }

        $existingRestaurant = $this->restaurantModel->getRestaurantById($restaurantId);
        if (!$existingRestaurant) {
            Router::$response->status(404)->json([
                "message" => "Restaurante no encontrado"
            ]);
            return;
        }

        $userId = Router::$request->user->id ?? null;
        if ($existingRestaurant['user_id'] != $userId) {
            Router::$response->status(403)->json([
                "message" => "No tienes permisos para eliminar este restaurante"
            ]);
            return;
        }

        $success = $this->restaurantModel->deleteRestaurant($restaurantId);

        if ($success) {
            Router::$response->json([
                "message" => "Restaurante eliminado correctamente"
            ]);
        } else {
            Router::$response->status(500)->json([
                "message" => "Error al eliminar el restaurante"
            ]);
        }
    }

    public function updateCoverImage($id)
    {
        if (!isset($_FILES['cover_image'])) {
            Router::$response->status(400)->json([
                "message" => "No se subió ninguna imagen de portada"
            ]);
            return;
        }

        $existingRestaurant = $this->restaurantModel->getRestaurantById($id);
        if (!$existingRestaurant) {
            Router::$response->status(404)->json([
                "message" => "Restaurante no encontrado"
            ]);
            return;
        }

        $userId = Router::$request->user->id ?? null;
        if ($existingRestaurant['user_id'] != $userId) {
            Router::$response->status(403)->json([
                "message" => "No tienes permisos para editar este restaurante"
            ]);
            return;
        }

        $file = $_FILES['cover_image'];
        $targetDir = realpath(__DIR__ . '/../../uploads/restaurants/cover') . DIRECTORY_SEPARATOR;

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $filename = uniqid() . "_" . basename($file['name']);
        $targetFile = $targetDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $targetFile)) {
            $imagePath = "/uploads/restaurants/cover/" . $filename;

            if ($this->restaurantModel->updateCoverImage($id, $imagePath)) {
                Router::$response->status(200)->json([
                    "message" => "Imagen de portada actualizada correctamente",
                    "foto_portada" => $imagePath
                ]);
            } else {
                Router::$response->status(500)->json([
                    "message" => "Error al guardar la ruta de la imagen en la base de datos"
                ]);
            }
        } else {
            Router::$response->status(500)->json([
                "message" => "Error al subir la imagen"
            ]);
        }
    }

    public function getFavoriteRestaurants()
    {
        $userId = Router::$request->user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->json([
                "message" => "Usuario no autenticado"
            ]);
            return;
        }

        $favorites = $this->restaurantModel->getUserFavorites($userId);

        Router::$response->status(200)->json([
            "data" => $favorites,
            "message" => "Restaurantes favoritos obtenidos correctamente"
        ]);
    }

    public function toggleFavorite($id)
    {
        $userId = Router::$request->user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->json([
                "message" => "Usuario no autenticado"
            ]);
            return;
        }

        $result = $this->restaurantModel->toggleFavorite($userId, $id);

        Router::$response->status(200)->json([
            "message" => $result['added'] ? "Restaurante agregado a favoritos ❤️" : "Restaurante eliminado de favoritos 💔",
            "data" => $result
        ]);
    }

    // =============================================
    // MÉTODOS DE PLATOS (DISHES) - NUEVOS
    // =============================================

    public function getRestaurantDishes($restaurantId)
    {
        $restaurantId = (int)$restaurantId;

        if ($restaurantId <= 0) {
            Router::$response->status(400)->json([
                "message" => "ID de restaurante no válido"
            ]);
            return;
        }

        // Verificar que el restaurante existe
        $restaurant = $this->restaurantModel->getRestaurantById($restaurantId);
        if (!$restaurant) {
            Router::$response->status(404)->json([
                "message" => "Restaurante no encontrado"
            ]);
            return;
        }

        $dishes = $this->dishModel->getDishesByRestaurant($restaurantId);

        Router::$response->status(200)->json([
            "data" => $dishes,
            "message" => "Platos del restaurante obtenidos correctamente"
        ]);
    }

    public function getRestaurantDish($restaurantId, $dishId)
    {
        $restaurantId = (int)$restaurantId;
        $dishId = (int)$dishId;

        if ($restaurantId <= 0 || $dishId <= 0) {
            Router::$response->status(400)->json([
                "message" => "IDs no válidos"
            ]);
            return;
        }

        $dish = $this->dishModel->getDish($restaurantId, $dishId);

        if ($dish) {
            Router::$response->status(200)->json([
                "data" => $dish,
                "message" => "Plato obtenido correctamente"
            ]);
        } else {
            Router::$response->status(404)->json([
                "message" => "Plato no encontrado"
            ]);
        }
    }

    public function createRestaurantDish($restaurantId)
    {
        $restaurantId = (int)$restaurantId;

        if ($restaurantId <= 0) {
            Router::$response->status(400)->json([
                "message" => "ID de restaurante no válido"
            ]);
            return;
        }

        // Verificar que el restaurante existe y pertenece al usuario
        $restaurant = $this->restaurantModel->getRestaurantById($restaurantId);
        if (!$restaurant) {
            Router::$response->status(404)->json([
                "message" => "Restaurante no encontrado"
            ]);
            return;
        }

        $userId = Router::$request->user->id ?? null;
        if ($restaurant['user_id'] != $userId) {
            Router::$response->status(403)->json([
                "message" => "No tienes permisos para agregar platos a este restaurante"
            ]);
            return;
        }

        $data = [
            'restaurant_id' => $restaurantId,
            'nombre' => Router::$request->body->nombre ?? '',
            'descripcion' => Router::$request->body->descripcion ?? '',
            'precio' => Router::$request->body->precio ?? null,
            'categoria' => Router::$request->body->categoria ?? '',
            'ingredientes' => Router::$request->body->ingredientes ?? '',
            'disponible' => Router::$request->body->disponible ?? true,
            'es_vegano' => Router::$request->body->es_vegano ?? false,
            'es_vegetariano' => Router::$request->body->es_vegetariano ?? false,
            'sin_gluten' => Router::$request->body->sin_gluten ?? false,
            'calorias' => Router::$request->body->calorias ?? null,
            'tiempo_preparacion' => Router::$request->body->tiempo_preparacion ?? null,
            'imagenes' => Router::$request->body->imagenes ?? null
        ];

        // Validaciones básicas
        if (empty($data['nombre']) || empty($data['categoria'])) {
            Router::$response->status(400)->json([
                "message" => "Nombre y categoría son obligatorios"
            ]);
            return;
        }

        if ($data['precio'] !== null && $data['precio'] < 0) {
            Router::$response->status(400)->json([
                "message" => "El precio no puede ser negativo"
            ]);
            return;
        }

        $dishId = $this->dishModel->createDish($data);

        if ($dishId) {
            Router::$response->status(201)->json([
                "message" => "Plato creado exitosamente",
                "data" => [
                    "id" => $dishId,
                    ...$data
                ]
            ]);
        } else {
            Router::$response->status(500)->json([
                "message" => "Error al crear el plato"
            ]);
        }
    }



    public function partialUpdateRestaurantDish($restaurantId, $dishId)
    {
        $restaurantId = (int)$restaurantId;
        $dishId = (int)$dishId;

        if ($restaurantId <= 0 || $dishId <= 0) {
            Router::$response->status(400)->json([
                "message" => "IDs no válidos"
            ]);
            return;
        }

        $rawInput = file_get_contents("php://input");
        $data = json_decode($rawInput, true);

        $existingDish = $this->dishModel->getDish($restaurantId, $dishId);
        if (!$existingDish) {
            Router::$response->status(404)->json([
                "message" => "Plato no encontrado"
            ]);
            return;
        }

        $restaurant = $this->restaurantModel->getRestaurantById($restaurantId);
        $userId = Router::$request->user->id ?? null;
        if ($restaurant['user_id'] != $userId) {
            Router::$response->status(403)->json([
                "message" => "No tienes permisos para editar platos de este restaurante"
            ]);
            return;
        }

        $success = $this->dishModel->updateDish($dishId, $data);

        if ($success) {
            Router::$response->json([
                "message" => "Plato actualizado parcialmente ✅",
                "data" => $this->dishModel->getDish($restaurantId, $dishId)
            ]);
        } else {
            Router::$response->status(500)->json([
                "message" => "Error al actualizar el plato"
            ]);
        }
    }

    public function deleteRestaurantDish($restaurantId, $dishId)
    {
        $restaurantId = (int)$restaurantId;
        $dishId = (int)$dishId;

        if ($restaurantId <= 0 || $dishId <= 0) {
            Router::$response->status(400)->json([
                "message" => "IDs no válidos"
            ]);
            return;
        }

        $existingDish = $this->dishModel->getDish($restaurantId, $dishId);
        if (!$existingDish) {
            Router::$response->status(404)->json([
                "message" => "Plato no encontrado"
            ]);
            return;
        }

        $restaurant = $this->restaurantModel->getRestaurantById($restaurantId);
        $userId = Router::$request->user->id ?? null;
        if ($restaurant['user_id'] != $userId) {
            Router::$response->status(403)->json([
                "message" => "No tienes permisos para eliminar platos de este restaurante"
            ]);
            return;
        }

        $success = $this->dishModel->deleteDish($dishId);

        if ($success) {
            Router::$response->json([
                "message" => "Plato eliminado correctamente"
            ]);
        } else {
            Router::$response->status(500)->json([
                "message" => "Error al eliminar el plato"
            ]);
        }
    }




    public function getRestaurantDishesByCategory($restaurantId, $category)
    {
        $restaurantId = (int)$restaurantId;

        if ($restaurantId <= 0) {
            Router::$response->status(400)->json([
                "message" => "ID de restaurante no válido"
            ]);
            return;
        }

        $restaurant = $this->restaurantModel->getRestaurantById($restaurantId);
        if (!$restaurant) {
            Router::$response->status(404)->json([
                "message" => "Restaurante no encontrado"
            ]);
            return;
        }

        $dishes = $this->dishModel->getDishesByCategory($restaurantId, $category);

        Router::$response->status(200)->json([
            "data" => $dishes,
            "message" => "Platos por categoría obtenidos correctamente"
        ]);
    }
}
