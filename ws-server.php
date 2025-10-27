<?php

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

require __DIR__ . '/vendor/autoload.php';

class SignalServer implements MessageComponentInterface
{
    protected $clients;
    protected $sessions;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
        $this->sessions = []; // session_id => array of connections
        echo "WebSocket server started on ws://localhost:8080\n";
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        echo "New connection: {$conn->resourceId}\n";

        // Log en archivo
        file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | Nueva conexión: {$conn->resourceId}\n", FILE_APPEND);
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | Mensaje recibido (raw): " . $msg . "\n", FILE_APPEND);
        
        try {
            // 🔹 PRIMERO validar y decodificar el mensaje
            $data = json_decode($msg, true);
            
            // 🔹 VERIFICAR si el JSON es válido
            if (!$data || !is_array($data)) {
                file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | ERROR: JSON inválido\n", FILE_APPEND);
                return;
            }

            // 🔹 LUEGO verificar los campos requeridos
            if (!isset($data['type'])) {
                file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | ERROR: Campo 'type' no encontrado en: " . json_encode($data) . "\n", FILE_APPEND);
                return;
            }

            // 🔹 Manejar diferentes tipos de mensajes
            switch ($data['type']) {
                case 'auth':
                    $this->handleAuth($from, $data);
                    break;
                    
                case 'join_chat':
                    $this->handleJoinChat($from, $data);
                    break;
                    
                case 'chat_message':
                    $this->handleChatMessage($from, $data);
                    break;
                    
                case 'ride_request':
                    $this->handleRideRequest($from, $data);
                    break;
                    
                case 'ride_accepted':
                    $this->handleRideAccepted($from, $data);
                    break;
                    
                default:
                    file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | Tipo de mensaje desconocido: {$data['type']}\n", FILE_APPEND);
                    break;
            }

        } catch (\Exception $e) {
            file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | ERROR procesando mensaje: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }

    // 🔹 Manejar autenticación
    private function handleAuth(ConnectionInterface $from, $data)
    {
        if (!isset($data['user_id'], $data['token'])) {
            file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | ERROR: Datos de autenticación incompletos\n", FILE_APPEND);
            return;
        }

        $userId = $data['user_id'];
        $from->userId = $userId; // Guardar user_id en la conexión
        
        file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | Usuario {$userId} autenticado en conexión {$from->resourceId}\n", FILE_APPEND);
        
        // Enviar confirmación
        $from->send(json_encode([
            'type' => 'auth_success',
            'user_id' => $userId,
            'message' => 'Autenticación exitosa'
        ]));
    }

    // 🔹 Manejar unión a chat
    private function handleJoinChat(ConnectionInterface $from, $data)
    {
        if (!isset($data['chat_id'], $data['user_id'])) {
            file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | ERROR: Datos de join_chat incompletos\n", FILE_APPEND);
            return;
        }

        $chatId = $data['chat_id'];
        $userId = $data['user_id'];
        
        // Crear sesión si no existe
        if (!isset($this->sessions[$chatId])) {
            $this->sessions[$chatId] = new \SplObjectStorage();
            file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | Sesión de chat {$chatId} creada\n", FILE_APPEND);
        }

        // Agregar cliente si no está en la sesión
        if (!$this->sessions[$chatId]->contains($from)) {
            $this->sessions[$chatId]->attach($from);
            $from->currentChat = $chatId; // Guardar chat actual
            file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | Cliente {$from->resourceId} (usuario {$userId}) agregado al chat {$chatId}\n", FILE_APPEND);
        }

        // Enviar confirmación
        $from->send(json_encode([
            'type' => 'joined_chat',
            'chat_id' => $chatId,
            'user_id' => $userId,
            'message' => 'Unido al chat exitosamente'
        ]));

        // Log del estado de la sesión
        $ids = [];
        foreach ($this->sessions[$chatId] as $client) {
            $ids[] = $client->resourceId . '(user:' . ($client->userId ?? 'unknown') . ')';
        }
        file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | Chat {$chatId} con " . count($ids) . " cliente(s): " . implode(', ', $ids) . "\n", FILE_APPEND);
    }

