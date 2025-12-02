<?php
// test-after-restart.php
echo "🧪 TEST POST-REINICIO MYSQL\n";

$host = "localhost";
$dbname = "tuanichatbd";
$user = "tuanichat";
$pass = "Argentina1991!";

echo "🔍 Intentando conectar a $dbname...\n";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5 // Timeout de 5 segundos
        ]
    );
    
    echo "✅ Conexión exitosa!\n";
    
    // Probar consultas
    $pdo->query("SELECT 1");
    echo "✅ Consulta básica funciona\n";
    
    // Verificar tablas
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "📋 Tablas encontradas: " . count($tables) . "\n";
    
    if (in_array('mensajes', $tables)) {
        echo "✅ Tabla 'mensajes' existe\n";
        
        // Insertar prueba
        $stmt = $pdo->prepare("INSERT INTO mensajes (chat_id, user_id, contenido, tipo) VALUES (?, ?, ?, ?)");
        $stmt->execute([999, 999, 'Test post-reinicio', 'texto']);
        
        $id = $pdo->lastInsertId();
        echo "✅ Mensaje insertado con ID: $id\n";
        
        // Eliminar prueba
        $pdo->query("DELETE FROM mensajes WHERE id = $id");
        echo "✅ Mensaje de prueba eliminado\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    
    // Diagnóstico más detallado
    echo "\n🔍 Diagnóstico:\n";
    
    // Probar solo host
    try {
        $pdo2 = new PDO("mysql:host=$host", $user, $pass);
        echo "✅ Conectado al host, probando sin BD específica...\n";
        
        $dbs = $pdo2->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array($dbname, $dbs)) {
            echo "✅ La BD '$dbname' existe\n";
        } else {
            echo "❌ La BD '$dbname' NO existe\n";
            echo "📋 Bases disponibles: " . implode(', ', $dbs) . "\n";
        }
        
    } catch (PDOException $e2) {
        echo "❌ No se puede conectar ni al host: " . $e2->getMessage() . "\n";
    }
}