<?php
declare(strict_types=1);

namespace App\Features\Booking\Domain\Listeners;

use App\Core\Events\EventInterface;
use App\Core\Events\ListenerInterface;
use App\Core\Logger;
use App\Core\Config;
use App\Features\Booking\Domain\Events\BookingPaidEvent;
use App\Features\Shared\Ports\ChannelManagerPortInterface;
use Exception;

/**
 * Listener que reacciona a BookingPaidEvent notificando al Channel Manager (Channex) para actualizar inventario en OTAs.
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
        $amount     = $event->getAmount();
        $guestData  = $event->getGuestData();

        $guestName  = (string)($guestData['name'] ?? 'Huesped USGAR');
        $guestEmail = (string)($guestData['email'] ?? Config::get('DEFAULT_GUEST_EMAIL'));
        $guestPhone = (string)($guestData['phone'] ?? '');
        $adults     = (int)($guestData['guests'] ?? 2);

        if (empty($checkIn)) {
            $checkIn = date('Y-m-d');
        }
        if (empty($checkOut)) {
            $checkOut = date('Y-m-d', strtotime('+1 day'));
        }

        Logger::info("SyncChannexBookingListener: Notificando reserva a Channex para Cart ID {$cartId}");

        $maxRetries = 3;
        $attempt = 0;
        $success = false;

        while ($attempt < $maxRetries && !$success) {
            $attempt++;
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

                if (!$channexResult) {
                    Logger::error("SyncChannexBookingListener: Fallo lógico en Channex (retornó false), no se reintentará. Cart ID {$cartId}");
                    break;
                }

                Logger::info("SyncChannexBookingListener: Reserva sincronizada en Channex exitosamente para Cart ID {$cartId} (Intento {$attempt})", [
                    'channex_result' => $channexResult
                ]);
                $success = true;
            } catch (Exception $e) {
                Logger::error("SyncChannexBookingListener Error al sincronizar reserva en Channex (Intento {$attempt}/{$maxRetries}): " . $e->getMessage(), [
                    'cart_id' => $cartId,
                ]);
                
                if ($attempt >= $maxRetries) {
                    throw $e;
                }
                // Backoff exponencial simple
                sleep(2 ** ($attempt - 1));
            }
        }
    }
}
