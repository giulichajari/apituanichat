<?php
// test-bd-websocket.php - Probar BD desde el contexto del WebSocket
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/App/Models/ChatModel.php';

echo "🧪 TEST BD DESDE WEBSOCKET\n";
echo "📂 Directorio: " . __DIR__ . "\n";

try {
    // 1. Instanciar ChatModel
    echo "🔍 Instanciando ChatModel...\n";
    $chatModel = new App\Models\ChatModel();
    echo "✅ ChatModel instanciado\n";
    
    // 2. Probar conexión directa
    echo "🔍 Probando conexión a BD...\n";
    
    // Método 1: Usar PDO directamente si tenemos acceso
    try {
        // Intentar acceder a la conexión PDO
        $reflection = new ReflectionClass($chatModel);
        $dbProperty = $reflection->getProperty('db');
        $dbProperty->setAccessible(true);
        $pdo = $dbProperty->getValue($chatModel);
        
        echo "✅ PDO obtenido\n";
        
        // Probar consulta
        $stmt = $pdo->query("SELECT DATABASE() as db, USER() as user");
        $result = $stmt->fetch();
        echo "📊 Base de datos: " . ($result['db'] ?? 'N/A') . "\n";
        echo "👤 Usuario BD: " . ($result['user'] ?? 'N/A') . "\n";
        
        // Verificar tablas
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "📋 Tablas disponibles: " . implode(', ', $tables) . "\n";
        
        // Verificar tabla mensajes
        if (in_array('mensajes', $tables)) {
            echo "✅ Tabla 'mensajes' existe\n";
            
            // Contar mensajes
            $count = $pdo->query("SELECT COUNT(*) FROM mensajes")->fetchColumn();
            echo "📊 Total mensajes: {$count}\n";
            
            // Insertar mensaje de prueba
            $testChatId = 1;
            $testUserId = 31;
            $testContent = "Mensaje de prueba desde script " . date('H:i:s');
            
            echo "🔍 Insertando mensaje de prueba...\n";
            $stmt = $pdo->prepare("
                INSERT INTO mensajes (chat_id, user_id, contenido, tipo, enviado_en) 
                VALUES (?, ?, ?, 'texto', NOW())
            ");
            $stmt->execute([$testChatId, $testUserId, $testContent]);
            
            $messageId = $pdo->lastInsertId();
            echo "✅ Mensaje insertado con ID: {$messageId}\n";
            
            // Verificar que se guardó
            $stmt = $pdo->prepare("SELECT * FROM mensajes WHERE id = ?");
            $stmt->execute([$messageId]);
            $mensaje = $stmt->fetch();
            
            if ($mensaje) {
                echo "✅ Mensaje verificado en BD\n";
                echo "📄 Contenido: " . $mensaje['contenido'] . "\n";
            } else {
                echo "❌ Mensaje NO encontrado después de insertar\n";
            }
        } else {
            echo "❌ Tabla 'mensajes' NO existe\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Error con PDO: " . $e->getMessage() . "\n";
    }
    
    // 3. Probar métodos del ChatModel
    echo "\n🔍 Probando métodos del ChatModel...\n";
    
    // Probar chatExists
    echo "🔍 Probando chatExists(1)...\n";
    $exists = $chatModel->chatExists(1);
    echo "📊 chatExists(1) = " . ($exists ? 'true' : 'false') . "\n";
    
    // Probar sendMessage
    echo "🔍 Probando sendMessage...\n";
    try {
        $testMessageId = $chatModel->sendMessage(1, 31, "Test desde script", 'texto');
        echo "✅ sendMessage exitoso - ID: {$testMessageId}\n";
    } catch (Exception $e) {
        echo "❌ Error en sendMessage: " . $e->getMessage() . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error general: " . $e->getMessage() . "\n";
    echo "📋 Trace: " . $e->getTraceAsString() . "\n";
}