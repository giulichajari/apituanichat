<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/unified-error.log');
    use AudioCallApp\AudioCallServer;

try {
    echo "🚀 UNIFIED SERVER - Con TURN configurado\n";

    require __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/AudioCallServer.php';
    require_once __DIR__ . '/ws-server.php';

    // Puertos: por defecto 9090 / 9095; si 9090 está ocupado, libera el proceso o usa otro puerto:
    //   TUANI_WS_CHAT_PORT=9091 TUANI_WS_AUDIO_PORT=9096 php master-server.php
    $chatPort = (int) (getenv('TUANI_WS_CHAT_PORT') ?: getenv('CHAT_WS_PORT') ?: 9090);
    $audioPort = (int) (getenv('TUANI_WS_AUDIO_PORT') ?: getenv('CHAT_AUDIO_WS_PORT') ?: 9095);
    if ($chatPort < 1 || $chatPort > 65535) {
        $chatPort = 9090;
    }
    if ($audioPort < 1 || $audioPort > 65535) {
        $audioPort = 9095;
    }

    // Crear loop de eventos
    $loop = \React\EventLoop\Factory::create();

    // Chat Server
    $chatWebSock = new \React\Socket\Server("0.0.0.0:{$chatPort}", $loop);
    $chatWsServer = new \Ratchet\WebSocket\WsServer(new \SignalServer());
    $chatHttpServer = new \Ratchet\Http\HttpServer($chatWsServer);
    new \Ratchet\Server\IoServer($chatHttpServer, $chatWebSock, $loop);

    // Audio Server CON TURN
    $audioWebSock = new \React\Socket\Server("0.0.0.0:{$audioPort}", $loop);
    $audioWsServer = new \Ratchet\WebSocket\WsServer(new AudioCallServer());
    $audioHttpServer = new \Ratchet\Http\HttpServer($audioWsServer);
    new \Ratchet\Server\IoServer($audioHttpServer, $audioWebSock, $loop);

    // Señales para cerrar correctamente
    $loop->addSignal(SIGINT, function () use ($loop) {
        echo "\n🛑 Señal SIGINT recibida, apagando servidores...\n";
        $loop->stop();
        exit(0);
    });

    // Timer de estado
    $loop->addPeriodicTimer(10, function () {
        echo "⏰ [" . date('H:i:s') . "] Servidores activos\n";
    });

    echo "💬 Chat Server: ws://0.0.0.0:{$chatPort}\n";
    echo "🎧 Audio Server: ws://0.0.0.0:{$audioPort}\n";
    echo "🔥 TURN Server: turn:tuanichat.com:3478\n";

    $loop->run();

} catch (\Throwable $e) {
    echo "\n❌ ERROR CRÍTICO ❌\n";
    echo "Mensaje: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . "\n";
    echo "Línea: " . $e->getLine() . "\n";
    echo "Traza:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
