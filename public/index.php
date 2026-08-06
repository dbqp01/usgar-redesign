<?php
declare(strict_types=1);

// Permitir ejecucion en segundo plano para webhooks lentos
if (PHP_SAPI !== 'cli') {
    ob_start();
}

// 1. Cargar Autoloaders (Composer + personalizado) y boot de la aplicacion
// Backend PHP vive en app/ (separado de src/ que es exclusivo para Astro/frontend)
$bootstrapFile = dirname(__DIR__) . '/app/bootstrap.php';
if (file_exists($bootstrapFile)) {
    require_once $bootstrapFile;
} elseif (file_exists(__DIR__ . '/app/bootstrap.php')) {
    require_once __DIR__ . '/app/bootstrap.php';
} else {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Backend app/ folder not found. Please upload app/ directory.']);
    exit;
}

use App\Core\Request;
use App\Core\Router;
use App\Core\Middleware;
use App\Core\Config;

// Importar Clases-Accion ADR (Action-Domain-Responder)
use App\Features\Health\Actions\HealthCheckAction;
use App\Features\Rooms\Actions\GetRoomsAction;
use App\Features\Rooms\Actions\GetRoomsCalendarAction;
use App\Features\Booking\Actions\CreateBookingAction;
use App\Features\Booking\Actions\ExtendHoldAction;
use App\Features\Booking\Actions\GetBookingStatusAction;
use App\Features\Booking\Actions\GetPaymentCheckAction;
use App\Features\Webhooks\Actions\HandleMercadoPagoWebhookAction;
use App\Features\Webhooks\Actions\HandleChannexWebhookAction;
use App\Features\Cron\Actions\CleanExpiredCartsAction;
use App\Features\Cron\Actions\RetryManualReviewAction;
use App\Features\Auth\Actions\AuthLoginAction;
use App\Features\Auth\Actions\AuthProvidersAction;
use App\Features\Auth\Actions\AuthCallbackAction;
use App\Features\Auth\Actions\AuthRegisterAction;
use App\Features\Auth\Actions\AuthLoginEmailAction;
use App\Features\Auth\Actions\AuthMeAction;
use App\Features\Auth\Actions\AuthLogoutAction;
use App\Features\Auth\Actions\GetUserBookingsAction;
use App\Features\Newsletter\Actions\SubscribeNewsletterAction;

// 2. Soporte para ejecuciones desde la linea de comandos (Cron Jobs)
if (PHP_SAPI === 'cli') {
    global $argv;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = $argv[1] ?? '/api/cron/cleanup';
}

// 3. Instanciar Request y Router
$request = new Request();
$router = new Router();

// 4. Configurar Middleware Pipeline (CORS, Security Headers, Rate Limit global)
$middleware = new Middleware();
$middleware
    ->add(Middleware::cors())
    ->add(Middleware::securityHeaders())
    ->add(Middleware::rateLimit((int)Config::get('RATE_LIMIT_MAX_REQUESTS', '300'), (int)Config::get('RATE_LIMIT_WINDOW_SECONDS', '600')));

$router->setMiddleware($middleware);

// 5. Registrar endpoints mapeados a Clases-Accion ADR (SRP extremo)
$router->get('/api/health',           HealthCheckAction::class);
$router->get('/api/rooms',            GetRoomsAction::class);
$router->get('/api/rooms/calendar',   GetRoomsCalendarAction::class);
$router->post('/api/booking',         CreateBookingAction::class);
$router->post('/api/process-payment', \App\Features\Booking\Actions\ProcessPaymentAction::class);
$router->post('/api/extend-hold',     ExtendHoldAction::class);
$router->get('/api/booking-status',   GetBookingStatusAction::class);
$router->get('/api/payment-check',    GetPaymentCheckAction::class);
$router->post('/api/webhook',         HandleMercadoPagoWebhookAction::class);
$router->post('/api/webhook/channex', HandleChannexWebhookAction::class);

// Endpoint de mantenimiento del sistema (Cron)
$router->post('/api/cron/cleanup',       CleanExpiredCartsAction::class);
$router->post('/api/cron/manual-review', RetryManualReviewAction::class);

use App\Features\Auth\Actions\UpdateUserProfileAction;

// Endpoints de Autenticacion y Panel de Huespedes
$router->get('/api/auth/providers',    AuthProvidersAction::class);
$router->get('/api/auth/login',        AuthLoginAction::class);
$router->get('/api/auth/callback',     AuthCallbackAction::class);
$router->post('/api/auth/register',    AuthRegisterAction::class);
$router->post('/api/auth/login-email', AuthLoginEmailAction::class);
$router->get('/api/auth/me',           AuthMeAction::class);
$router->post('/api/auth/logout',      AuthLogoutAction::class);
$router->get('/api/auth/logout',       AuthLogoutAction::class);
$router->get('/api/user/bookings',     GetUserBookingsAction::class);
$router->post('/api/user/profile',     UpdateUserProfileAction::class);
$router->post('/api/newsletter',       SubscribeNewsletterAction::class);

// 6. Despachar la peticion actual
$router->dispatch($request);

