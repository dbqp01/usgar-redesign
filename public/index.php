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

// Importar Clases-Acción ADR (Action-Domain-Responder)
use App\Features\Health\Actions\HealthCheckAction;
use App\Features\Rooms\Actions\GetRoomsAction;
use App\Features\Booking\Actions\CreateBookingAction;
use App\Features\Booking\Actions\ExtendHoldAction;
use App\Features\Booking\Actions\GetBookingStatusAction;
use App\Features\Webhooks\Actions\HandleMercadoPagoWebhookAction;
use App\Features\Webhooks\Actions\HandleChannexWebhookAction;
use App\Features\Cron\Actions\CleanExpiredCartsAction;
use App\Features\Auth\Actions\AuthLoginAction;
use App\Features\Auth\Actions\AuthCallbackAction;
use App\Features\Auth\Actions\AuthRegisterAction;
use App\Features\Auth\Actions\AuthLoginEmailAction;
use App\Features\Auth\Actions\AuthMeAction;
use App\Features\Auth\Actions\AuthLogoutAction;
use App\Features\Auth\Actions\GetUserBookingsAction;

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

// 6. Registrar endpoints mapeados a Clases-Acción ADR (SRP extremo)
$router->get('/api/health',           HealthCheckAction::class);
$router->get('/api/rooms',            GetRoomsAction::class);
$router->post('/api/booking',         CreateBookingAction::class);
$router->post('/api/extend-hold',     ExtendHoldAction::class);
$router->get('/api/booking-status',   GetBookingStatusAction::class);
$router->post('/api/webhook',         HandleMercadoPagoWebhookAction::class);
$router->post('/api/webhook/channex', HandleChannexWebhookAction::class);

// Endpoint de mantenimiento del sistema (Cron)
$router->post('/api/cron/cleanup',    CleanExpiredCartsAction::class);

// Endpoints de Autenticación y Panel de Huéspedes
$router->get('/api/auth/login',        AuthLoginAction::class);
$router->get('/api/auth/callback',     AuthCallbackAction::class);
$router->post('/api/auth/register',    AuthRegisterAction::class);
$router->post('/api/auth/login-email', AuthLoginEmailAction::class);
$router->get('/api/auth/me',           AuthMeAction::class);
$router->post('/api/auth/logout',      AuthLogoutAction::class);
$router->get('/api/user/bookings',     GetUserBookingsAction::class);

// 7. Despachar la petición actual
$router->dispatch($request);
