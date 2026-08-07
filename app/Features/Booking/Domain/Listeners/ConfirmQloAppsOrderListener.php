<?php
declare(strict_types=1);

namespace App\Features\Booking\Domain\Listeners;

use App\Core\Events\EventInterface;
use App\Core\Events\ListenerInterface;
use App\Core\Logger;
use App\Core\Config;
use App\Features\Booking\Domain\Events\BookingPaidEvent;
use App\Features\Shared\Ports\PmsPortInterface;
use RuntimeException;

/**
 * Listener que reacciona a BookingPaidEvent confirmando la orden en QloApps PMS.
 *
 * Todo 21 (Wave 4):
 *  - 1 intento + THROW ante confirmOrder null (el outbox reintenta via
 *    el cron process_outbox — todo 19). Se elimina el retry loop interno.
 *  - DEDUP del consumidor: UNICO mecanismo = pre-chequeo por
 *    external_reference = USGAR-{cartId}. Si la orden YA esta confirmada en
 *    QloApps -> skip/exito SIN llamar confirmOrder (la ventana crash entre un
 *    confirmOrder exitoso y el write COMPLETED del outbox deja el evento
 *    IN_PROGRESS y el reclaim del todo 19 lo re-entrega -> doble confirm sin
 *    el dedup).
 *  - FAIL-CLOSED en el pre-chequeo: si el pre-chequeo mismo falla (PMS caido)
 *    THROW -> outbox reintenta; nunca skip por error. El null de un
 *    confirmOrder real SIEMPRE hace throw (el dedup-skip es un camino de
 *    exito distinto que NO pasa por el throw-on-null).
 *  - Todo 25: el PMS recibe monto PEN (amount_pen), no USD.
 */
class ConfirmQloAppsOrderListener implements ListenerInterface {
    private PmsPortInterface $pmsAdapter;

    public function __construct(PmsPortInterface $pmsAdapter) {
        $this->pmsAdapter = $pmsAdapter;
    }

    public function handle(EventInterface $event): void {
        if (!($event instanceof BookingPaidEvent)) {
            return;
        }

        $cartId    = $event->getCartId();
        $paymentId = $event->getPaymentId();
        $amountPen = $event->getAmountPen() / 100; // PEN float para el PMS (todo 25)
        $guestData = $event->getGuestData();

        $guestName  = (string)($guestData['name'] ?? Config::get('QLOAPPS_DEFAULT_GUEST_NAME', 'Huésped USGAR'));
        $guestEmail = (string)($guestData['email'] ?? Config::get('DEFAULT_GUEST_EMAIL'));

        Logger::info("ConfirmQloAppsOrderListener: Procesando confirmación en QloApps para Cart ID {$cartId} (Monto PEN: {$amountPen})");

        // TODO 21: DEDUP del consumidor (mecanismo UNICO). FAIL-CLOSED: si el
        // pre-chequeo lanza (PMS caido), la excepcion propaga -> el outbox
        // reintenta — nunca skip por error.
        $externalReference = 'USGAR-' . $cartId;
        if ($this->pmsAdapter->isOrderConfirmed($externalReference)) {
            Logger::info("ConfirmQloAppsOrderListener: Orden con external_reference {$externalReference} YA confirmada en QloApps — dedup-skip (sin llamar confirmOrder).");
            return;
        }

        // 1 intento + throw (el retry lo gestiona el outbox, todo 19).
        $orderResult = $this->pmsAdapter->confirmOrder($cartId, $amountPen, $guestName, $guestEmail);

        if ($orderResult === null) {
            Logger::error("ConfirmQloAppsOrderListener Error: confirmOrder devolvio resultado vacio para Cart ID {$cartId} (payment {$paymentId})");
            throw new RuntimeException("QloApps confirmOrder fallo para cart {$cartId}: resultado null.");
        }

        Logger::info("ConfirmQloAppsOrderListener: Orden generada en QloApps exitosamente para Cart ID {$cartId}", [
            'order_result' => $orderResult,
            'payment_id'   => $paymentId,
        ]);
    }
}
