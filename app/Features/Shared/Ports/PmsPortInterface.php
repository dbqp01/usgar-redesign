<?php
declare(strict_types=1);

namespace App\Features\Shared\Ports;

/**
 * Puerto de abstraccion para la interaccion con el sistema PMS (QloApps).
 */
interface PmsPortInterface {
    /** @return array<int, array<string, mixed>> */
    public function getAvailableRooms(string $checkIn, string $checkOut, int $idHotel = 1, ?int $idLang = null): array;

    /** @return array<string, array<int, int>> */
    public function getAvailabilityCalendar(string $from, string $to, int $idHotel = 1): array;

    /**
     * Tasa de conversion USD -> PEN del propio PMS (qlo_currency), la unica
     * fuente coherente con el back-office (auditoria 2026-08-11): la tasa
     * fija de Config (3.80) diferia de la del CMS (3.388) y los montos PEN
     * del sitio no cuadraban con los reportes de QloApps. FAIL-SAFE: si la
     * consulta falla (PMS caido), devuelve la tasa de Config (EXCHANGE_RATE_USD_PEN)
     * para no romper la cotizacion.
     */
    public function getExchangeRatePEN(): float;
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
