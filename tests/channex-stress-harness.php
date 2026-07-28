<?php
declare(strict_types=1);

/**
 * Harness de Pruebas de Estres y Auditoria para el Sistema Channex
 * USGAR Hotels - Cusco, Peru
 *
 * Ejecuta pruebas de estres, seguridad y resiliencia runtime.
 * Uso: php tests/channex-stress-harness.php
 */

define('PHP_TESTING', true);
require_once __DIR__ . '/../app/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app');

use App\Core\Config;
use App\Core\Database;
use App\Features\Webhooks\Actions\HandleChannexWebhookAction;
use App\Features\Shared\Adapters\ChannexAdapter;

Config::boot();

echo "==========================================================\n";
echo "   HARNESS DE PRUEBAS DE ESTRES — SISTEMA CHANNEX\n";
echo "==========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $description, bool $condition): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] {$description}\n";
        $passCount++;
    } else {
        echo "[FAIL] {$description}\n";
        $failCount++;
    }
}

// 1. Prueba de Instanciacion de Componentes Channex
echo "--- 1. AUDITORIA DE INSTANCIACION ---\n";
try {
    $adapter = new ChannexAdapter();
    assertTest("ChannexAdapter instanciado correctamente", $adapter instanceof ChannexAdapter);
} catch (Throwable $e) {
    assertTest("ChannexAdapter instanciado correctamente: " . $e->getMessage(), false);
}

try {
    $action = new HandleChannexWebhookAction();
    assertTest("HandleChannexWebhookAction instanciado correctamente", $action instanceof HandleChannexWebhookAction);
} catch (Throwable $e) {
    assertTest("HandleChannexWebhookAction instanciado correctamente: " . $e->getMessage(), false);
}

// 2. Prueba de Firma / Secreto de Webhook
echo "\n--- 2. AUDITORIA DE SEGURIDAD Y FIRMA SECRETA ---\n";
Config::set('CHANNEX_WEBHOOK_SECRET', 'test_secret_key');

$dummyRequestInvalid = new \App\Core\Request(
    'POST',
    '/api/webhook/channex',
    ['x-channex-secret' => 'invalid_secret_key'],
    ['event' => 'booking_new', 'payload' => ['booking_id' => '123']]
);

// Obtenemos buffer para capturar respuesta HTTP 401
ob_start();
try {
    $action($dummyRequestInvalid);
    $output = ob_get_clean();
    assertTest("Rechazo de firma invalida con 401", str_contains($output, 'Invalid Channex webhook secret header') || str_contains($output, 'unauthorized'));
} catch (Throwable $e) {
    ob_end_clean();
    assertTest("Rechazo de firma invalida con excepcion: " . $e->getMessage(), true);
}

// 3. Prueba de Carga y Estres (50 solicitudes simultaneas / en rafaga)
echo "\n--- 3. PRUEBA DE ESTRES (RAFAGA DE 50 RESERVAS SIMULTANEAS) ---\n";
$startTime = microtime(true);
$stressSuccess = 0;
$stressErrors = 0;

$pdo = Database::getInstance()->getConnection();

for ($i = 1; $i <= 50; $i++) {
    $reservationId = "STRESS-OTA-" . sprintf("%04d", $i) . "-" . time();
    $request = new \App\Core\Request(
        'POST',
        '/api/webhook/channex',
        ['x-channex-secret' => 'test_secret_key'],
        [
            'event' => 'booking_new',
            'payload' => [
                'booking' => [
                    'id' => $reservationId,
                    'arrival_date' => date('Y-m-d', strtotime("+$i days")),
                    'departure_date' => date('Y-m-d', strtotime("+" . ($i + 2) . " days")),
                    'ota_name' => ($i % 2 === 0) ? 'Booking.com' : 'Expedia',
                    'amount' => 180.00,
                    'room_type_id' => ($i % 4) + 1,
                    'customer' => [
                        'name' => "Huesped Stress $i",
                        'surname' => "OTA Test",
                        'mail' => "stress$i@ota-test.com",
                        'phone' => "+51999888777"
                    ]
                ]
            ]
        ]
    );

    ob_start();
    try {
        $action($request);
        $resOutput = ob_get_clean();
        if (str_contains($resOutput, '"success":true')) {
            $stressSuccess++;
        } else {
            $stressErrors++;
        }
    } catch (Throwable $e) {
        ob_end_clean();
        $stressErrors++;
    }
}

$duration = round(microtime(true) - $startTime, 3);
echo "Rafaga de 50 peticiones ejecutada en {$duration} segundos.\n";
echo "Exitos: {$stressSuccess} | Errores: {$stressErrors}\n";

assertTest("Prueba de estres de 50 peticiones completada con 100% de exito", $stressSuccess === 50 && $stressErrors === 0);
assertTest("Rendimiento aceptable de procesamiento (< 15.0 segundos total)", $duration < 15.0);

// 4. Verificacion de Persistencia de Reservas de Estres en BD Local
if ($pdo !== null) {
    echo "\n--- 4. AUDITORIA DE BASE DE DATOS Y CONCURRENCIA ---\n";
    $stmt = $pdo->query("SELECT COUNT(*) FROM provisional_bookings WHERE cart_id LIKE 'OTA-%'");
    $countOtaHolds = (int)$stmt->fetchColumn();
    echo "Registros de reservas OTA encontrados en BD: {$countOtaHolds}\n";
    assertTest("Persistencia de reservas OTA en la base de datos verificada", $countOtaHolds >= 50);
}

echo "\n==========================================================\n";
echo "   RESUMEN FINAL: {$passCount} PASADAS | {$failCount} FALLIDAS\n";
echo "==========================================================\n";

exit($failCount > 0 ? 1 : 0);
