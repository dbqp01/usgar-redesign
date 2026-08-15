<?php
declare(strict_types=1);

namespace App\Test\Unit\Core\Events;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Core\Container;
use App\Core\Events\EventDispatcher;
use ReflectionProperty;

/**
 * Test de regresión del WIRING del EventDispatcher (bug 2026-08-15, primera
 * venta real).
 *
 * El bug: Container::get() por autowiring crea instancias NUEVAS (no cachea
 * las resueltas por reflexión — solo bind()/set() cachean), así que las
 * actions HTTP (ProcessPaymentAction, HandleMercadoPagoWebhookAction,
 * RetryManualReviewAction) recibían un EventDispatcher SIN los listeners que
 * bootstrap.php registra en EventDispatcher::getInstance(). dispatch() hacía
 * return temprano (empty listeners) y el INSERT en event_outbox NUNCA ocurría:
 * hold paid pero el cron process_outbox no tenía nada que entregar (QloApps
 * sin orden + sin email de confirmación).
 *
 * El fix: bootstrap.php registra el singleton en el Container
 * ($container->set(EventDispatcher::class, EventDispatcher::getInstance())).
 *
 * Este test carga el bootstrap REAL en proceso separado (BD anulada → el
 * listener de QloApps no se registra, pero el de email sí — sin red) y
 * verifica que el Container resuelve el MISMO objeto singleton que
 * bootstrap subscribió. Falla antes del fix, pasa después.
 */
final class EventDispatcherWiringTest extends TestCase {
    #[RunInSeparateProcess]
    public function testContainerResolvesTheRegisteredEventDispatcherSingleton(): void {
        // Anular BD para que bootstrap no intente conectar (hermético, sin red).
        Config::set('DB_HOST', '127.0.0.1');
        Config::set('DB_PORT', '3399');
        Config::set('DB_USER', 'none');
        Config::set('DB_PASS', 'none');
        Config::set('DB_NAME', 'none');

        require_once dirname(__DIR__, 4) . '/app/bootstrap.php';

        $resolved = Container::getInstance()->get(EventDispatcher::class);

        // El Container debe resolver el MISMO singleton donde bootstrap
        // registra los listeners de booking.paid.
        $this->assertSame(EventDispatcher::getInstance(), $resolved);

        // Y ese singleton debe tener listeners de booking.paid (al menos el
        // de email, que se registra sin BD).
        $rp = new ReflectionProperty(EventDispatcher::class, 'listeners');
        $rp->setAccessible(true);
        $listeners = $rp->getValue($resolved);
        $this->assertNotEmpty($listeners['booking.paid'] ?? []);
    }
}
