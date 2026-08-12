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
use App\Features\Shared\Adapters\MercadoPagoAdapter;
use App\Features\Shared\Adapters\QloAppAdapter;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use App\Features\Shared\Ports\PmsPortInterface;

// Autoloader de Composer (PSR-4: App\ => app/, + dependencias). Es el unico
// autoloader del proyecto desde 2026-08-10: el Autoloader.php casero quedo
// eliminado al declarar "autoload" psr-4 en composer.json.
if (!class_exists(\Composer\Autoload\ClassLoader::class)) {
    $vendorAutoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($vendorAutoload)) {
        require_once $vendorAutoload;
    }
}

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
// PaymentGateway no requiere BD (adaptador HTTP)
$container->bind(PaymentGatewayPortInterface::class, fn($c) => new MercadoPagoAdapter());

// PMS requiere BD — solo registrar si hay conexion disponible
if ($dbConnection !== null) {
    $container->bind(PmsPortInterface::class, fn($c) => new QloAppAdapter($c->get(PDO::class)));
} else {
    $container->bind(PmsPortInterface::class, fn($c) => new QloAppAdapter(null));
}

// Listeners de dominio (webhook -> PMS / Channel Manager) con DIP desde el container
$eventDispatcher = EventDispatcher::getInstance();
if ($dbConnection !== null) {
    $eventDispatcher->subscribe('booking.paid', new ConfirmQloAppsOrderListener($container->get(PmsPortInterface::class)));
}

/**
 * Acceso global al container.
 *
 * @return Container
 */
function app(): Container {
    return Container::getInstance();
}
