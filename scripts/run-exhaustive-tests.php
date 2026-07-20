<?php
declare(strict_types=1);

// scripts/run-exhaustive-tests.php - Exhaustive Integration and Unit Audit Test Suite

require_once __DIR__ . '/../src/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src');

use App\Core\Config;
use App\Core\Database;
use App\Core\BookingStatus;
use App\Core\Validator;
use App\Core\HttpException;

echo "==========================================================" . PHP_EOL;
echo "🧪 USGAR REDESIGN - AUDITORÍA Y PRUEBAS EXHAUSTIVAS" . PHP_EOL;
echo "==========================================================" . PHP_EOL;

$failedTests = 0;
$passedTests = 0;

function assertTest(string $description, bool $condition, ?string $errorMessage = null) {
    global $failedTests, $passedTests;
    if ($condition) {
        echo "   ✅ PASS: $description" . PHP_EOL;
        $passedTests++;
    } else {
        echo "   ❌ FAIL: $description" . PHP_EOL;
        if ($errorMessage) {
            echo "      👉 $errorMessage" . PHP_EOL;
        }
        $failedTests++;
    }
}

// --------------------------------------------------------
// SECTION 1: Unit & Core Tests
// --------------------------------------------------------
echo PHP_EOL . "--- 📦 SECCIÓN 1: PRUEBAS UNITARIAS DE CORE ---" . PHP_EOL;

// 1.1 Autoloader
assertTest("El Autoloader PSR-4 está cargando las clases de Core", class_exists('App\Core\Router'));
assertTest("El Autoloader PSR-4 está cargando los Controladores", class_exists('App\Controllers\RoomController'));

// 1.2 Config
Config::boot();
assertTest("Config carga valores por defecto", Config::get('SITE_URL') !== null);
assertTest("Config detecta modo desarrollo por defecto", Config::isProduction() === false);

// 1.3 BookingStatus Enum
$status = BookingStatus::Pending;
assertTest("BookingStatus::Pending es extendible", $status->isExtendable() === true);
assertTest("BookingStatus::Pending no es terminal", $status->isTerminal() === false);
assertTest("BookingStatus::Paid no es extendible", BookingStatus::Paid->isExtendable() === false);
assertTest("BookingStatus::Paid es terminal", BookingStatus::Paid->isTerminal() === true);

// 1.4 Validator
try {
    Validator::requireFields(['name' => 'John'], ['name']);
    assertTest("Validator::requireFields pasa con parámetros válidos", true);
} catch (HttpException $e) {
    assertTest("Validator::requireFields pasa con parámetros válidos", false, $e->getMessage());
}

try {
    Validator::requireFields(['name' => 'John'], ['email']);
    assertTest("Validator::requireFields lanza excepción si faltan campos", false);
} catch (HttpException $e) {
    assertTest("Validator::requireFields lanza excepción si faltan campos", $e->getStatusCode() === 400);
}

try {
    Validator::dateRange('2026-07-20', '2026-07-25');
    assertTest("Validator::dateRange acepta rango de fechas futuras válido", true);
} catch (HttpException $e) {
    assertTest("Validator::dateRange acepta rango de fechas futuras", false, $e->getMessage());
}

try {
    Validator::dateRange('2026-07-25', '2026-07-20');
    assertTest("Validator::dateRange falla si checkOut es antes de checkIn", false);
} catch (HttpException $e) {
    assertTest("Validator::dateRange falla si checkOut es antes de checkIn", $e->getStatusCode() === 400);
}

// --------------------------------------------------------
// SECTION 2: HTTP Integration Tests via Server
// --------------------------------------------------------
echo PHP_EOL . "--- 🌐 SECCIÓN 2: PRUEBAS DE INTEGRACIÓN HTTP ---" . PHP_EOL;

// Iniciar servidor local de prueba en el puerto 8089 de forma multiplataforma (proc_open)
$host = '127.0.0.1';
$port = 8089;

$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$descriptorspec = [
    0 => ["pipe", "r"],
    1 => ["file", $nullDevice, "w"],
    2 => ["file", $nullDevice, "w"]
];

$docRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php';
$process = proc_open("php -S $host:$port $docRoot", $descriptorspec, $pipes, dirname(__DIR__));

// Esperar activamente a que el servidor responda (máximo 3 segundos)
$serverReady = false;
for ($i = 0; $i < 30; $i++) {
    usleep(100000); // 100ms
    $healthCheck = @file_get_contents("http://$host:$port/api/health");
    if ($healthCheck !== false && str_contains($healthCheck, '"success":true')) {
        $serverReady = true;
        break;
    }
}

if (!$serverReady) {
    echo "   ❌ ERROR: El servidor de pruebas en http://$host:$port no pudo iniciar." . PHP_EOL;
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    exit(1);
}

echo "   Servidor de pruebas listo en http://$host:$port" . PHP_EOL;

function httpGet(string $url): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    if ($response === false) {
        return ['status' => 0, 'headers' => [], 'body' => ''];
    }

    $headersRaw = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    
    return [
        'status' => $httpCode,
        'body' => $body,
    ];
}

// Test /api/health
$healthRes = httpGet("http://$host:$port/api/health");
assertTest("Endpoint GET /api/health retorna HTTP 200", $healthRes['status'] === 200);
assertTest("Endpoint GET /api/health retorna JSON válido con success=true", str_contains($healthRes['body'], '"success":true'));

// Test /api/rooms sin parámetros (debe fallar por validación)
$roomsInvalidRes = httpGet("http://$host:$port/api/rooms");
assertTest("Endpoint GET /api/rooms sin parámetros retorna HTTP 400 (Bad Request)", $roomsInvalidRes['status'] === 400);
assertTest("Endpoint GET /api/rooms sin parámetros reporta error de validación", str_contains(strtolower($roomsInvalidRes['body']), 'falta') || str_contains(strtolower($roomsInvalidRes['body']), 'field') || str_contains(strtolower($roomsInvalidRes['body']), 'checkin'));

// Test /api/rooms con rango inválido
$roomsRangeInvalidRes = httpGet("http://$host:$port/api/rooms?checkIn=2026-07-25&checkOut=2026-07-20");
assertTest("Endpoint GET /api/rooms con rango de fechas invertido retorna HTTP 400", $roomsRangeInvalidRes['status'] === 400);

// Test /api/booking-status sin cart_id (debe fallar)
$bookingStatusInvalidRes = httpGet("http://$host:$port/api/booking-status");
assertTest("Endpoint GET /api/booking-status sin cart_id retorna HTTP 400", $bookingStatusInvalidRes['status'] === 400);

// Limpiar proceso del servidor de pruebas
if (is_resource($process)) {
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    proc_terminate($process);
    proc_close($process);
}
echo "   Servidor de pruebas apagado." . PHP_EOL;

// --------------------------------------------------------
// SUMMARY
// --------------------------------------------------------
echo PHP_EOL . "==========================================================" . PHP_EOL;
echo "📊 RESUMEN DE AUDITORÍA:" . PHP_EOL;
echo "   Pasados: $passedTests" . PHP_EOL;
echo "   Fallidos: $failedTests" . PHP_EOL;
echo "==========================================================" . PHP_EOL;

if ($failedTests > 0) {
    exit(1);
} else {
    exit(0);
}
