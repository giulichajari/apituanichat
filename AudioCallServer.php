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
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $connId = spl_object_id($conn);
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

        // ⭐⭐ IMPORTANTE: Solo procesar si es audio binario
        // Los mensajes JSON son manejados por SignalServer
        if (!is_string($msg)) {
            // Es binario, probablemente audio
            $this->relayAudio($from, $msg);
            return;
        }

        // Intentar decodificar JSON
        $data = json_decode($msg, true);
        
        if ($data === null) {
            // No es JSON, es audio binario
            $this->relayAudio($from, $msg);
            return;
        }

        // Si es JSON, solo procesar get_turn_config
        if (isset($data['type']) && $data['type'] === 'get_turn_config') {
            $this->sendTurnConfig($from, $connId);
            return;
        }

        // ⭐⭐ CORRECCIÓN: NO imprimir "Tipo desconocido" para mensajes que no son de audio
        // Estos mensajes son manejados por SignalServer
        // Solo loguear para debug
        if (isset($data['type'])) {
            echo "🎧 AudioCallServer ignorando mensaje de tipo: {$data['type']} (manejado por SignalServer)\n";
        }
        
        // No hacer nada más, estos mensajes son para SignalServer
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