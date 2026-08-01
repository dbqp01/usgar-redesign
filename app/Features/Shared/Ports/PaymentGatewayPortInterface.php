<?php
declare(strict_types=1);

namespace App\Features\Shared\Ports;

/**
 * Puerto de abstraccion para la interaccion con pasarelas de pago (Mercado Pago).
 */
interface PaymentGatewayPortInterface {
    public function processPayment(array $paymentData): ?array;

    public function verifyNotification(array $payload, array $headers = []): ?array;

    public function verifySignature(?string $signatureHeader, ?string $requestId, ?string $dataId): bool;

    public function getPaymentDetails(string $paymentId): ?array;

    /**
     * Reembolsa un pago. Si $amount es null, hace reembolso total.
     */
    public function refundPayment(string $paymentId, ?float $amount = null): bool;

}
