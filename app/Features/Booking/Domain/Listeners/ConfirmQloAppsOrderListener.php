<?php
declare(strict_types=1);

namespace App\Features\Booking\Domain\Listeners;

use App\Core\Events\EventInterface;
use App\Core\Events\ListenerInterface;
use App\Core\Logger;
use App\Features\Booking\Domain\Events\BookingPaidEvent;
use App\Features\Shared\Ports\PmsPortInterface;
use App\Features\Shared\Adapters\QloAppAdapter;
use Exception;

/**
 * Listener que reacciona a BookingPaidEvent confirmando la orden en QloApps PMS.
 */
class ConfirmQloAppsOrderListener implements ListenerInterface {
    private PmsPortInterface $pmsAdapter;

    public function __construct(?PmsPortInterface $pmsAdapter = null) {
        $this->pmsAdapter = $pmsAdapter ?? new QloAppAdapter();
    }

    public function handle(EventInterface $event): void {
        if (!($event instanceof BookingPaidEvent)) {
            return;
        }

        $cartId    = $event->getCartId();
        $paymentId = $event->getPaymentId();
        $amount    = $event->getAmount();
        $guestData = $event->getGuestData();

        $guestName  = (string)($guestData['name'] ?? 'Huésped USGAR');
        $guestEmail = (string)($guestData['email'] ?? 'reserva@hotelesusgar.com');

        Logger::info("ConfirmQloAppsOrderListener: Procesando confirmación en QloApps para Cart ID {$cartId} (Monto: {$amount})");

        try {
            $orderResult = $this->pmsAdapter->confirmOrder($cartId, $amount, $guestName, $guestEmail);
            Logger::info("ConfirmQloAppsOrderListener: Orden generada en QloApps exitosamente para Cart ID {$cartId}", [
                'order_result' => $orderResult
            ]);
        } catch (Exception $e) {
            Logger::error("ConfirmQloAppsOrderListener Error al confirmar orden en QloApps PMS: " . $e->getMessage(), [
                'cart_id'    => $cartId,
                'payment_id' => $paymentId,
            ]);
            throw $e;
        }
    }
}
