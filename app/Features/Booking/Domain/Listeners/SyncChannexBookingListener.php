<?php
declare(strict_types=1);

namespace App\Features\Booking\Domain\Listeners;

use App\Core\Events\EventInterface;
use App\Core\Events\ListenerInterface;
use App\Core\Logger;
use App\Features\Booking\Domain\Events\BookingPaidEvent;
use App\Features\Shared\Ports\ChannelManagerPortInterface;
use App\Features\Shared\Adapters\ChannexAdapter;
use Exception;

/**
 * Listener que reacciona a BookingPaidEvent notificando al Channel Manager (Channex) para actualizar inventario en OTAs.
 */
class SyncChannexBookingListener implements ListenerInterface {
    private ChannelManagerPortInterface $channexAdapter;

    public function __construct(?ChannelManagerPortInterface $channexAdapter = null) {
        $this->channexAdapter = $channexAdapter ?? new ChannexAdapter();
    }

    public function handle(EventInterface $event): void {
        if (!($event instanceof BookingPaidEvent)) {
            return;
        }

        $cartId     = $event->getCartId();
        $checkIn    = $event->getCheckIn();
        $checkOut   = $event->getCheckOut();
        $idRoomType = $event->getIdRoomType();
        $amount     = $event->getAmount();
        $guestData  = $event->getGuestData();

        $guestName  = (string)($guestData['name'] ?? 'Huésped USGAR');
        $guestEmail = (string)($guestData['email'] ?? 'reserva@hotelesusgar.com');
        $guestPhone = (string)($guestData['phone'] ?? '');
        $adults     = (int)($guestData['guests'] ?? 2);

        if (empty($checkIn)) {
            $checkIn = date('Y-m-d');
        }
        if (empty($checkOut)) {
            $checkOut = date('Y-m-d', strtotime('+1 day'));
        }

        Logger::info("SyncChannexBookingListener: Notificando reserva a Channex para Cart ID {$cartId}");

        try {
            $channexResult = $this->channexAdapter->createBooking(
                $cartId,
                $checkIn,
                $checkOut,
                $idRoomType,
                $amount,
                $guestName,
                $guestEmail,
                $guestPhone,
                $adults
            );

            Logger::info("SyncChannexBookingListener: Reserva sincronizada en Channex exitosamente para Cart ID {$cartId}", [
                'channex_result' => $channexResult
            ]);
        } catch (Exception $e) {
            Logger::error("SyncChannexBookingListener Error al sincronizar reserva en Channex: " . $e->getMessage(), [
                'cart_id' => $cartId,
            ]);
        }
    }
}