    // 🔹 Manejar mensajes de chat
    private function handleChatMessage(ConnectionInterface $from, $data)
    {
        if (!isset($data['chat_id'], $data['user_id'], $data['contenido'])) {
            file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | ERROR: Datos de mensaje incompletos\n", FILE_APPEND);
            return;
        }

        $chatId = $data['chat_id'];
        $userId = $data['user_id'];

        // Verificar que el chat existe
        if (!isset($this->sessions[$chatId])) {
            file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | ERROR: Chat {$chatId} no existe\n", FILE_APPEND);
            return;
        }

        // Preparar mensaje para broadcast
        $messageData = [
            'type' => 'message',
            'chat_id' => $chatId,
            'user_id' => $userId,
            'contenido' => $data['contenido'],
            'tipo' => $data['tipo'] ?? 'texto',
            'message_id' => $data['message_id'] ?? null,
            'timestamp' => $data['timestamp'] ?? date('c')
        ];

        // Enviar a todos los clientes en el chat (excepto al remitente)
        $sentCount = 0;
        foreach ($this->sessions[$chatId] as $client) {
            if ($client !== $from) {
                $client->send(json_encode($messageData));
                $sentCount++;
            }
        }

        file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | Mensaje de {$userId} en chat {$chatId} enviado a {$sentCount} cliente(s)\n", FILE_APPEND);
    }

    // 🔹 Manejar solicitudes de viaje
    private function handleRideRequest(ConnectionInterface $from, $data)
    {
        if (!isset($data['chat_id'], $data['ride_id'], $data['pickup'], $data['destination'], $data['fare'])) {
            file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | ERROR: Datos de ride_request incompletos\n", FILE_APPEND);
            return;
        }

        $chatId = $data['chat_id'];
        
        if (!isset($this->sessions[$chatId])) {
            file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | ERROR: Chat {$chatId} no existe para ride_request\n", FILE_APPEND);
            return;
        }

        $rideData = [
            'type' => 'ride_request',
            'chat_id' => $chatId,
            'ride_id' => $data['ride_id'],
            'user_id' => $data['user_id'] ?? $from->userId,
            'pickup' => $data['pickup'],
            'destination' => $data['destination'],
            'fare' => $data['fare'],
            'timestamp' => $data['timestamp'] ?? date('c')
        ];

        // Enviar a todos en el chat excepto al remitente
        $sentCount = 0;
        foreach ($this->sessions[$chatId] as $client) {
            if ($client !== $from) {
                $client->send(json_encode($rideData));
                $sentCount++;
            }
        }

        file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | Ride request {$data['ride_id']} enviado a {$sentCount} cliente(s) en chat {$chatId}\n", FILE_APPEND);
    }

    // 🔹 Manejar aceptación de viaje
    private function handleRideAccepted(ConnectionInterface $from, $data)
    {
        if (!isset($data['chat_id'], $data['ride_id'], $data['driver_id'])) {
            file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | ERROR: Datos de ride_accepted incompletos\n", FILE_APPEND);
            return;
        }

        $chatId = $data['chat_id'];
        
        if (!isset($this->sessions[$chatId])) {
            return;
        }

        $acceptData = [
            'type' => 'ride_accepted',
            'chat_id' => $chatId,
            'ride_id' => $data['ride_id'],
            'driver_id' => $data['driver_id'],
            'timestamp' => $data['timestamp'] ?? date('c')
        ];

        // Broadcast a todos en el chat
        foreach ($this->sessions[$chatId] as $client) {
            $client->send(json_encode($acceptData));
        }

        file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | Ride {$data['ride_id']} aceptado por driver {$data['driver_id']} en chat {$chatId}\n", FILE_APPEND);
    }

    public function onClose(ConnectionInterface $conn)
    {
        // Eliminar de todas las sesiones
        foreach ($this->sessions as $chatId => $clients) {
            if ($clients->contains($conn)) {
                $clients->detach($conn);
                file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | Cliente {$conn->resourceId} removido del chat {$chatId}\n", FILE_APPEND);
                
                // Eliminar sesión si está vacía
                if ($clients->count() === 0) {
                    unset($this->sessions[$chatId]);
                    file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | Sesión de chat {$chatId} eliminada (sin clientes)\n", FILE_APPEND);
                }
            }
        }

        $this->clients->detach($conn);
        file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | Conexión {$conn->resourceId} cerrada\n", FILE_APPEND);
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | ERROR en conexión {$conn->resourceId}: " . $e->getMessage() . "\n", FILE_APPEND);
        $conn->close();
    }
}

$server = \Ratchet\Server\IoServer::factory(
    new \Ratchet\Http\HttpServer(
        new \Ratchet\WebSocket\WsServer(
            new SignalServer()
        )
    ),
    8080
);

echo "Servidor WebSocket iniciado en ws://localhost:8080\n";
$server->run();