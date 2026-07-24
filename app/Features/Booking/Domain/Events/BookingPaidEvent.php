<?php
declare(strict_types=1);

namespace App\Features\Booking\Domain\Events;

use App\Core\Events\EventInterface;
use DateTimeImmutable;

/**
 * Evento de Dominio: Se ha confirmado un pago de reserva en la plataforma.
 */
class BookingPaidEvent implements EventInterface {
    private string $cartId;
    private string $paymentId;
    private float $amount;
    private string $checkIn;
    private string $checkOut;
    private int $idRoomType;
    private array $guestData;
    private array $roomData;
    private DateTimeImmutable $occurredAt;

    public function __construct(
        string $cartId,
        string $paymentId,
        float $amount,
        string $checkIn = '',
        string $checkOut = '',
        int $idRoomType = 1,
        array $guestData = [],
        array $roomData = []
    ) {
        $this->cartId = $cartId;
        $this->paymentId = $paymentId;
        $this->amount = $amount;
        $this->checkIn = $checkIn;
        $this->checkOut = $checkOut;
        $this->idRoomType = $idRoomType;
        $this->guestData = $guestData;
        $this->roomData = $roomData;
        $this->occurredAt = new DateTimeImmutable();
    }

    public function getName(): string {
        return 'booking.paid';
    }

    public function getCartId(): string {
        return $this->cartId;
    }

    public function getPaymentId(): string {
        return $this->paymentId;
    }

    public function getAmount(): float {
        return $this->amount;
    }

    public function getCheckIn(): string {
        return $this->checkIn;
    }

    public function getCheckOut(): string {
        return $this->checkOut;
    }

    public function getIdRoomType(): int {
        return $this->idRoomType;
    }

    public function getGuestData(): array {
        return $this->guestData;
    }

    public function getRoomData(): array {
        return $this->roomData;
    }

    public function getPayload(): array {
        return [
            'cart_id'      => $this->cartId,
            'payment_id'   => $this->paymentId,
            'amount'       => $this->amount,
            'checkin'      => $this->checkIn,
            'checkout'     => $this->checkOut,
            'id_room_type' => $this->idRoomType,
            'guest_data'   => $this->guestData,
            'room_data'    => $this->roomData,
        ];
    }

    public function getOccurredAt(): DateTimeImmutable {
        return $this->occurredAt;
    }
}
