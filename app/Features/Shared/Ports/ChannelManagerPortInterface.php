<?php
declare(strict_types=1);

namespace App\Features\Shared\Ports;

/**
 * Puerto de abstraccion para la interaccion con Channel Managers.
 */
interface ChannelManagerPortInterface {
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

    public function fetchBookingRevision(string $revisionId): ?array;

    public function acknowledgeRevision(string $revisionId): bool;

    /**
     * Dedup del consumidor (todo 22): consulta si ya existe un booking con la
     * external_reference dada (USGAR-{cartId}). Devuelve el booking o null si
     * no existe. FAIL-CLOSED: ante fallo de transporte/API DEBE lanzar (nunca
     * null por error — el caller reintentaria y duplicaria).
     */
    public function findBookingByExternalReference(string $externalReference): ?array;
}
