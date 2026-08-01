<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Shared;

use PHPUnit\Framework\TestCase;
use App\Features\Shared\Adapters\MercadoPagoAdapter;
use App\Core\Config;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Payment\PaymentRefundClient;
use MercadoPago\Client\Common\RequestOptions;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\Net\MPResponse;
use MercadoPago\Resources\Payment;
use MercadoPago\Resources\PaymentRefund;

/**
 * Tests de caracterizacion: congelan el comportamiento observable de MercadoPagoAdapter
 * para que el refactor (eliminacion de residuos Checkout Pro, DIP) no lo altere.
 */
final class MercadoPagoAdapterTest extends TestCase {
    protected function setUp(): void {
        Config::set('MERCADO_PAGO_ACCESS_TOKEN', 'TEST-123456789');
        Config::set('MERCADO_PAGO_WEBHOOK_SECRET', 'test-webhook-secret');
        Config::set('SITE_URL', 'https://usgarhoteles.com');
        Config::set('MP_STATEMENT_DESCRIPTOR', 'USGAR HOTELES CUSCO');
        Config::set('MP_BINARY_MODE', 'true');
    }

    public function testProcessPaymentBuildsCorrectPayload(): void {
        $paymentClient = $this->createMock(PaymentClient::class);
        $paymentClient->expects($this->once())
            ->method('create')
            ->with(
                $this->callback(function (array $payload): bool {
                    $this->assertSame(125.50, $payload['transaction_amount']);
                    $this->assertSame('card', $payload['payment_method_id']);
                    $this->assertSame('CART-ABC-123', $payload['external_reference']);
                    $this->assertSame('USGAR HOTELES CUSCO', $payload['statement_descriptor']);
                    $this->assertSame('https://usgarhoteles.com/api/webhook', $payload['notification_url']);
                    $this->assertSame(['email' => 'cliente@test.com'], $payload['payer']);
                    $this->assertSame('CARD_TOKEN_XYZ', $payload['token']);
                    $this->assertSame('310', $payload['issuer_id']);
                    $this->assertSame(3, $payload['installments']);
                    return true;
                }),
                $this->isInstanceOf(RequestOptions::class)
            )
            ->willReturn($this->makePayment(987654321, 'approved', 'accredited', 'CART-ABC-123', 125.50));

        $adapter = new MercadoPagoAdapter($paymentClient);
        $result = $adapter->processPayment([
            'external_reference' => 'CART-ABC-123',
            'transaction_amount' => 125.50,
            'payment_method_id' => 'card',
            'payer' => ['email' => 'cliente@test.com'],
            'token' => 'CARD_TOKEN_XYZ',
            'issuer_id' => '310',
            'installments' => 3,
        ]);

        $this->assertSame(987654321, $result['id']);
        $this->assertSame('approved', $result['status']);
        $this->assertSame('accredited', $result['status_detail']);
        $this->assertSame('CART-ABC-123', $result['external_reference']);
    }

    public function testProcessPaymentSendsIdempotencyHeader(): void {
        $paymentClient = $this->createMock(PaymentClient::class);
        $paymentClient->expects($this->once())
            ->method('create')
            ->with(
                $this->anything(),
                $this->callback(function (RequestOptions $options): bool {
                    $headers = $options->getCustomHeaders();
                    $this->assertNotEmpty($headers, 'Debe enviar X-Idempotency-Key');
                    // El SDK espera claves => valores ("X-Idempotency-Key" => "pay_...").
                    // El formato antiguo ("X-Idempotency-Key: pay_..." como valor unico
                    // con clave entera) se reindexaba en array_merge y el header no se enviaba.
                    $this->assertArrayHasKey('X-Idempotency-Key', $headers);
                    $this->assertStringStartsWith('pay_', $headers['X-Idempotency-Key']);
                    return true;
                })
            )
            ->willReturn($this->makePayment(111, 'pending', 'pending', 'CART-1', 50.0));

        $adapter = new MercadoPagoAdapter($paymentClient);
        $adapter->processPayment([
            'external_reference' => 'CART-1',
            'transaction_amount' => 50.0,
            'payment_method_id' => 'card',
            'payer' => ['email' => 'x@test.com'],
        ]);
    }

