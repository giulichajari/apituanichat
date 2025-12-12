<?php

namespace AudioCallApp;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class AudioCallServer implements MessageComponentInterface
{
    protected $clients;       // SplObjectStorage para las conexiones
    protected $clientData;    // Array asociativo para IDs, userId, etc.
    protected $turnConfig;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
        $this->clientData = []; // Mapa de conexiones

        // 🔥 CONFIGURACIÓN TURN
        $this->turnConfig = [
            'server' => 'tuanichat.com',
            'port' => 3478,
            'tls_port' => 5349,
            'username' => 'webrtcuser',
            'password' => 'ClaveSuperSegura123',
            'enabled' => true
        ];

        echo "🎧 AudioCallServer con TURN inicializado\n";
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $connId = spl_object_id($conn); // ID único para cada conexión
        $this->clients->attach($conn);
        $this->clientData[$connId] = [
            'resourceId' => $connId,
            'userId' => null
        ];

        echo "🔗 Nueva conexión de audio: #$connId\n";

        // 🔥 ENVIAR CONFIGURACIÓN TURN
        $this->sendTurnConfig($conn, $connId);
    }

    private function sendTurnConfig(ConnectionInterface $conn, int $connId)
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
                ['urls' => 'stun:stun.l.google.com:19302']
            ],
            'server_time' => date('Y-m-d H:i:s')
        ];

        $conn->send(json_encode($config));
        echo "📤 Configuración TURN enviada a conexión #$connId\n";
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $connId = spl_object_id($from);

        $data = json_decode($msg, true);
        if (!$data) {
            // Audio binario
            $this->relayAudio($from, $msg);
            return;
        }

        switch ($data['type'] ?? '') {
            case 'get_turn_config':
                $this->sendTurnConfig($from, $connId);
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

    private function relayWebRTCMessage(ConnectionInterface $from, array $data)
    {
        foreach ($this->clients as $client) {
            if ($from !== $client) {
                $client->send(json_encode($data));
            }
        }
    }

    private function relayAudio(ConnectionInterface $from, $audioData)
    {
        foreach ($this->clients as $client) {
            if ($from !== $client) {
                $client->send($audioData);
            }
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $connId = spl_object_id($conn);
        $this->clients->detach($conn);
        unset($this->clientData[$connId]);

        echo "❌ Conexión de audio cerrada: #$connId\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $connId = spl_object_id($conn);
        echo "⚠️ Error en audio #$connId: {$e->getMessage()}\n";
        $conn->close();
    }
}
