<?php
declare(strict_types=1);

// scripts/run-exhaustive-tests.php - Exhaustive Integration and Unit Audit Test Suite

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\BookingStatus;
use App\Core\Validator;
use App\Core\HttpException;
use App\Features\Rooms\Actions\GetRoomsAction;
use App\Features\Shared\Ports\PmsPortInterface;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use App\Features\Shared\Adapters\QloAppAdapter;

echo "==========================================================" . PHP_EOL;
echo " USGAR REDESIGN - AUDITORIA Y PRUEBAS EXHAUSTIVAS" . PHP_EOL;
echo "==========================================================" . PHP_EOL;

$failedTests = 0;
$passedTests = 0;

function assertTest(string $description, bool $condition, $debugData = null): void {
    global $passedTests, $failedTests;
    if ($condition) {
        echo "    PASS: $description" . PHP_EOL;
        $passedTests++;
    } else {
        echo "    FAIL: $description" . PHP_EOL;
        if ($debugData !== null) {
            echo "      DEBUG DATA: " . json_encode($debugData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        }
        $failedTests++;
    }
}

// --------------------------------------------------------
// SECTION 1: Unit & Core Tests
// --------------------------------------------------------
echo PHP_EOL . "---  SECCION 1: PRUEBAS UNITARIAS DE CORE & ADR ---" . PHP_EOL;

// 1.1 Autoloader PSR-4
assertTest("El Autoloader PSR-4 está cargando las clases de Core", class_exists('App\Core\Router'));
assertTest("El Autoloader PSR-4 está cargando las Clases-Acción ADR (Vertical Slicing)", class_exists(GetRoomsAction::class));
assertTest("El Autoloader PSR-4 está cargando los Puertos Hexagonales", interface_exists(PmsPortInterface::class));
assertTest("El Autoloader PSR-4 está cargando los Adaptadores Hexagonales", class_exists(QloAppAdapter::class));

// 1.2 Verificacion de Contratos Hexagonales (verifySignature, getPaymentDetails)
assertTest("PaymentGatewayPortInterface declara 'verifySignature'", method_exists(PaymentGatewayPortInterface::class, 'verifySignature'));
assertTest("PaymentGatewayPortInterface declara 'getPaymentDetails'", method_exists(PaymentGatewayPortInterface::class, 'getPaymentDetails'));

// 1.3 Config
Config::boot();
assertTest("Config carga valores por defecto", Config::get('SITE_URL') !== null);

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

$today = new DateTimeImmutable('today');
$validCheckIn = $today->modify('+1 day')->format('Y-m-d');
$validCheckOut = $today->modify('+3 day')->format('Y-m-d');

try {
    Validator::dateRange($validCheckIn, $validCheckOut);
    assertTest("Validator::dateRange acepta rango de fechas futuras válido", true);
} catch (HttpException $e) {
    assertTest("Validator::dateRange acepta rango de fechas futuras", false, $e->getMessage());
}

try {
    Validator::dateRange($validCheckOut, $validCheckIn);
    assertTest("Validator::dateRange falla si checkOut es antes de checkIn", false);
} catch (HttpException $e) {
    assertTest("Validator::dateRange falla si checkOut es antes de checkIn", $e->getStatusCode() === 400);
}

// --------------------------------------------------------
// SECTION 2: HTTP Integration Tests via Server
// --------------------------------------------------------
echo PHP_EOL . "---  SECCION 2: PRUEBAS DE INTEGRACION HTTP ---" . PHP_EOL;

$host = '127.0.0.1';
$port = 8089;

// Encontrar un puerto libre no bloqueado en TIME_WAIT por ejecuciones previas
for ($tryPort = 8089; $tryPort <= 8110; $tryPort++) {
    $fp = @fsockopen($host, $tryPort, $errno, $errstr, 0.1);
    if (!$fp) {
        $port = $tryPort;
        break;
    }
    fclose($fp);
}

// Nombre de log unico por ejecucion: evita locks de Windows cuando un servidor
// de pruebas anterior quedo vivo (zombie) reteniendo el archivo.
$serverLogFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-test-server-' . getmypid() . '.log';

$descriptorspec = [
    0 => ["pipe", "r"],
    1 => ["file", $serverLogFile, "a"],
    2 => ["file", $serverLogFile, "a"]
];

$docRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public';
$routerScript = $docRoot . DIRECTORY_SEPARATOR . 'index.php';

// .env de pruebas: SIN credenciales de servicios externos. El servidor de
// pruebas arranca con este archivo (ENV_FILE), asi que no puede tocar la BD
// real, Mercado Pago ni SMTP por mucho que crezca la suite.
$testEnvFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'usgar-test-env-' . getmypid() . '.env';
file_put_contents($testEnvFile, implode(PHP_EOL, [
    'SITE_URL=http://' . $host . ':' . $port,
    'TIMEZONE=America/Lima',
    'ALLOWED_ORIGINS=*',
    'DB_USER=',   // vacio -> Database no conecta (modo offline)
    'DB_NAME=',
    '',
]));

/**
 * Entorno del proceso hijo: el del padre SIN credenciales de servicios
 * externos, mas ENV_FILE (el .env de pruebas).
 *
 * @return array<string, string>
 */
function testChildEnv(string $envFile): array {
    $env = getenv();
    foreach ([
        'DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASS', 'DB_NAME',
        'MERCADO_PAGO_ACCESS_TOKEN', 'MERCADO_PAGO_WEBHOOK_SECRET', 'PUBLIC_MERCADO_PAGO_PUBLIC_KEY',
        'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS',
        'QLOAPP_API_URL', 'QLOAPP_API_KEY',
    ] as $key) {
        unset($env[$key]);
    }
    $env['ENV_FILE'] = $envFile;
    return $env;
}

// Comando como array: sin cmd.exe en Windows, asi que proc_terminate mata al
// php -S y no deja servidores zombie (el string pasaria por cmd y el hijo
// sobrevivia).
$cmd = [PHP_BINARY, '-S', $host . ':' . $port, '-t', $docRoot, $routerScript];
$process = proc_open($cmd, $descriptorspec, $pipes, dirname(__DIR__), testChildEnv($testEnvFile));

register_shutdown_function(function () use (&$process, &$pipes, $testEnvFile) {
    if (is_resource($process)) {
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            @fclose($pipes[0]);
        }
        @proc_terminate($process);
        @proc_close($process);
    }
    @unlink($testEnvFile);
});

