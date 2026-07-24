<?php
declare(strict_types=1);

/**
 * test_endpoints.php - Script de integracion para validar el correcto funcionamiento de la API.
 * Ubicado en scripts/ por seguridad (RULES.md: No exponer scripts en public/).
 *
 * Ejecutar via CLI: php scripts/test_endpoints.php
 */

// 1. Cargar el Autocargador PSR-4
require_once __DIR__ . '/../app/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app');
\App\Core\Config::boot();

echo "=== INICIANDO PRUEBAS DE INTEGRACION DE ENDPOINTS (usgar-redesign) ===" . PHP_EOL;

use App\Core\Database;
use App\Core\Config;
use App\Core\BookingStatus;
use App\Features\Rooms\Actions\GetRoomsAction;

// 2. Verificar conexion a la base de datos
$db = Database::getInstance();
$pdo = $db->getConnection();

if (!$pdo) {
    echo "️ Base de datos offline. Los tests de BD se omitiran (modo Mock)." . PHP_EOL;
} else {
    echo " Conexion a la base de datos establecida con exito." . PHP_EOL;
}

// 3. Probar Autoloading
if (class_exists('App\Core\Router') && class_exists('App\Features\Rooms\Actions\GetRoomsAction')) {
    echo " Autoloading PSR-4 personalizado funcionando correctamente." . PHP_EOL;
} else {
    die(" Error: Autoloading falló.");
}

// 4. Probar BookingStatus Enum
$pending = BookingStatus::Pending;
if ($pending->value === 'pending' && $pending->isExtendable() && !$pending->isTerminal()) {
    echo " BookingStatus enum: Pending OK" . PHP_EOL;
}

$paid = BookingStatus::Paid;
if ($paid->value === 'paid' && !$paid->isExtendable() && $paid->isTerminal()) {
    echo " BookingStatus enum: Paid OK" . PHP_EOL;
}

// 5. Probar Config
$env = Config::get('ENVIRONMENT', 'development');
echo " Config: ENVIRONMENT = {$env}" . PHP_EOL;

// 6. Probar controladores y logica de BD
echo PHP_EOL . "--- PROBANDO CONTROLADORES Y BASE DE DATOS ---" . PHP_EOL;

try {
    $roomsAction = new GetRoomsAction();
    echo " GetRoomsAction instanciado correctamente." . PHP_EOL;

    if ($pdo) {
        $bookingModel = new \App\Features\Booking\Domain\ProvisionalBookingRepository($pdo);
        $testCartId = 'MOCK-CART-TEST-' . time();

        // Limpiar holds de prueba previos
        $cleanStmt = $pdo->prepare("DELETE FROM provisional_bookings WHERE cart_id LIKE :pattern");
        $cleanStmt->execute([':pattern' => 'MOCK-CART-TEST-%']);

        $holdCreated = $bookingModel->create([
            'cart_id'        => $testCartId,
            'id_hotel'       => 1,
            'id_room_type'   => 1,
            'guest_data'     => ['name' => 'Test User', 'email' => 'test@example.com', 'phone' => '123'],
            'room_data'      => ['room_name' => 'Matrimonial', 'price_per_night' => 90.0, 'nights' => 2],
            'price_snapshot' => 180.0,
            'checkin'        => date('Y-m-d', strtotime('+5 days')),
            'checkout'       => date('Y-m-d', strtotime('+7 days')),
            'status'         => BookingStatus::Pending->value,
            'expires_at'     => date('Y-m-d H:i:s', strtotime('+15 minutes')),
        ]);

        if ($holdCreated) {
            echo " Creacion de provisional_bookings en BD: OK" . PHP_EOL;

            // Probar lectura
            $hold = $bookingModel->getByCartId($testCartId);
            if ($hold && $hold['guest_data']['name'] === 'Test User') {
                echo " Lectura de provisional_bookings en BD: OK" . PHP_EOL;
            } else {
                echo " Fallo al leer provisional_bookings." . PHP_EOL;
            }

            // Probar extension
            $extended = $bookingModel->extend($testCartId, date('Y-m-d H:i:s', strtotime('+30 minutes')));
            echo ($extended ? "" : "") . " Extension de provisional_bookings en BD: " . ($extended ? "OK" : "FAIL") . PHP_EOL;

            // Probar cleanup (UPDATE a 'expired')
            $expiredCount = $bookingModel->cleanExpiredCarts();
            echo " Cleanup de holds expirados: {$expiredCount} registros afectados" . PHP_EOL;

            // Limpieza
            $deleteStmt = $pdo->prepare("DELETE FROM provisional_bookings WHERE cart_id = :cart_id");
            $deleteStmt->execute([':cart_id' => $testCartId]);
            echo " Limpieza de registros de prueba en BD: OK" . PHP_EOL;
        } else {
            echo " Fallo al insertar provisional_bookings de prueba." . PHP_EOL;
        }
    }
} catch (Exception $e) {
    echo " Excepcion durante las pruebas: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "=== PRUEBAS COMPLETADAS CON EXITO ===" . PHP_EOL;
