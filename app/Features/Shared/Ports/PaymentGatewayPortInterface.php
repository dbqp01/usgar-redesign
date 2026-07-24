<?php
declare(strict_types=1);

namespace App\Features\Shared\Ports;

/**
 * Puerto de abstraccion para la interaccion con pasarelas de pago (Mercado Pago).
 */
interface PaymentGatewayPortInterface {
    public function createPreference(
        string $cartId,
        int $idRoomType,
        string $checkIn,
        string $checkOut,
        float $totalPrice,
        string $guestName,
        string $guestEmail
    ): array;

    public function verifyNotification(array $payload, array $headers = []): ?array;

    public function verifySignature(?string $signatureHeader, ?string $requestId, ?string $dataId): bool;

    public function getPaymentDetails(string $paymentId): ?array;
}
