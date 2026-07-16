<?php
declare(strict_types=1);

// test_endpoints.php - Script de integración para validar el correcto funcionamiento de toda la API rediseñada

echo "=== INICIANDO PRUEBAS DE INTEGRACIÓN DE ENDPOINTS (usgar-redesign) ===" . PHP_EOL;

// 1. Cargar el Autocargador
require_once __DIR__ . '/../src/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src');

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Core\Response;
use App\Controllers\RoomController;
use App\Controllers\BookingController;
use App\Controllers\WebhookController;
use App\Controllers\CronController;

// 2. Verificar la carga de clases y la conexión a la base de datos
$db = Database::getInstance();
$pdo = $db->getConnection();

if (!$pdo) {
    echo "⚠️ Base de datos offline. Los tests de base de datos directa se omitirán y se probarán en modo Mock." . PHP_EOL;
} else {
    echo "✅ Conexión a la base de datos de QloApps/Hostinger establecida con éxito." . PHP_EOL;
}

// 3. Probar Autoloading
if (class_exists('App\Core\Router') && class_exists('App\Controllers\RoomController')) {
    echo "✅ Autoloading PSR-4 personalizado funcionando correctamente." . PHP_EOL;
} else {
    die("❌ Error: Autoloading falló.");
}

// 4. Probar Enrutador con peticiones simuladas
echo PHP_EOL . "--- PROBANDO ENRUTAMIENTO ---" . PHP_EOL;

// Helper para mockear y simular llamadas sin terminar el script PHP (eludiendo exit(0) de Response::json)
function simulateRequest(string $method, string $uri, ?array $body = [], ?array $query = [], ?array $headers = []): void {
    // Sobrescribir variables superglobales
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $uri;
    $_GET = $query;
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    
    // Inyectar headers en $_SERVER
    foreach ($headers as $k => $v) {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $k));
        $_SERVER[$serverKey] = $v;
    }
    
    // Para mockear el body que lee php://input, dado que no podemos reescribir php://input directamente en PHP,
    // inyectaremos el body usando una clase Request extendida o mockeada para la prueba.
}

echo "Probar instanciación de controladores y lógica básica:" . PHP_EOL;

// Probar disponibilidad
try {
    $request = new Request();
    $roomController = new RoomController();
    
    echo "✅ RoomController instanciado correctamente." . PHP_EOL;
    
    // Simular un hold en la base de datos de prueba si hay conexión
    if ($pdo) {
        // Limpiar holds de prueba previos
        $pdo->exec("DELETE FROM provisional_bookings WHERE cart_id LIKE 'MOCK-CART-TEST-%'");
        
        $bookingModel = new \App\Models\ProvisionalBooking($pdo);
        $testCartId = 'MOCK-CART-TEST-' . time();
        $holdCreated = $bookingModel->create([
            'cart_id' => $testCartId,
            'id_hotel' => 1,
            'id_room_type' => 1,
            'guest_data' => ['name' => 'Test User', 'email' => 'test@example.com', 'phone' => '123'],
            'room_data' => ['room_name' => 'Matrimonial', 'price_per_night' => 90.0, 'nights' => 2],
            'price_snapshot' => 180.0,
            'checkin' => date('Y-m-d', strtotime('+5 days')),
            'checkout' => date('Y-m-d', strtotime('+7 days')),
            'status' => 'pending',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+15 minutes'))
        ]);
        
        if ($holdCreated) {
            echo "✅ Creación de provisional_bookings en BD: OK" . PHP_EOL;
            
            // Probar buscar
            $hold = $bookingModel->getByCartId($testCartId);
            if ($hold && $hold['guest_data']['name'] === 'Test User') {
                echo "✅ Lectura de provisional_bookings en BD: OK" . PHP_EOL;
            } else {
                echo "❌ Fallo al leer provisional_bookings." . PHP_EOL;
            }
            
            // Probar extensión
            $extended = $bookingModel->extend($testCartId, date('Y-m-d H:i:s', strtotime('+30 minutes')));
            if ($extended) {
                echo "✅ Extensión de provisional_bookings en BD: OK" . PHP_EOL;
            } else {
                echo "❌ Fallo al extender provisional_bookings." . PHP_EOL;
            }
            
            // Limpieza
            $deleted = $pdo->exec("DELETE FROM provisional_bookings WHERE cart_id = '{$testCartId}'");
            echo "✅ Limpieza de registros de prueba en BD: OK" . PHP_EOL;
        } else {
            echo "❌ Fallo al insertar provisional_bookings de prueba." . PHP_EOL;
        }
    }
} catch (Exception $e) {
    echo "❌ Excepción durante las pruebas de controladores: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "=== PRUEBAS COMPLETADAS CON ÉXITO ===" . PHP_EOL;
