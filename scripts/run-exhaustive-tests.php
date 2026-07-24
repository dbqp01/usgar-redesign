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
use App\Features\Rooms\Actions\GetRoomsAction;
use App\Features\Shared\Ports\PmsPortInterface;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use App\Features\Shared\Ports\ChannelManagerPortInterface;
use App\Features\Shared\Adapters\QloAppAdapter;

echo "==========================================================" . PHP_EOL;
echo "🧪 USGAR REDESIGN - AUDITORÍA Y PRUEBAS EXHAUSTIVAS" . PHP_EOL;
echo "==========================================================" . PHP_EOL;

$failedTests = 0;
$passedTests = 0;

function assertTest(string $description, bool $condition, $debugData = null): void {
    global $passedTests, $failedTests;
    if ($condition) {
        echo "   ✅ PASS: $description" . PHP_EOL;
        $passedTests++;
    } else {
        echo "   ❌ FAIL: $description" . PHP_EOL;
        if ($debugData !== null) {
            echo "      DEBUG DATA: " . print_r($debugData, true) . PHP_EOL;
        }
        $failedTests++;
    }
}

// --------------------------------------------------------
// SECTION 1: Unit & Core Tests
// --------------------------------------------------------
echo PHP_EOL . "--- 📦 SECCIÓN 1: PRUEBAS UNITARIAS DE CORE & ADR ---" . PHP_EOL;

// 1.1 Autoloader PSR-4
assertTest("El Autoloader PSR-4 está cargando las clases de Core", class_exists('App\Core\Router'));
assertTest("El Autoloader PSR-4 está cargando las Clases-Acción ADR (Vertical Slicing)", class_exists(GetRoomsAction::class));
assertTest("El Autoloader PSR-4 está cargando los Puertos Hexagonales", interface_exists(PmsPortInterface::class));
assertTest("El Autoloader PSR-4 está cargando los Adaptadores Hexagonales", class_exists(QloAppAdapter::class));

// 1.2 Verificación de Contratos Hexagonales (verifySignature, getPaymentDetails, createBooking)
assertTest("PaymentGatewayPortInterface declara 'verifySignature'", method_exists(PaymentGatewayPortInterface::class, 'verifySignature'));
assertTest("PaymentGatewayPortInterface declara 'getPaymentDetails'", method_exists(PaymentGatewayPortInterface::class, 'getPaymentDetails'));
assertTest("ChannelManagerPortInterface declara 'createBooking'", method_exists(ChannelManagerPortInterface::class, 'createBooking'));

// 1.3 Config
Config::boot();
assertTest("Config carga valores por defecto", Config::get('SITE_URL') !== null);
assertTest("Config detecta modo desarrollo por defecto", Config::isProduction() === false);

// 1.4 BookingStatus Enum
$status = BookingStatus::Pending;
assertTest("BookingStatus::Pending es extendible", $status->isExtendable() === true);
assertTest("BookingStatus::Pending no es terminal", $status->isTerminal() === false);
assertTest("BookingStatus::Paid no es extendible", BookingStatus::Paid->isExtendable() === false);
assertTest("BookingStatus::Paid es terminal", BookingStatus::Paid->isTerminal() === true);

// 1.5 Validator
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

$host = '127.0.0.1';
$port = 8089;

$serverLogFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-test-server.log';
if (file_exists($serverLogFile)) {
    @unlink($serverLogFile);
}

$descriptorspec = [
    0 => ["pipe", "r"],
    1 => ["file", $serverLogFile, "a"],
    2 => ["file", $serverLogFile, "a"]
];

$docRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public';
$routerScript = $docRoot . DIRECTORY_SEPARATOR . 'index.php';

$cmd = sprintf('php -S %s:%d -t %s %s', $host, $port, escapeshellarg($docRoot), escapeshellarg($routerScript));
$process = proc_open($cmd, $descriptorspec, $pipes, dirname(__DIR__));

register_shutdown_function(function () use (&$process, &$pipes) {
    if (is_resource($process)) {
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            @fclose($pipes[0]);
        }
        @proc_terminate($process);
        @proc_close($process);
    }
});

$serverReady = false;
for ($i = 0; $i < 30; $i++) {
    usleep(200000);
    $fp = @fsockopen($host, $port, $errno, $errstr, 0.5);
    if ($fp) {
        fclose($fp);
        $healthCheck = @file_get_contents("http://$host:$port/api/health");
        if ($healthCheck !== false && str_contains($healthCheck, '"success":true')) {
            $serverReady = true;
            break;
        }
    }
}

if (!$serverReady) {
    echo "   ❌ ERROR: El servidor de pruebas en http://$host:$port no pudo iniciar." . PHP_EOL;
    $logContent = file_exists($serverLogFile) ? file_get_contents($serverLogFile) : '(sin log)';
    echo "---- php -S log ----" . PHP_EOL;
    echo ($logContent !== false && trim($logContent) !== '') ? $logContent : '(log vacío)' . PHP_EOL;
    echo "--------------------" . PHP_EOL;
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
assertTest("Endpoint GET /api/booking-status sin cart_id retorna HTTP 400", $bookingStatusInvalidRes['status'] === 400, $bookingStatusInvalidRes);

if (is_resource($process)) {
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    proc_terminate($process);
    proc_close($process);
}
echo "   Servidor de pruebas apagado." . PHP_EOL;

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