    public function testGetPaymentDetailsReturnsNormalizedArray(): void {
        $paymentClient = $this->createMock(PaymentClient::class);
        $paymentClient->expects($this->once())
            ->method('get')
            ->with(42424242, $this->isInstanceOf(RequestOptions::class))
            ->willReturn($this->makePayment(42424242, 'approved', 'accredited', 'CART-42', 75.25));

        $adapter = new MercadoPagoAdapter($paymentClient);
        $result = $adapter->getPaymentDetails('42424242');

        $this->assertSame(42424242, $result['id']);
        $this->assertSame('approved', $result['status']);
        $this->assertSame('CART-42', $result['external_reference']);
        $this->assertSame(75.25, $result['transaction_amount']);
    }

    public function testGetPaymentDetailsReturnsNullOnSdkFailure(): void {
        $paymentClient = $this->createMock(PaymentClient::class);
        $paymentClient->expects($this->once())
            ->method('get')
            ->willThrowException(new MPApiException(
                'API error',
                new MPResponse(500, ['error' => 'internal'])
            ));

        $adapter = new MercadoPagoAdapter($paymentClient);
        $this->assertNull($adapter->getPaymentDetails('42424242'));
    }

    public function testVerifySignatureAcceptsValidHmac(): void {
        $ts = (string) time();
        $dataId = '123456789';
        $requestId = 'req-abc-123';
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $v1 = hash_hmac('sha256', $manifest, 'test-webhook-secret');
        $signatureHeader = "ts={$ts},v1={$v1}";

        $adapter = new MercadoPagoAdapter();
        $this->assertTrue($adapter->verifySignature($signatureHeader, $requestId, $dataId));
    }

    public function testVerifySignatureRejectsInvalidHmac(): void {
        $ts = (string) time();
        $dataId = '123456789';
        $manifest = "id:{$dataId};ts:{$ts};";
        $v1 = hash_hmac('sha256', $manifest, 'wrong-secret');
        $signatureHeader = "ts={$ts},v1={$v1}";

        $adapter = new MercadoPagoAdapter();
        $this->assertFalse($adapter->verifySignature($signatureHeader, null, $dataId));
    }

    public function testVerifyNotificationValidatesSignatureAndFetchesPayment(): void {
        $paymentClient = $this->createMock(PaymentClient::class);
        $paymentClient->expects($this->once())
            ->method('get')
            ->with(555666777, $this->isInstanceOf(RequestOptions::class))
            ->willReturn($this->makePayment(555666777, 'approved', 'accredited', 'CART-9', 99.99));

        $adapter = new MercadoPagoAdapter($paymentClient);

        $ts = (string) time();
        $dataId = '555666777';
        $requestId = 'req-xyz';
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $v1 = hash_hmac('sha256', $manifest, 'test-webhook-secret');

        $result = $adapter->verifyNotification(
            ['type' => 'payment', 'data' => ['id' => $dataId]],
            ['x-signature' => "ts={$ts},v1={$v1}", 'x-request-id' => $requestId]
        );

        $this->assertSame(555666777, $result['id']);
        $this->assertSame('approved', $result['status']);
    }

    public function testRefundPaymentFullRefund(): void {
        $refundClient = $this->createMock(PaymentRefundClient::class);
        $refundClient->expects($this->once())
            ->method('refundTotal')
            ->with(42424242, $this->isInstanceOf(RequestOptions::class))
            ->willReturn(new PaymentRefund());

        $adapter = new MercadoPagoAdapter(null, $refundClient);
        $this->assertTrue($adapter->refundPayment('42424242'));
    }

    public function testRefundPaymentPartialRefundUsesRefundMethod(): void {
        $refundClient = $this->createMock(PaymentRefundClient::class);
        $refundClient->expects($this->once())
            ->method('refund')
            ->with(42424242, 50.0, $this->isInstanceOf(RequestOptions::class))
            ->willReturn(new PaymentRefund());

        $adapter = new MercadoPagoAdapter(null, $refundClient);
        $this->assertTrue($adapter->refundPayment('42424242', 50.0));
    }

    private function makePayment(int $id, string $status, string $statusDetail, string $externalRef, float $amount): Payment {
        $payment = new Payment();
        $payment->id = $id;
        $payment->status = $status;
        $payment->status_detail = $statusDetail;
        $payment->external_reference = $externalRef;
        $payment->transaction_amount = $amount;
        return $payment;
    }
}
