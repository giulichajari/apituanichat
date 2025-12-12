<?php

namespace AudioCallApp;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class AudioCallServer implements MessageComponentInterface
{
    protected $clients;
    protected $turnConfig;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
        
        // 🔥 CONFIGURACIÓN TURN
        $this->turnConfig = [
            'server' => 'tuanichat.com',
            'port' => 3478,
            'tls_port' => 5349,
            'username' => 'webrtcuser',
            'password' => 'ClaveSuperSegura123'
        ];
        
        echo "🎧 AudioCallServer con TURN inicializado\n";
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        echo "🔗 Nueva conexión de audio: {$conn->resourceId}\n";
        
        // 🔥 ENVIAR CONFIGURACIÓN TURN INMEDIATAMENTE
        $this->sendTurnConfig($conn);
    }

    private function sendTurnConfig(ConnectionInterface $conn)
    {
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
                ['urls' => 'stun:stun.l.google.com:19302']
            ],
            'server_time' => date('Y-m-d H:i:s')
        ];
        
        $conn->send(json_encode($config));
        echo "📤 Configuración TURN enviada a conexión #{$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);
        
        if (!$data) {
            // Manejar audio binario
            $this->relayAudio($from, $msg);
            return;
        }

        switch ($data['type']) {
            case 'get_turn_config':
                $this->sendTurnConfig($from);
                break;
                
            case 'offer':
            case 'answer':
            case 'candidate':
                $this->relayWebRTCMessage($from, $data);
                break;
                
            default:
                echo "⚠️ Tipo desconocido: {$data['type']}\n";
        }
    }

    private function relayWebRTCMessage($from, $data)
    {
        foreach ($this->clients as $client) {
            if ($from !== $client) {
                $client->send(json_encode($data));
            }
        }
    }

    private function relayAudio($from, $audioData)
    {
        foreach ($this->clients as $client) {
            if ($from !== $client) {
                $client->send($audioData);
            }
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        echo "❌ Conexión de audio cerrada: {$conn->resourceId}\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "⚠️ Error en audio: {$e->getMessage()}\n";
        $conn->close();
    }
}