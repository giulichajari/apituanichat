<?php
// unified-server.php - UN SOLO PROCESO, MULTIPLES PUERTOS

require __DIR__ . '/vendor/autoload.php';

use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\Server\IoServer;
use React\EventLoop\Factory as LoopFactory;

echo "========================================\n";
echo "🚀 UNIFIED SERVER - Un proceso, múltiples puertos\n";
echo "========================================\n\n";

try {
    // 1. Cargar ambas clases
    require_once __DIR__ . '/SignalServer.php'; // Tu clase SignalServer
    require_once __DIR__ . '/AudioCallServer.php'; // Tu clase AudioCallServer
    
    // 2. Crear loop de eventos
    $loop = LoopFactory::create();
    
    // 3. Servidor de Chat (puerto 9090)
    echo "1️⃣ Configurando Chat Server (puerto 9090)...\n";
    $chatWebSock = new \React\Socket\Server('0.0.0.0:9090', $loop);
    $chatWsServer = new WsServer(new \SignalServer()); // Tu clase existente
    $chatHttpServer = new HttpServer($chatWsServer);
    $chatServer = new IoServer($chatHttpServer, $chatWebSock, $loop);
    echo "✅ Chat Server listo\n";
    
    // 4. Servidor de Audio (puerto 9095)
    echo "\n2️⃣ Configurando Audio Server (puerto 9095)...\n";
    $audioWebSock = new \React\Socket\Server('0.0.0.0:9095', $loop);
    $audioWsServer = new WsServer(new \AudioCallApp\AudioCallServer());
    $audioHttpServer = new HttpServer($audioWsServer);
    $audioServer = new IoServer($audioHttpServer, $audioWebSock, $loop);
    echo "✅ Audio Server listo\n";
    
    // 5. Configurar manejo de señales
    $loop->addSignal(SIGINT, function () use ($loop) {
        echo "\n\n🛑 Recibida señal SIGINT (Ctrl+C)\n";
        echo "👋 Apagando servidores...\n";
        $loop->stop();
        exit(0);
    });
    
    // 6. Timer para mostrar estado
    $loop->addPeriodicTimer(30, function () {
        echo "⏰ [" . date('H:i:s') . "] Servidores activos\n";
        echo "   Memoria: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";
    });
    
    echo "\n========================================\n";
    echo "🟢 SERVIDORES UNIFICADOS INICIADOS\n";
    echo "========================================\n";
    echo "💬 Chat Server: ws://0.0.0.0:9090\n";
    echo "🎧 Audio Server: ws://0.0.0.0:9095\n";
    echo "⏰ Iniciado: " . date('Y-m-d H:i:s') . "\n";
    echo "========================================\n\n";
    echo "📋 Para detener: Ctrl+C\n";
    echo "========================================\n";
    
    // 7. Iniciar loop (esto bloquea)
    $loop->run();
    
} catch (\Exception $e) {
    echo "\n❌❌❌ ERROR CRÍTICO ❌❌❌\n";
    echo "Mensaje: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . "\n";
    echo "Línea: " . $e->getLine() . "\n";
    exit(1);
}