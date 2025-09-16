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
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        // Decodificar mensaje
        $data = json_decode($msg, true);

        // Log del mensaje recibido
        $logMsg = date('Y-m-d H:i:s') . " | Mensaje recibido: " . $msg . "\n";
        file_put_contents(__DIR__ . '/ws.log', $logMsg, FILE_APPEND);

        if (!$data || !isset($data['session_id'], $data['type'])) {
            $errorLog = date('Y-m-d H:i:s') . " | Mensaje inválido o incompleto\n";
            file_put_contents(__DIR__ . '/ws.log', $errorLog, FILE_APPEND);
            return;
        }

        $sessionId = $data['session_id'];

        // Inicializar sesión si no existe
        if (!isset($this->sessions[$sessionId])) {
            $this->sessions[$sessionId] = new \SplObjectStorage();
            $sessionLog = date('Y-m-d H:i:s') . " | Nueva sesión creada: $sessionId\n";
            file_put_contents(__DIR__ . '/ws.log', $sessionLog, FILE_APPEND);
        }

        // Agregar conexión a la sesión solo si no estaba ya
        if (!$this->sessions[$sessionId]->contains($from)) {
            $this->sessions[$sessionId]->attach($from);
            $attachLog = date('Y-m-d H:i:s') . " | Cliente {$from->resourceId} agregado a sesión $sessionId\n";
            file_put_contents(__DIR__ . '/ws.log', $attachLog, FILE_APPEND);
        }

        // Enviar a otros clientes de la misma sesión
        foreach ($this->sessions[$sessionId] as $client) {
            if ($client !== $from) {
                $client->send(json_encode($data));
                $sendLog = date('Y-m-d H:i:s') . " | Enviado a cliente {$client->resourceId}: " . json_encode($data) . "\n";
                file_put_contents(__DIR__ . '/ws.log', $sendLog, FILE_APPEND);
            }
        }

        // Log final del estado de la sesión
        $count = count($this->sessions[$sessionId]);
        $sessionStateLog = date('Y-m-d H:i:s') . " | Sesión $sessionId con $count cliente(s) activo(s)\n";
        file_put_contents(__DIR__ . '/ws.log', $sessionStateLog, FILE_APPEND);
    }


    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        // Eliminar de todas las sesiones
        foreach ($this->sessions as $sessionId => $clients) {
            if ($clients->contains($conn)) $clients->detach($conn);
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
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

$server->run();
