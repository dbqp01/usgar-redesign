<?php
declare(strict_types=1);

// Hide the PHP runtime fingerprint for local PHP servers and any SAPI where
// Apache/LiteSpeed cannot apply public/.htaccess (production also unsets this
// header there for static Astro responses).
if (function_exists('header_remove')) {
    header_remove('X-Powered-By');
}

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
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'INTERNAL_ERROR',
            'message' => 'Backend app/ folder not found.',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
use App\Features\Newsletter\Actions\ConfirmNewsletterAction;
use App\Features\Newsletter\Actions\SubscribeNewsletterAction;
use App\Features\Newsletter\Actions\UnsubscribeNewsletterAction;
use App\Features\Contact\Actions\SubmitContactAction;
use App\Features\Panel\Actions\PanelLoginAction;
use App\Features\Panel\Actions\PanelLogoutAction;
use App\Features\Panel\Actions\GetAvailabilityAction;
use App\Features\Panel\Actions\ExportAvailabilityAction;
use App\Features\Panel\Actions\ImportAvailabilityAction;
use App\Features\Panel\Actions\PanelBookingAction;
use App\Features\Panel\Actions\PanelBlockAction;
use App\Features\Panel\Actions\PanelUnblockAction;
use App\Features\Panel\Actions\PanelRoomsAvailabilityAction;
use App\Features\Auth\Actions\UpdateUserProfileAction;
use App\Features\Reviews\Actions\GetReviewScoresAction;
use App\Features\Reviews\Actions\RefreshReviewScoresAction;

// 2. Soporte para ejecuciones desde la linea de comandos (Cron Jobs)
if (PHP_SAPI === 'cli') {
    global $argv;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $cliRequestUri = $argv[1] ?? '/api/cron/cleanup';
    if (!is_string($cliRequestUri) || trim($cliRequestUri) === '') {
        $cliRequestUri = '/api/cron/cleanup';
    }
    $_SERVER['REQUEST_URI'] = $cliRequestUri;

    // Keep CLI dispatch equivalent to HTTP: Request also parses REQUEST_URI,
    // but populating $_GET here supports any legacy code that reads it directly.
    $cliQuery = parse_url($cliRequestUri, PHP_URL_QUERY);
    if (is_string($cliQuery) && $cliQuery !== '') {
        parse_str($cliQuery, $cliQueryParams);
        $_GET = is_array($cliQueryParams) ? $cliQueryParams : [];
    } else {
        $_GET = [];
    }
}

// 3. Instanciar Request y Router
$request = new Request();
if (str_starts_with($request->getPath(), '/api/panel/')
    || str_starts_with($request->getPath(), '/api/auth/')
    || str_starts_with($request->getPath(), '/api/user/')) {
    header('Cache-Control: private, no-store');
}
$router = new Router();

// 4. Configurar Middleware Pipeline (CORS, Security Headers, Rate Limit global)
$middleware = new Middleware();
$middleware
    ->add(Middleware::cors())
    ->add(Middleware::securityHeaders())
    ->add(Middleware::requestId())
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

// Endpoint de mantenimiento del sistema (Cron)
$router->post('/api/cron/cleanup',       CleanExpiredCartsAction::class);
$router->post('/api/cron/manual-review', RetryManualReviewAction::class);
$router->post('/api/cron/refresh-reviews', RefreshReviewScoresAction::class);

// Scores de reseñas de plataformas (ver cron refresh-reviews)
$router->get('/api/reviews-score',       GetReviewScoresAction::class);

// Endpoints de Autenticacion y Panel de Huespedes
$router->get('/api/auth/providers',    AuthProvidersAction::class);
$router->get('/api/auth/login',        AuthLoginAction::class);
$router->get('/api/auth/callback',     AuthCallbackAction::class);
$router->post('/api/auth/register',    AuthRegisterAction::class);
$router->post('/api/auth/login-email', AuthLoginEmailAction::class);
$router->get('/api/auth/me',           AuthMeAction::class);
$router->post('/api/auth/logout',      AuthLogoutAction::class);
$router->get('/api/user/bookings',     GetUserBookingsAction::class);
$router->post('/api/user/profile',     UpdateUserProfileAction::class);
$router->post('/api/newsletter',       SubscribeNewsletterAction::class);
$router->get('/api/newsletter/confirm',    ConfirmNewsletterAction::class);
$router->get('/api/newsletter/unsubscribe', UnsubscribeNewsletterAction::class);
$router->post('/api/newsletter/unsubscribe', UnsubscribeNewsletterAction::class);
$router->post('/api/contact',          SubmitContactAction::class);

// Panel de disponibilidad del dueno (protegido por cookie usgar_panel)
$router->post('/api/panel/login',        PanelLoginAction::class);
$router->post('/api/panel/logout',       PanelLogoutAction::class);
$router->get('/api/panel/availability',  GetAvailabilityAction::class);
$router->get('/api/panel/export',        ExportAvailabilityAction::class);
$router->post('/api/panel/import',       ImportAvailabilityAction::class);
// Rediseno 2026-08-15: wizard de reserva manual + bloqueos del dueno
$router->get('/api/panel/rooms-availability', PanelRoomsAvailabilityAction::class);
$router->post('/api/panel/booking',      PanelBookingAction::class);
$router->post('/api/panel/block',        PanelBlockAction::class);
$router->delete('/api/panel/block',      PanelUnblockAction::class);

// 6. Despachar la peticion actual
$router->dispatch($request);

