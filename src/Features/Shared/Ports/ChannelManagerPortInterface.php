<?php
declare(strict_types=1);

namespace App\Features\Shared\Ports;

/**
 * Puerto de abstracción para la interacción con Channel Managers (Channex).
 */
interface ChannelManagerPortInterface {
    public function pushAvailability(
        int $idRoomType,
        string $checkIn,
        string $checkOut,
        float $totalPrice,
        int $availabilityQty = 1
    ): bool;

    public function processChannelBooking(array $bookingData): bool;

    public function createBooking(
        string $bookingId,
        string $checkIn,
        string $checkOut,
        int $idRoomType,
        float $totalPrice,
        string $guestName,
        string $guestEmail,
        string $guestPhone = '',
        int $adults = 2
    ): bool;
}
