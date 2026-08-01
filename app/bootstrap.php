<?php
declare(strict_types=1);

/**
 * Bootstrap compartido de la aplicacion.
 * Fuente unica de verdad para: autoloaders, Config, Container, bindings de
 * interfaces y registro de listeners de dominio.
 * Usado por public/index.php y cron/process_outbox.php.
 */

use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Events\EventDispatcher;
use App\Features\Booking\Domain\Listeners\ConfirmQloAppsOrderListener;
use App\Features\Booking\Domain\Listeners\SyncChannexBookingListener;
use App\Features\Shared\Adapters\ChannexAdapter;
use App\Features\Shared\Adapters\MercadoPagoAdapter;
use App\Features\Shared\Adapters\QloAppAdapter;
use App\Features\Shared\Ports\ChannelManagerPortInterface;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use App\Features\Shared\Ports\PmsPortInterface;

if (!class_exists('App\Core\Autoloader')) {
    require_once __DIR__ . '/Core/Autoloader.php';
}
if (!class_exists(\Composer\Autoload\ClassLoader::class)) {
    $vendorAutoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($vendorAutoload)) {
        require_once $vendorAutoload;
    }
}
\App\Core\Autoloader::register(__DIR__);

// Inicializar configuracion centralizada (parsea .env)
Config::boot();

// Container PSR-11 con autowiring
$container = Container::getInstance();

// Registrar conexion PDO
$dbConnection = Database::getInstance()->getConnection();
if ($dbConnection !== null) {
    $container->set(PDO::class, $dbConnection);
}

// Bindings interfaz -> implementacion (DIP)
$container->bind(PmsPortInterface::class, fn($c) => new QloAppAdapter($c->get(PDO::class)));
$container->bind(PaymentGatewayPortInterface::class, fn($c) => new MercadoPagoAdapter());
$container->bind(ChannelManagerPortInterface::class, fn($c) => new ChannexAdapter());

// Listeners de dominio (webhook -> PMS / Channel Manager) con DIP desde el container
$eventDispatcher = EventDispatcher::getInstance();
$eventDispatcher->subscribe('booking.paid', new ConfirmQloAppsOrderListener($container->get(PmsPortInterface::class)));
$eventDispatcher->subscribe('booking.paid', new SyncChannexBookingListener($container->get(ChannelManagerPortInterface::class)));

/**
 * Acceso global al container.
 *
 * @return Container
 */
function app(): Container {
    return Container::getInstance();
}
