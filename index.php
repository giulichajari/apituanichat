<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Models\RestaurantModel;
use App\Routers\UsersRouter;
use App\Routers\ProfileRouter;
use App\Routers\SignalRouter; // <--- importar tu router nuevo
use App\Routers\ChatRouter; // <--- importar tu router nuevo
use App\Routers\DriversRouter;
use App\Routers\PaymentsRouter;
use App\Routers\RestaurantsRouter;
use App\Routers\ProductsRouter;
use App\Routers\StatusRouter;
use EasyProjects\SimpleRouter\Router;




try {
    $router = new Router();
    ini_set('display_errors', 0);        // No mostrar errores en pantalla
    ini_set('log_errors', 1);            // Guardar errores en log
    ini_set('error_log', __DIR__ . '/php-error.log'); // Ruta del log
    error_reporting(E_ALL);

    // CORS: permitimos el frontend de React
    $router->cors()->setAllowedOrigins("*");


    //  $router->cors()->setAllowedOrigins("http://localhost:3000");


    $router->cors()->setAllowedMethods("GET", "POST", "PUT", "DELETE", "OPTIONS", "PATCH");
    $router->cors()->setAllowedHeaders("Content-Type", "Authorization");

    // Responder preflight OPTIONS
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }

    $router->autoload();
    $router->prepareAssets("./App/Views/Assets");

    // Registramos routers
    new UsersRouter($router);
    new ProfileRouter($router);
    new SignalRouter($router);  // <--- aquí sumamos el signaling
    new ChatRouter($router);
    new DriversRouter($router);
    new PaymentsRouter($router);
    new RestaurantsRouter($router);
    new ProductsRouter($router);
    new StatusRouter($router);
    // Ejecutamos router
    // Cargar variables de entorno desde la raíz del proyecto

    $router->start();
} catch (Exception $e) {
    // Captura cualquier excepción que ocurra
    echo "Ocurrió un error al iniciar el router: " . $e->getMessage();
    // Opcional: registrar el error en un log
    error_log($e->getMessage());
}
