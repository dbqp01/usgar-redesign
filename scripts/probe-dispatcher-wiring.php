<?php
// Verificación: ¿el EventDispatcher inyectado por el Container a las actions
// es el MISMO singleton donde bootstrap registra los listeners?
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Container;
use App\Core\Events\EventDispatcher;
use App\Features\Booking\Actions\ProcessPaymentAction;
use App\Features\Webhooks\Actions\HandleMercadoPagoWebhookAction;
use App\Features\Cron\Actions\ReconcilePaymentsAction;
use ReflectionProperty;

$singleton = EventDispatcher::getInstance();

// 1. ¿El singleton tiene los listeners registrados por bootstrap?
$rp = new ReflectionProperty(EventDispatcher::class, 'listeners');
$rp->setAccessible(true);
$listeners = $rp->getValue($singleton);
echo "Listeners en singleton: " . json_encode(array_map(fn($l) => count($l), $listeners)) . "\n";

// 2. ¿Qué dispatcher recibe cada action al ser construida por el Container?
foreach ([
    ProcessPaymentAction::class,
    HandleMercadoPagoWebhookAction::class,
    ReconcilePaymentsAction::class,
] as $class) {
    $action = Container::getInstance()->get($class);
    $rp2 = new ReflectionProperty($class, 'eventDispatcher');
    $rp2->setAccessible(true);
    $d = $rp2->getValue($action);
    $isSame = ($d === $singleton);
    $n = count($rp->getValue($d));
    echo $class . ": mismo singleton? " . var_export($isSame, true) . " | listeners en su dispatcher: {$n}\n";
}
