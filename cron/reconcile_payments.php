<?php
declare(strict_types=1);

/**
 * Cron de reconciliacion de pagos pendientes.
 * Consulta MercadoPago por holds cuyo webhook nunca llego y completa el flujo.
 * Uso: php cron/reconcile_payments.php (cada 10 minutos)
 */

// Solo CLI (cron): nunca ejecucion via web (dist publicado expone cron/).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Container;
use App\Core\Database;
use App\Core\Events\EventDispatcher;
use App\Features\Cron\Actions\ReconcilePaymentsAction;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;

$pdo = Database::getInstance()->getConnection();
if ($pdo === null) {
    echo "No database connection.\n";
    exit(1);
}

$container = Container::getInstance();

$action = new ReconcilePaymentsAction(
    $pdo,
    $container->get(PaymentGatewayPortInterface::class),
    new ProvisionalBookingRepository($pdo),
    EventDispatcher::getInstance()
);

$result = $action();
echo "Reconciliacion completada: checked={$result['checked']}, reconciled={$result['reconciled']}, skipped={$result['skipped']}\n";
exit(0);
