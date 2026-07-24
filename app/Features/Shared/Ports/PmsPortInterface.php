<?php
declare(strict_types=1);

namespace App\Features\Shared\Ports;

/**
 * Puerto de abstracción para la interacción con el sistema PMS (QloApps).
 */
interface PmsPortInterface {
    public function getAvailableRooms(string $checkIn, string $checkOut, int $idHotel = 1): array;
    public function createCart(int $idHotel, int $idProduct, string $checkIn, string $checkOut, int $guests = 1): string;
    public function extendCartSession(string $cartId): bool;
    public function confirmOrder(string $cartId, float $totalPrice, string $guestName, string $guestEmail): ?string;
}
