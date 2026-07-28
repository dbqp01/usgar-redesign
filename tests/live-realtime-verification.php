<?php
declare(strict_types=1);

/**
 * Script de Verificacion Runtime de Funcionalidad en Tiempo Real.
 * USGAR Hotels - Cusco, Peru
 *
 * Prueba que al enviar una solicitud a la API (Channex/Webhook/Booking),
 * la respuesta de disponibilidad de la API y la web cambia al instante.
 */

define('PHP_TESTING', true);
require_once __DIR__ . '/../app/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app');

use App\Core\Config;
use App\Core\Request;
use App\Features\Rooms\Actions\GetRoomsAction;
use App\Features\Webhooks\Actions\HandleChannexWebhookAction;
use App\Features\Shared\Adapters\QloAppAdapter;

Config::boot();
Config::set('CHANNEX_WEBHOOK_SECRET', 'realtime_secret_test');

echo "==========================================================\n";
echo "   VERIFICACION RUNTIME EN TIEMPO REAL (API -> WEB)\n";
echo "==========================================================\n\n";

$adapter = new QloAppAdapter();
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// Limpiar reservas de estres anteriores para la prueba limpia
$pdo = \App\Core\Database::getInstance()->getConnection();
if ($pdo) {
    $pdo->exec("DELETE FROM provisional_bookings WHERE cart_id LIKE 'OTA-%' OR cart_id LIKE 'REALTIME-%'");
}

// 1. Disponibilidad inicial antes de la solicitud API
$initialRooms = $adapter->getAvailableRooms($today, $tomorrow);
$initialMatrimonial = null;
foreach ($initialRooms as $r) {
    if ($r['id_room_type'] === 1) {
        $initialMatrimonial = $r['available_qty'];
        break;
    }
}

echo "1. Disponibilidad inicial Matrimonial Superior: " . ($initialMatrimonial ?? 'N/A') . " habitaciones.\n";

// 2. Enviar una reserva via la API de Webhook Channex
$webhookAction = new HandleChannexWebhookAction();
$resId = "REALTIME-API-" . time();

$webhookRequest = new Request(
    'POST',
    '/api/webhook/channex',
    ['x-channex-secret' => 'realtime_secret_test'],
    [
        'event' => 'booking_new',
        'payload' => [
            'booking' => [
                'id' => $resId,
                'arrival_date' => $today,
                'departure_date' => $tomorrow,
                'ota_name' => 'Booking.com Realtime Test',
                'amount' => 90.00,
                'room_type_id' => 1,
                'customer' => [
                    'name' => 'Huesped Realtime',
                    'surname' => 'API Test',
                    'mail' => 'realtime@test.com'
                ]
            ]
        ]
    ]
);

ob_start();
$webhookAction($webhookRequest);
$outputWebhook = ob_get_clean();

echo "2. Solicitud enviada a la API Channex Webhook. Respuesta: {$outputWebhook}\n";

// 3. Consulta en tiempo real tras la solicitud API
$updatedRooms = $adapter->getAvailableRooms($today, $tomorrow);
$updatedMatrimonial = null;
foreach ($updatedRooms as $r) {
    if ($r['id_room_type'] === 1) {
        $updatedMatrimonial = $r['available_qty'];
        break;
    }
}

echo "3. Disponibilidad en TIEMPO REAL tras la solicitud API: " . ($updatedMatrimonial ?? 'N/A') . " habitaciones.\n";

$difference = ($initialMatrimonial !== null && $updatedMatrimonial !== null) ? ($initialMatrimonial - $updatedMatrimonial) : 0;

if ($difference === 1) {
    echo "\n[PASS] EXITOSAS PRUEBAS EN TIEMPO REAL: La solicitud a la API modifico instantaneamente la disponibilidad en vivo (De {$initialMatrimonial} -> a {$updatedMatrimonial}).\n";
    exit(0);
} else {
    echo "\n[FAIL] No se detecto el cambio esperado en tiempo real. Inicial: {$initialMatrimonial}, Actualizado: {$updatedMatrimonial}\n";
    exit(1);
}
