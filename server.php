<?php

require __DIR__ . '/vendor/autoload.php';

use AudioCallApp\AudioCallServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\Http\HttpServer;

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new AudioCallServer()
        )
    ),
    9095
);

echo "Servidor WebSocket iniciado en el puerto 9095\n";
$server->run();