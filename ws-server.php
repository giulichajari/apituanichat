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
        $data = json_decode($msg, true);
        if (!$data || !isset($data['session_id'], $data['type'])) return;

        $sessionId = $data['session_id'];

        if (!isset($this->sessions[$sessionId])) {
            $this->sessions[$sessionId] = new \SplObjectStorage();
        }
        $this->sessions[$sessionId]->attach($from);

        foreach ($this->sessions[$sessionId] as $client) {
            if ($client !== $from) {
                $client->send(json_encode($data));
            }
        }
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
