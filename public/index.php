<?php
declare(strict_types=1);

// Permitir ejecución en segundo plano para webhooks lentos
if (PHP_SAPI !== 'cli') {
    ob_start();
}

// 1. Cargar el Autoloader personalizado (Compatibilidad nativa con Hostinger sin Composer)
require_once __DIR__ . '/../src/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src');

use App\Core\Config;
use App\Core\Request;
use App\Core\Router;
use App\Core\Middleware;
use App\Controllers\RoomController;
use App\Controllers\BookingController;
use App\Controllers\WebhookController;
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

// Endpoint de mantenimiento del sistema (Cron)
$router->post('/api/cron/cleanup',   [CronController::class, 'cleanup']);
$router->get('/api/cron/cleanup',    [CronController::class, 'cleanup']);

// 7. Despachar la petición actual
$router->dispatch($request);