/**
 * GET simple con timeout. curl aborta a los $timeoutSeconds: file_get_contents
 * se queda colgado si el servidor (single-thread) esta ocupado.
 */
function httpGet(string $url, int $timeoutSeconds = 5): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
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
        'headers' => $headersRaw,
        'body' => $body,
    ];
}

$serverReady = false;
for ($i = 0; $i < 30; $i++) {
    usleep(200000);
    $fp = @fsockopen($host, $port, $errno, $errstr, 0.5);
    if ($fp) {
        fclose($fp);
        $probe = httpGet("http://$host:$port/api/health", 3);
        if ($probe['status'] === 200 && str_contains($probe['body'], '"success":true')) {
            $serverReady = true;
            break;
        }
    }
}

if (!$serverReady) {
    echo "    ERROR: El servidor de pruebas en http://$host:$port no pudo iniciar." . PHP_EOL;
    $logContent = file_exists($serverLogFile) ? file_get_contents($serverLogFile) : '(sin log)';
    echo "---- php -S log ----" . PHP_EOL;
    echo ($logContent !== false && trim($logContent) !== '') ? $logContent : '(log vacio)' . PHP_EOL;
    echo "--------------------" . PHP_EOL;
    exit(1);
}

echo "   Servidor de pruebas listo en http://$host:$port" . PHP_EOL;

