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
        $data = json_decode($msg, true);
        file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | Mensaje recibido: " . $msg . "\n", FILE_APPEND);

        if (!$data || !isset($data['session_id'], $data['type'])) {
            file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | Mensaje inválido o incompleto\n", FILE_APPEND);
            return;
        }

        $sessionId = $data['session_id'];

        // Inicializar sesión si no existe
        if (!isset($this->sessions[$sessionId])) {
            $this->sessions[$sessionId] = new \SplObjectStorage();
            file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | Sesión $sessionId creada\n", FILE_APPEND);
        }

        // Agregar cliente si no está en la sesión
        if (!$this->sessions[$sessionId]->contains($from)) {
            $this->sessions[$sessionId]->attach($from);
            file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | Cliente {$from->resourceId} agregado a sesión $sessionId\n", FILE_APPEND);
        }

        // Enviar a otros clientes de la misma sesión
        foreach ($this->sessions[$sessionId] as $client) {
            if ($client !== $from) {
                $client->send(json_encode($data));
                file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | Enviado a cliente {$client->resourceId}: " . json_encode($data) . "\n", FILE_APPEND);
            }
        }

        // Log final del estado de la sesión
        $ids = [];
        foreach ($this->sessions[$sessionId] as $c) $ids[] = $c->resourceId;
        file_put_contents(__DIR__ . '/ws.log', date('Y-m-d H:i:s') . " | Sesión $sessionId con " . count($ids) . " cliente(s) activo(s): " . json_encode($ids) . "\n", FILE_APPEND);
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
