<?php

namespace AudioCallApp;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;

class AudioCallServer implements MessageComponentInterface
{
    protected $clients;
    protected $rooms;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
        $this->rooms = [];
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        echo "Nueva conexión: {$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);
        
        if (!$data) {
            return;
        }

        switch ($data['type']) {
            case 'join':
                $roomId = $data['roomId'];
                $userId = $data['userId'];
                
                if (!isset($this->rooms[$roomId])) {
                    $this->rooms[$roomId] = [];
                }
                
                $this->rooms[$roomId][$userId] = $from;
                $from->roomId = $roomId;
                $from->userId = $userId;
                
                // Notificar a otros en la sala
                foreach ($this->rooms[$roomId] as $id => $client) {
                    if ($id !== $userId) {
                        $client->send(json_encode([
                            'type' => 'user-joined',
                            'userId' => $userId
                        ]));
                    }
                }
                
                // Enviar lista de usuarios al nuevo
                $users = array_keys($this->rooms[$roomId]);
                $from->send(json_encode([
                    'type' => 'room-info',
                    'users' => $users
                ]));
                break;

            case 'offer':
            case 'answer':
            case 'candidate':
                $targetUserId = $data['targetUserId'];
                $roomId = $from->roomId;
                
                if (isset($this->rooms[$roomId][$targetUserId])) {
                    $this->rooms[$roomId][$targetUserId]->send(json_encode([
                        'type' => $data['type'],
                        'from' => $from->userId,
                        'data' => $data['data']
                    ]));
                }
                break;

            case 'leave':
                $roomId = $from->roomId;
                $userId = $from->userId;
                
                if (isset($this->rooms[$roomId][$userId])) {
                    unset($this->rooms[$roomId][$userId]);
                    
                    foreach ($this->rooms[$roomId] as $client) {
                        $client->send(json_encode([
                            'type' => 'user-left',
                            'userId' => $userId
                        ]));
                    }
                }
                break;
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        
        if (isset($conn->roomId, $conn->userId)) {
            $roomId = $conn->roomId;
            $userId = $conn->userId;
            
            if (isset($this->rooms[$roomId][$userId])) {
                unset($this->rooms[$roomId][$userId]);
                
                foreach ($this->rooms[$roomId] as $client) {
                    $client->send(json_encode([
                        'type' => 'user-left',
                        'userId' => $userId
                    ]));
                }
            }
        }
        
        echo "Conexión cerrada: {$conn->resourceId}\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }
}