// Test /api/health
$healthRes = httpGet("http://$host:$port/api/health");
assertTest("Endpoint GET /api/health retorna HTTP 200", $healthRes['status'] === 200);
assertTest("Endpoint GET /api/health retorna JSON válido con success=true", str_contains($healthRes['body'], '"success":true'));

// Aislamiento: el servidor de pruebas arranca sin credenciales de servicios
// (ver ENV_FILE mas arriba), asi que la BD real no se toca y el health lo dice.
assertTest("El servidor de pruebas NO tiene conexion a la BD real (database: offline)",
    str_contains($healthRes['body'], '"database":"offline"'), $healthRes['body']);

// Un endpoint que depende del PMS responde 503 en vez de consultar QloApps.
// Rango unico por ejecucion: la clave de cache de /api/rooms incluye las
// fechas, asi que el test nunca lee una respuesta cacheada de otra corrida.
$pmsOffset = 4000 + (getmypid() % 300) + random_int(0, 200);
$pmsCheckIn = date('Y-m-d', strtotime("+{$pmsOffset} days"));
$pmsCheckOut = date('Y-m-d', strtotime('+' . ($pmsOffset + 1) . ' days'));
$roomsPmsRes = httpGet("http://$host:$port/api/rooms?checkIn={$pmsCheckIn}&checkOut={$pmsCheckOut}");
assertTest("GET /api/rooms sin PMS responde 503 (no consulta el PMS real)",
    $roomsPmsRes['status'] === 503, $roomsPmsRes);

// La validación de entrada no depende de inventario ni disponibilidad del PMS.
$roomsHotelInvalidRes = httpGet("http://$host:$port/api/rooms?id_hotel=0");
$roomsHotelInvalidPayload = json_decode($roomsHotelInvalidRes['body'], true);
assertTest("Endpoint GET /api/rooms con id_hotel=0 retorna HTTP 400", $roomsHotelInvalidRes['status'] === 400, $roomsHotelInvalidRes);
assertTest("Endpoint GET /api/rooms con id_hotel=0 reporta success=false y BAD_REQUEST",
    ($roomsHotelInvalidPayload['success'] ?? null) === false
        && ($roomsHotelInvalidPayload['error']['code'] ?? null) === 'BAD_REQUEST',
    $roomsHotelInvalidPayload);

// Test /api/rooms con rango invalido
$roomsRangeInvalidRes = httpGet("http://$host:$port/api/rooms?checkIn={$validCheckOut}&checkOut={$validCheckIn}");
assertTest("Endpoint GET /api/rooms con rango de fechas invertido retorna HTTP 400", $roomsRangeInvalidRes['status'] === 400);

// Test /api/booking-status sin cart_id (debe fallar)
$bookingStatusInvalidRes = httpGet("http://$host:$port/api/booking-status");
assertTest("Endpoint GET /api/booking-status sin cart_id retorna HTTP 400", $bookingStatusInvalidRes['status'] === 400, $bookingStatusInvalidRes);

foreach (['/api/panel/availability', '/api/auth/me', '/api/user/bookings'] as $privatePath) {
    $privateRes = httpGet("http://$host:$port$privatePath");
    assertTest("$privatePath prohibe almacenar respuestas en cache",
        preg_match('/^Cache-Control:.*\bno-store\b/im', $privateRes['headers']) === 1);
}

if (is_resource($process)) {
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    proc_terminate($process);
    proc_close($process);
}
echo "   Servidor de pruebas apagado." . PHP_EOL;

echo PHP_EOL . "==========================================================" . PHP_EOL;
echo " RESUMEN DE AUDITORIA:" . PHP_EOL;
echo "   Pasados: $passedTests" . PHP_EOL;
echo "   Fallidos: $failedTests" . PHP_EOL;
echo "==========================================================" . PHP_EOL;

if ($failedTests > 0) {
    exit(1);
} else {
    exit(0);
}
