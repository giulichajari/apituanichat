<?php
// cliente_test.php - Para probar el WebSocket
error_reporting(E_ALL);

$host = '127.0.0.1';
$port = 8080;

echo "🔌 Conectando a ws://{$host}:{$port}...\n";

// Usar WebSocket desde línea de comandos
// En Linux/Mac:
// echo 'Instalar wscat: npm install -g wscat'
// echo 'Luego: wscat -c ws://localhost:8080'

// O usar este script PHP simple:
$fp = fsockopen($host, $port, $errno, $errstr, 30);

if (!$fp) {
    echo "❌ Error: {$errstr} ({$errno})\n";
} else {
    echo "✅ Conectado\n";
    
    // Handshake WebSocket manual (simplificado)
    $key = base64_encode(random_bytes(16));
    $header = "GET / HTTP/1.1\r\n";
    $header .= "Host: {$host}:{$port}\r\n";
    $header .= "Upgrade: websocket\r\n";
    $header .= "Connection: Upgrade\r\n";
    $header .= "Sec-WebSocket-Key: {$key}\r\n";
    $header .= "Sec-WebSocket-Version: 13\r\n\r\n";
    
    fwrite($fp, $header);
    
    // Leer respuesta
    $response = fread($fp, 1024);
    echo "📥 Respuesta: " . $response . "\n";
    
    fclose($fp);
}

echo "\n📋 Comandos para probar:\n";
echo "1. Instalar wscat: npm install -g wscat\n";
echo "2. Conectar: wscat -c ws://localhost:8080\n";
echo "3. Enviar: {\"type\":\"test\",\"message\":\"Hola\"}\n";
echo "4. Enviar: {\"type\":\"ping\"}\n";
echo "5. Enviar: {\"type\":\"chat_message\",\"user\":\"Juan\",\"message\":\"Hola a todos\"}\n";