<?php
// master-server.php

require __DIR__ . '/vendor/autoload.php';

echo "========================================\n";
echo "🎮 MASTER SERVER - Control de múltiples servidores\n";
echo "========================================\n\n";

// Servidor 1: Chat y señalización (puerto 9090)
echo "1️⃣ Iniciando Chat Server (puerto 9090)...\n";
$chatProcess = popen('php ws-server.php', 'r');
if ($chatProcess) {
    echo "✅ Chat Server iniciado\n";
} else {
    echo "❌ Error al iniciar Chat Server\n";
}

sleep(2);

// Servidor 2: Audio puro (puerto 9095)
echo "\n2️⃣ Iniciando Audio Server (puerto 9095)...\n";

use AudioCallApp\AudioCallServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\Http\HttpServer;

$audioServer = IoServer::factory(
    new HttpServer(
        new WsServer(
            new AudioCallServer()
        )
    ),
    9095
);

echo "✅ Audio Server con TURN iniciado en puerto 9095\n";

echo "\n========================================\n";
echo "🟢 AMBOS SERVIDORES EN EJECUCIÓN\n";
echo "========================================\n";
echo "📡 Chat & Signaling: ws://0.0.0.0:9090\n";
echo "🎧 Audio & TURN: ws://0.0.0.0:9095\n";
echo "⏰ Iniciado: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

// Mantener ambos servidores corriendo
$audioServer->run();