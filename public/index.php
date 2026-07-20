<?php
declare(strict_types=1);

// Permitir ejecución en segundo plano para webhooks lentos
if (PHP_SAPI !== 'cli') {
    ob_start();
}

// 1. Cargar el Autoloader personalizado (Compatibilidad nativa con Hostinger sin Composer)
if (file_exists(__DIR__ . '/../src/Core/Autoloader.php')) {
    require_once __DIR__ . '/../src/Core/Autoloader.php';
    \App\Core\Autoloader::register(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src');
} elseif (file_exists(__DIR__ . '/src/Core/Autoloader.php')) {
    require_once __DIR__ . '/src/Core/Autoloader.php';
    \App\Core\Autoloader::register(__DIR__ . DIRECTORY_SEPARATOR . 'src');
} else {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Backend src/ folder not found. Please upload src/ directory.']);
    exit;
}

use App\Core\Config;
use App\Core\Request;
use App\Core\Router;
use App\Core\Middleware;
use App\Controllers\RoomController;
use App\Controllers\BookingController;
use App\Controllers\WebhookController;
use App\Controllers\ChannexWebhookController;
use App\Controllers\CronController;
use App\Controllers\HealthController;

// 2. Inicializar configuración centralizada
Config::boot();

// 3. Soporte para ejecuciones desde la línea de comandos (Cron Jobs)
if (PHP_SAPI === 'cli') {
    global $argv;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = $argv[1] ?? '/api/cron/cleanup';
}

// 4. Instanciar Request y Router
$request = new Request();
$router = new Router();

// 5. Configurar Middleware Pipeline (CORS, Security Headers, Rate Limit global)
$middleware = new Middleware();
$middleware
    ->add(Middleware::cors())
    ->add(Middleware::securityHeaders())
    ->add(Middleware::rateLimit(60, 600));

$router->setMiddleware($middleware);

// 6. Registrar endpoints de la API REST de USGAR Hotels
$router->get('/api/health',          [HealthController::class, 'index']);
$router->get('/api/rooms',           [RoomController::class, 'index']);
$router->post('/api/booking',        [BookingController::class, 'create']);
$router->post('/api/extend-hold',    [BookingController::class, 'extend']);
$router->get('/api/booking-status',  [BookingController::class, 'status']);
$router->post('/api/webhook',        [WebhookController::class, 'handle']);
$router->post('/api/webhook/channex', [ChannexWebhookController::class, 'handle']);

// Endpoint de mantenimiento del sistema (Cron - Exclusivo POST y CLI)
$router->post('/api/cron/cleanup',   [CronController::class, 'cleanup']);

// Endpoints de Autenticación y Panel de Huéspedes
$router->get('/api/auth/login',        [\App\Controllers\AuthController::class, 'login']);
$router->get('/api/auth/callback',     [\App\Controllers\AuthController::class, 'callback']);
$router->post('/api/auth/register',    [\App\Controllers\AuthController::class, 'register']);
$router->post('/api/auth/login-email', [\App\Controllers\AuthController::class, 'loginEmail']);
$router->get('/api/auth/me',           [\App\Controllers\AuthController::class, 'me']);
$router->post('/api/auth/logout',      [\App\Controllers\AuthController::class, 'logout']);
$router->get('/api/user/bookings',     [\App\Controllers\AuthController::class, 'bookings']);

// 7. Despachar la petición actual
$router->dispatch($request);
