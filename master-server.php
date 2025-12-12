<?php
// unified-server-fixed.php - VERSIÓN CORREGIDA CON TURN

// ===================== CONFIGURACIÓN DEBUG =====================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/unified-error.log');

echo "========================================\n";
echo "🚀 UNIFIED SERVER - Con TURN configurado\n";
echo "========================================\n\n";

try {
    // 1. Cargar autoload PRIMERO
    require __DIR__ . '/vendor/autoload.php';
    echo "✅ Vendor autoload cargado\n";
    
    // 2. Cargar clases específicas
    $signalServerPath = __DIR__ . '/ws-server.php';
    if (file_exists($signalServerPath)) {
        // Extraer la clase SignalServer del archivo
        require_once $signalServerPath;
        echo "✅ SignalServer cargada\n";
    } else {
        die("❌ Error: ws-server.php no encontrado en: $signalServerPath\n");
    }
    
    // 3. MODIFICAR AudioCallServer para incluir TURN
    class AudioCallServerWithTurn implements \Ratchet\MessageComponentInterface
    {
        protected $clients;
        private $turnConfig;
        
        public function __construct()
        {
            $this->clients = new \SplObjectStorage();
            
            // 🔥 CONFIGURACIÓN TURN - ¡ESTO ES LO QUE NECESITAS!
            $this->turnConfig = [
                'server' => 'tuanichat.com',
                'port' => 3478,
                'tls_port' => 5349,
                'username' => 'webrtcuser',
                'password' => 'ClaveSuperSegura123',
                'enabled' => true
            ];
            
            echo "🎧 AudioCallServer CON TURN inicializado\n";
            echo "   TURN Server: " . $this->turnConfig['server'] . ":" . $this->turnConfig['port'] . "\n";
        }
        
        public function onOpen(\Ratchet\ConnectionInterface $conn)
        {
            $this->clients->attach($conn);
            echo date('H:i:s') . " 🔊 Conexión audio #{$conn->resourceId} abierta\n";
            
            // 🔥 ENVIAR CONFIGURACIÓN TURN INMEDIATAMENTE
            $this->sendTurnConfig($conn);
            
            $conn->send(json_encode([
                'type' => 'audio_server_ready',
                'turn_enabled' => $this->turnConfig['enabled'],
                'connection_id' => $conn->resourceId,
                'server_time' => date('Y-m-d H:i:s')
            ]));
        }
        
        private function sendTurnConfig(\Ratchet\ConnectionInterface $conn)
        {
            if (!$this->turnConfig['enabled']) return;
            
            $config = [
                'type' => 'turn_config',
                'turn_servers' => [
                    [
                        'urls' => [
                            'turn:' . $this->turnConfig['server'] . ':' . $this->turnConfig['port'] . '?transport=udp',
                            'turn:' . $this->turnConfig['server'] . ':' . $this->turnConfig['port'] . '?transport=tcp',
                            'turns:' . $this->turnConfig['server'] . ':' . $this->turnConfig['tls_port'] . '?transport=tcp'
                        ],
                        'username' => $this->turnConfig['username'],
                        'credential' => $this->turnConfig['password']
                    ]
                ],
                'stun_servers' => [
                    ['urls' => 'stun:stun.l.google.com:19302'],
                    ['urls' => 'stun:stun1.l.google.com:19302']
                ],
                'ice_transport_policy' => 'all',
                'timestamp' => time(),
                'server' => $this->turnConfig['server'],
                'config_source' => 'audio_server'
            ];
            
            $conn->send(json_encode($config));
            echo "📤 Configuración TURN enviada a conexión #{$conn->resourceId}\n";
        }
        
        public function onMessage(\Ratchet\ConnectionInterface $from, $msg)
        {
            echo date('H:i:s') . " 📨 Audio #{$from->resourceId} → " . substr($msg, 0, 100) . "\n";
            
            try {
                if (is_string($msg) && $this->isJson($msg)) {
                    $data = json_decode($msg, true);
                    
                    switch ($data['type'] ?? '') {
                        case 'get_turn_config':
                            $this->sendTurnConfig($from);
                            break;
                            
                        case 'ping':
                            $from->send(json_encode(['type' => 'pong', 'time' => time()]));
                            break;
                            
                        case 'offer':
                        case 'answer':
                        case 'candidate':
                            $this->relayWebRTCMessage($from, $data);
                            break;
                            
                        case 'call_request':
                            $this->handleCallRequest($from, $data);
                            break;
                            
                        default:
                            // Relay binario o desconocido
                            $this->relayToOthers($from, $msg);
                    }
                } else {
                    // Audio binario
                    $this->relayToOthers($from, $msg);
                }
            } catch (\Exception $e) {
                echo "❌ Error procesando mensaje: {$e->getMessage()}\n";
            }
        }
        
        private function handleCallRequest($from, $data)
        {
            $toUserId = $data['to'] ?? null;
            
            if (!$toUserId) return;
            
            // Buscar conexión del destinatario
            foreach ($this->clients as $client) {
                if ($client !== $from && isset($client->userId) && $client->userId == $toUserId) {
                    $client->send(json_encode($data));
                    echo "📞 Call request reenviado a usuario {$toUserId}\n";
                    return;
                }
            }
            
            // Destinatario no encontrado
            $from->send(json_encode([
                'type' => 'user_offline',
                'to' => $toUserId,
                'message' => 'Usuario no disponible'
            ]));
        }
        
        private function relayWebRTCMessage($from, $data)
        {
            foreach ($this->clients as $client) {
                if ($from !== $client) {
                    $client->send(json_encode($data));
                }
            }
        }
        
        private function relayToOthers($from, $msg)
        {
            foreach ($this->clients as $client) {
                if ($from !== $client) {
                    $client->send($msg);
                }
            }
        }
        
        public function onClose(\Ratchet\ConnectionInterface $conn)
        {
            $this->clients->detach($conn);
            echo date('H:i:s') . " ❌ Conexión audio #{$conn->resourceId} cerrada\n";
        }
        
        public function onError(\Ratchet\ConnectionInterface $conn, \Exception $e)
        {
            echo date('H:i:s') . " ⚠️ Error audio #{$conn->resourceId}: {$e->getMessage()}\n";
            $conn->close();
        }
        
        private function isJson($string)
        {
            json_decode($string);
            return json_last_error() === JSON_ERROR_NONE;
        }
    }
    
    // 4. Crear loop de eventos
    echo "🔄 Creando loop de eventos...\n";
    $loop = \React\EventLoop\Factory::create();
    
    // 5. Servidor de Chat (puerto 9090)
    echo "\n1️⃣ Iniciando Chat Server (puerto 9090)...\n";
    $chatWebSock = new \React\Socket\Server('0.0.0.0:9090', $loop);
    $chatWsServer = new \Ratchet\WebSocket\WsServer(new \SignalServer());
    $chatHttpServer = new \Ratchet\Http\HttpServer($chatWsServer);
    $chatServer = new \Ratchet\Server\IoServer($chatHttpServer, $chatWebSock, $loop);
    echo "✅ Chat Server listo en ws://0.0.0.0:9090\n";
    
    // 6. Servidor de Audio CON TURN (puerto 9095)
    echo "\n2️⃣ Iniciando Audio Server con TURN (puerto 9095)...\n";
    $audioWebSock = new \React\Socket\Server('0.0.0.0:9095', $loop);
    $audioWsServer = new \Ratchet\WebSocket\WsServer(new AudioCallServerWithTurn());
    $audioHttpServer = new \Ratchet\Http\HttpServer($audioWsServer);
    $audioServer = new \Ratchet\Server\IoServer($audioHttpServer, $audioWebSock, $loop);
    echo "✅ Audio Server listo en ws://0.0.0.0:9095\n";
    echo "   🔥 TURN Configurado: tuanichat.com:3478\n";
    
    // 7. Configurar manejo de señales
    $loop->addSignal(SIGINT, function () use ($loop) {
        echo "\n\n🛑 Recibida señal SIGINT (Ctrl+C)\n";
        echo "👋 Apagando servidores...\n";
        $loop->stop();
        exit(0);
    });
    
    // 8. Timer para mostrar estado
    $loop->addPeriodicTimer(10, function () {
        echo "⏰ [" . date('H:i:s') . "] Servidores activos\n";
    });
    
    echo "\n========================================\n";
    echo "🟢 SERVIDORES INICIADOS CON ÉXITO\n";
    echo "========================================\n";
    echo "💬 Chat Server: ws://0.0.0.0:9090\n";
    echo "🎧 Audio Server: ws://0.0.0.0:9095\n";
    echo "🔥 TURN Server: turn:tuanichat.com:3478\n";
    echo "⏰ Iniciado: " . date('Y-m-d H:i:s') . "\n";
    echo "========================================\n\n";
    echo "📋 Para probar conectividad:\n";
    echo "   curl -v telnet://localhost:9090\n";
    echo "   curl -v telnet://localhost:9095\n";
    echo "   Ctrl+C para detener\n";
    echo "========================================\n";
    
    // 9. Iniciar loop
    $loop->run();
    
} catch (\Throwable $e) {
    echo "\n❌❌❌ ERROR CRÍTICO ❌❌❌\n";
    echo "Mensaje: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . "\n";
    echo "Línea: " . $e->getLine() . "\n";
    echo "Traza:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}