<?php
require_once __DIR__.'/vendor/autoload.php';

use App\Routers\UsersRouter;
use App\Routers\ProfileRouter;
use App\Routers\SignalRouter; // <--- importar tu router nuevo
use App\Routers\ChatRouter; // <--- importar tu router nuevo
use EasyProjects\SimpleRouter\Router;

$router = new Router();
ini_set('display_errors', 0);        // No mostrar errores en pantalla
ini_set('log_errors', 1);            // Guardar errores en log
ini_set('error_log', __DIR__ . '/php-error.log'); // Ruta del log
error_reporting(E_ALL);

// CORS: permitimos el frontend de React
$router->cors()->setAllowedOrigins("http://localhost:3000", "localhost");
$router->cors()->setAllowedMethods("GET", "POST", "PUT", "DELETE", "OPTIONS");
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
// Ejecutamos router
$router->start();
