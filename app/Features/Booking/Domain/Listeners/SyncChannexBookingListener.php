<?php
declare(strict_types=1);

namespace App\Features\Booking\Domain\Listeners;

use App\Core\Events\EventInterface;
use App\Core\Events\ListenerInterface;
use App\Core\Logger;
use App\Core\Config;
use App\Features\Booking\Domain\Events\BookingPaidEvent;
use App\Features\Shared\Ports\ChannelManagerPortInterface;

/**
 * Listener que reacciona a BookingPaidEvent notificando al Channel Manager
 * (Channex) para actualizar inventario en OTAs.
 *
 * Todo 22 (Wave 4):
 *  - 1 intento + throw: ChannexAdapter ya NO traga errores (createBooking
 *    lanza exception en vez de devolver false); la excepcion propaga -> el
 *    outbox reintenta via el cron process_outbox (todo 19). Se elimina el
 *    retry loop interno y el chequeo `!$result -> return null`.
 *  - DEDUP del consumidor: antes de crear, consulta si ya existe booking con
 *    external_reference = USGAR-{cartId}; si existe -> no recrear (la ventana
 *    crash entre un createBooking exitoso y el write COMPLETED del outbox
 *    dejaria el evento IN_PROGRESS y el reclaim del todo 19 lo re-entregaria).
 *    FAIL-CLOSED: si el pre-chequeo lanza (Channex caido), la excepcion
 *    propaga — nunca "no existe" por error (recrearia y duplicaria).
 *  - Todo 25: Channex recibe monto PEN (amount_pen), no USD.
 */
class SyncChannexBookingListener implements ListenerInterface {
    private ChannelManagerPortInterface $channexAdapter;

    public function __construct(ChannelManagerPortInterface $channexAdapter) {
        $this->channexAdapter = $channexAdapter;
    }

    public function handle(EventInterface $event): void {
        if (!($event instanceof BookingPaidEvent)) {
            return;
        }

        $cartId     = $event->getCartId();
        $checkIn    = $event->getCheckIn();
        $checkOut   = $event->getCheckOut();
        $idRoomType = $event->getIdRoomType();
        $amountPen  = $event->getAmountPen() / 100; // PEN float para el PMS (todo 25)
        $guestData  = $event->getGuestData();

        $guestName  = (string)($guestData['name'] ?? Config::get('CHANNEX_DEFAULT_GUEST_NAME', 'Huesped USGAR'));
        $guestEmail = (string)($guestData['email'] ?? Config::get('DEFAULT_GUEST_EMAIL'));
        $guestPhone = (string)($guestData['phone'] ?? '');
        $adults     = (int)($guestData['guests'] ?? 2);

        if (empty($checkIn)) {
            $checkIn = date('Y-m-d');
        }
        if (empty($checkOut)) {
            $checkOut = date('Y-m-d', strtotime('+1 day'));
        }

        Logger::info("SyncChannexBookingListener: Notificando reserva a Channex para Cart ID {$cartId} (Monto PEN: {$amountPen})");

        // TODO 22: DEDUP del consumidor. FAIL-CLOSED: si el pre-chequeo lanza
        // (Channex caido), la excepcion propaga -> outbox reintenta; nunca
        // recrear por un falso "no existe".
        $existing = $this->channexAdapter->findBookingByExternalReference('USGAR-' . $cartId);
        if ($existing !== null) {
            Logger::info("SyncChannexBookingListener: Booking con external_reference USGAR-{$cartId} YA existe en Channex — dedup-skip (sin recrear).");
            return;
        }

        // 1 intento + throw (el retry lo gestiona el outbox, todo 19).
        $channexResult = $this->channexAdapter->createBooking(
            $cartId,
            $checkIn,
            $checkOut,
            $idRoomType,
            $amountPen,
            $guestName,
            $guestEmail,
            $guestPhone,
            $adults
        );

        Logger::info("SyncChannexBookingListener: Reserva sincronizada en Channex exitosamente para Cart ID {$cartId}", [
            'channex_result' => $channexResult,
        ]);
    }
}
