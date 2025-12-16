<?php

namespace AudioCallApp;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class AudioCallServer implements MessageComponentInterface
{
    protected $clients;
    protected $clientData;
    protected $turnConfig;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
        $this->clientData = [];

     $this->turnConfig = [
            'server' => 'tuanichat.com',
            'port' => 3478,
            'tls_port' => 5349,
            'username' => 'webrtcuser',
            'password' => 'ClaveSuperSegura123',
            'enabled' => true
        ];

        echo "🎧 AudioCallServer con TURN inicializado\n";
        echo "📌 NOTA: Este servidor maneja SOLO audio binario y config TURN\n";
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $connId = spl_object_hash($conn);
        $this->clients->attach($conn, [
            'id' => $connId,
            'userId' => null,
            'connected_at' => time()
        ]);

        echo "🔗 [AUDIO] Nueva conexión: {$connId}\n";
        
        // ⭐⭐ ENVIAR CONFIGURACIÓN TURN INMEDIATAMENTE
        $this->sendTurnConfig($conn);
    }

    private function sendTurnConfig(ConnectionInterface $conn)
    {
        if (!$this->turnConfig['enabled']) {
            echo "⚠️ [AUDIO] TURN deshabilitado en configuración\n";
            return;
        }

        $config = [
            'type' => 'turn_config',
            'turn' => [
                'urls' => [
                    'turn:' . $this->turnConfig['server'] . ':' . $this->turnConfig['port'],
                    'turns:' . $this->turnConfig['server'] . ':' . $this->turnConfig['tls_port']
                ],
                'username' => $this->turnConfig['username'],
                'credential' => $this->turnConfig['password']
            ],
            'stun' => [
                'urls' => 'stun:stun.l.google.com:19302'
            ],
            'iceTransportPolicy' => 'all',
            'rtcpMuxPolicy' => 'require'
        ];

        $conn->send(json_encode($config));
        echo "📤 [AUDIO] Config TURN enviada\n";
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $connData = $this->clients[$from];
        $connId = $connData['id'];

        // ⭐⭐ SOLO MANEJAR DOS TIPOS DE MENSAJES:
        
        // 1. Solicitud de configuración TURN (JSON)
        if (is_string($msg)) {
            $data = json_decode($msg, true);
            
            if ($data && isset($data['type']) && $data['type'] === 'get_turn_config') {
                echo "📨 [AUDIO] Solicitud TURN de {$connId}\n";
                $this->sendTurnConfig($from);
                return;
            }
        }

        // 2. Audio binario (relay a otros clientes)
        // ⭐⭐ IMPORTANTE: Asumimos que TODO lo demás es audio binario
        echo "🔊 [AUDIO] Relay de datos binarios ({$this->getDataSize($msg)} bytes)\n";
        $this->relayAudio($from, $msg);
    }

    private function getDataSize($data)
    {
        if (is_string($data)) {
            return strlen($data);
        } elseif (is_array($data) || is_object($data)) {
            return strlen(json_encode($data));
        }
        return 0;
    }

    private function relayAudio(ConnectionInterface $from, $audioData)
    {
        $fromId = $this->clients[$from]['id'];
        $relayedCount = 0;

        foreach ($this->clients as $client) {
            if ($from !== $client) {
                $client->send($audioData);
                $relayedCount++;
            }
        }

        if ($relayedCount > 0) {
            echo "📡 [AUDIO] Datos relayados de {$fromId} a {$relayedCount} clientes\n";
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        if ($this->clients->contains($conn)) {
            $connData = $this->clients[$conn];
            $duration = time() - $connData['connected_at'];
            
            echo "❌ [AUDIO] Conexión cerrada: {$connData['id']} (duración: {$duration}s)\n";
            $this->clients->detach($conn);
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        if ($this->clients->contains($conn)) {
            $connData = $this->clients[$conn];
            echo "⚠️ [AUDIO] Error en {$connData['id']}: {$e->getMessage()}\n";
        }
        $conn->close();
    }
    
    // ⭐⭐ NUEVO: Método para conectar usuarios a sesiones de audio
    public function associateUser($conn, $userId, $sessionId)
    {
        if ($this->clients->contains($conn)) {
            $this->clients[$conn]['userId'] = $userId;
            $this->clients[$conn]['sessionId'] = $sessionId;
            echo "👤 [AUDIO] Usuario {$userId} asociado a sesión {$sessionId}\n";
        }
    }
}