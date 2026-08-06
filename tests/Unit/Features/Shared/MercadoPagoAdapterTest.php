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
use MercadoPago\Net\MPSearchRequest;
use MercadoPago\Resources\Payment;
use MercadoPago\Resources\PaymentSearch;
use MercadoPago\Resources\Payment\PaymentSearchResult;
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
                    // Todo 3 (clausula r2): el create NO envia notification_url
                    // (la config del panel/save_webhook gobierna).
                    $this->assertArrayNotHasKey('notification_url', $payload);
                    // Todo 4: currency_id explicito.
                    $this->assertSame('PEN', $payload['currency_id']);
                    $this->assertSame(['email' => 'cliente@test.com'], $payload['payer']);
                    $this->assertSame('CARD_TOKEN_XYZ', $payload['token']);
                    $this->assertSame('310', $payload['issuer_id']);
                    $this->assertSame(3, $payload['installments']);
                    // Todo 3: additional_info pasa tal cual (density armada en la accion).
                    $this->assertSame('travel', $payload['additional_info']['items'][0]['category_id']);
                    $this->assertSame(2, $payload['additional_info']['items'][0]['quantity']);
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
            'additional_info' => [
                'items' => [[
                    'id' => '5',
                    'title' => 'Suite Deluxe',
                    'quantity' => 2,
                    'unit_price' => 250.0,
                    'category_id' => 'travel',
                ]],
            ],
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
                    $this->assertNotEmpty($headers, 'Debe enviar x-idempotency-key');
                    $this->assertArrayHasKey('x-idempotency-key', $headers);
                    // Todo 2: UUID v4 por intento (ya no es determinista por carrito).
                    $this->assertMatchesRegularExpression(
                        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                        $headers['x-idempotency-key'],
                        'La key debe ser un UUID v4 fresco, no derivada del cart.'
                    );
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

    public function testProcessPaymentGeneratesFreshIdempotencyKeyPerAttempt(): void {
        // Todo 2 (QA+): dos llamadas seguidas sobre el MISMO cart -> keys DISTINTAS
        // (la key determinista por carrito bloqueaba reintentos legitimos).
        $capturedKeys = [];
        $paymentClient = $this->createMock(PaymentClient::class);
        $paymentClient->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function (array $payload, RequestOptions $options) use (&$capturedKeys) {
                $capturedKeys[] = $options->getCustomHeaders()['x-idempotency-key'] ?? null;
                return $this->makePayment(222, 'approved', 'accredited', 'CART-1', 50.0);
            });

        $adapter = new MercadoPagoAdapter($paymentClient);
        $request = [
            'external_reference' => 'CART-1',
            'transaction_amount' => 50.0,
            'payment_method_id' => 'card',
            'payer' => ['email' => 'x@test.com'],
        ];
        $adapter->processPayment($request);
        $adapter->processPayment($request);

        $this->assertCount(2, $capturedKeys);
        $this->assertNotSame($capturedKeys[0], $capturedKeys[1], 'Cada intento debe tener una idempotency key fresca (UUID).');
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

    public function testGetPaymentDetailsIncludesStatusDetail(): void {
        // Todo 7 (QA+): el retorno incluye status_detail (motivos de rechazo MP).
        $paymentClient = $this->createMock(PaymentClient::class);
        $paymentClient->expects($this->once())
            ->method('get')
            ->with(888, $this->isInstanceOf(RequestOptions::class))
            ->willReturn($this->makePayment(888, 'rejected', 'cc_rejected_insufficient_amount', 'CART-8', 10.0));

        $adapter = new MercadoPagoAdapter($paymentClient);
        $result = $adapter->getPaymentDetails('888');

        $this->assertSame('cc_rejected_insufficient_amount', $result['status_detail']);
    }

    public function testGetPaymentDetailsReturnsNullForNonNumericId(): void {
        // Todo 7 (QA-): paymentId alfanumerico -> null + log, sin TypeError del cast.
        $paymentClient = $this->createMock(PaymentClient::class);
        $paymentClient->expects($this->never())->method('get');

        $adapter = new MercadoPagoAdapter($paymentClient);
        $this->assertNull($adapter->getPaymentDetails('ABC123XYZ'));
    }

    public function testFindPaymentByExternalReferenceReturnsNormalizedPayment(): void {
        // Todo 7: dueno de findPaymentByExternalReference (consume todo 31).
        $result = new PaymentSearchResult();
        $result->id = 777;
        $result->status = 'approved';
        $result->status_detail = 'accredited';
        $result->external_reference = 'USGAR-CART-77';
        $result->transaction_amount = 380.0;
        $search = new PaymentSearch();
        $search->results = [$result];

        $paymentClient = $this->createMock(PaymentClient::class);
        $paymentClient->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(MPSearchRequest::class), $this->isInstanceOf(RequestOptions::class))
            ->willReturn($search);

        $adapter = new MercadoPagoAdapter($paymentClient);
        $found = $adapter->findPaymentByExternalReference('USGAR-CART-77');

        $this->assertNotNull($found);
        $this->assertSame(777, $found['id']);
        $this->assertSame('approved', $found['status']);
        $this->assertSame('accredited', $found['status_detail']);
        $this->assertSame('USGAR-CART-77', $found['external_reference']);
        $this->assertSame(380.0, $found['transaction_amount']);
    }

    public function testFindPaymentByExternalReferenceReturnsNullWhenNoResults(): void {
        $search = new PaymentSearch();
        $search->results = [];

        $paymentClient = $this->createMock(PaymentClient::class);
        $paymentClient->expects($this->once())
            ->method('search')
            ->willReturn($search);

        $adapter = new MercadoPagoAdapter($paymentClient);
        $this->assertNull($adapter->findPaymentByExternalReference('USGAR-CART-NONE'));
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
