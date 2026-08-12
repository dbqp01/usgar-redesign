<?php
declare(strict_types=1);

namespace App\Features\Shared\Ports;

/**
 * Puerto de abstraccion para la interaccion con pasarelas de pago (Mercado Pago).
 */
interface PaymentGatewayPortInterface {
    /**
     * @param array<string, mixed> $paymentData
     * @return array<string, mixed>|null
     */
    public function processPayment(array $paymentData): ?array;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $headers
     * @return array<string, mixed>|null
     */
    public function verifyNotification(array $payload, array $headers = []): ?array;

    public function verifySignature(?string $signatureHeader, ?string $requestId, ?string $dataId): bool;

    /**
     * @return array<string, mixed>|null
     */
    public function getPaymentDetails(string $paymentId): ?array;

    /**
     * Reembolsa un pago. Si $amount es null, hace reembolso total.
     */
    public function refundPayment(string $paymentId, ?float $amount = null): bool;

    /**
     * Busca un pago por external_reference (GET /v1/payments/search).
     * Devuelve el primer resultado normalizado o null si no existe.
     * Consumido por GetPaymentCheckAction (todo 31, W5) para evitar segundos
     * cobros tras commit-falla; la busqueda es eventualmente consistente, el
     * caller reintenta con backoff.
     *
     * @return array{id:int,status:string,status_detail:string,external_reference:string,transaction_amount:float}|null
     */
    public function findPaymentByExternalReference(string $externalRef): ?array;

}
