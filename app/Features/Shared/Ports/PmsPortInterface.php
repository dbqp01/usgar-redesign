<?php
declare(strict_types=1);

namespace App\Features\Shared\Ports;

/**
 * Puerto de abstraccion para la interaccion con el sistema PMS (QloApps).
 */
interface PmsPortInterface {
    public function getAvailableRooms(string $checkIn, string $checkOut, int $idHotel = 1): array;
    public function getAvailabilityCalendar(string $from, string $to, int $idHotel = 1): array;
    public function createCart(int $idHotel, int $idProduct, string $checkIn, string $checkOut, int $guests = 1, float $totalPrice = 0, string $guestName = '', string $guestEmail = '', string $guestPhone = ''): string;
    public function extendCartSession(string $cartId): bool;
    public function confirmOrder(string $cartId, float $totalPrice, string $guestName, string $guestEmail): ?string;

    /**
     * Dedup del consumidor (todo 21): indica si la orden con la referencia
     * externa dada (USGAR-{cartId}) YA esta confirmada en el PMS.
     * FAIL-CLOSED: si el PMS esta caido/no responde, DEBE lanzar (nunca
     * devolver false por error — el caller reintenta via outbox).
     */
    public function isOrderConfirmed(string $externalReference): bool;
}
