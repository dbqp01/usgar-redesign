<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Webhooks;

use PHPUnit\Framework\TestCase;
use App\Core\Request;
use App\Core\Config;
use App\Core\Events\EventDispatcher;
use App\Features\Webhooks\Actions\HandleMercadoPagoWebhookAction;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use PDO;

/**
 * Tests de caracterizacion del action de webhook: congelan el comportamiento
 * observable (codigo de respuesta HTTP + efectos en repos/eventos) para que
 * el refactor no altere la logica de pagos.
 */
final class HandleMercadoPagoWebhookActionTest extends TestCase {
    private PDO $pdo;
    private PaymentGatewayPortInterface $paymentGateway;
    private ProvisionalBookingRepository $bookingRepo;
    private EventDispatcher $eventDispatcher;
    private HandleMercadoPagoWebhookAction $action;

    protected function setUp(): void {
        Config::set('APP_ENV', 'testing');
        Config::set('MERCADO_PAGO_WEBHOOK_SECRET', 'test-webhook-secret');
        Config::set('EXCHANGE_RATE_USD_PEN', '3.80');

        $this->pdo = $this->createMock(PDO::class);
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->method('commit')->willReturn(true);
        $this->pdo->method('inTransaction')->willReturn(false);

        $this->paymentGateway = $this->createMock(PaymentGatewayPortInterface::class);
        $this->bookingRepo = $this->createMock(ProvisionalBookingRepository::class);
        $this->eventDispatcher = $this->createMock(EventDispatcher::class);

        $this->action = new HandleMercadoPagoWebhookAction(
            $this->pdo,
            $this->paymentGateway,
            $this->bookingRepo,
            $this->eventDispatcher
        );
    }

    private function captureResponse(callable $fn): array {
        ob_start();
        $fn();
        $body = (string) ob_get_clean();
        return ['code' => http_response_code(), 'body' => $body];
    }

    public function testNonPaymentEventIsAcknowledgedWithoutProcessing(): void {
        $this->bookingRepo->expects($this->never())->method('isPaymentProcessed');

        $request = new Request('POST', '/api/webhook', [], ['type' => 'merchant_order']);

        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('Non-payment event acknowledged', $response['body']);
    }

    public function testMissingPaymentIdIsAcknowledged(): void {
        $this->bookingRepo->expects($this->never())->method('isPaymentProcessed');

        $request = new Request('POST', '/api/webhook', [], ['type' => 'payment', 'data' => []]);

        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('No payment ID found', $response['body']);
    }

    public function testInvalidSignatureReturns401(): void {
        $this->paymentGateway->method('verifySignature')->willReturn(false);
        $this->bookingRepo->expects($this->never())->method('isPaymentProcessed');

        $request = new Request(
            'POST',
            '/api/webhook',
            ['x-signature' => 'ts=1,v1=invalid', 'x-request-id' => 'req-1'],
            ['type' => 'payment', 'data' => ['id' => '555666777']]
        );

        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(401, $response['code']);
    }

    public function testAlreadyProcessedPaymentIsNotReprocessed(): void {
        // Todo 12: el chequeo de idempotencia recibe event_type (derivado del
        // status del pago), por lo que getPaymentDetails corre ANTES del
        // chequeo; el evento YA procesado se responde 200 sin persistir nada.
        $this->paymentGateway->method('verifySignature')->willReturn(true);
        $this->paymentGateway->method('getPaymentDetails')->willReturn([
            'id' => 555666777,
            'status' => 'approved',
            'external_reference' => 'CART-42',
            'transaction_amount' => 285.0,
        ]);
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(true);
        $this->bookingRepo->expects($this->never())->method('markPaymentProcessed');
        $this->bookingRepo->expects($this->never())->method('updateStatus');

        $request = new Request(
            'POST',
            '/api/webhook',
            ['x-signature' => 'ts=1,v1=valid', 'x-request-id' => 'req-1'],
            ['type' => 'payment', 'data' => ['id' => '555666777']]
        );

        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('already processed', $response['body']);
    }

    public function testDoubleDeliveryRegistersExactlyOneProcessedRow(): void {
        // Todo 11 (QA+): doble entrega del MISMO webhook firmado -> 1 solo
        // registro en processed_payments (el 2do POST responde 200 por
        // idempotencia, sin reprocesar).
        $this->paymentGateway->method('verifySignature')->willReturn(true);
        $this->paymentGateway->method('getPaymentDetails')->willReturn([
            'id' => 555666777,
            'status' => 'approved',
            'external_reference' => 'CART-42',
            'transaction_amount' => 285.0,
        ]);
        $this->bookingRepo->method('isPaymentProcessed')->willReturnOnConsecutiveCalls(false, true);
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn([
            'cart_id' => 'CART-42',
            'status' => 'provisional',
            'price_snapshot' => 75.0,
            'checkin' => '2026-09-01',
            'checkout' => '2026-09-03',
            'id_room_type' => 2,
            'guest_data' => ['email' => 'x@test.com'],
            'room_data' => [],
        ]);
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')->with('555666777', 'CART-42', 'approved');
        $this->eventDispatcher->expects($this->once())->method('dispatch');

        $request = new Request(
            'POST',
            '/api/webhook',
            ['x-signature' => 'ts=1,v1=valid', 'x-request-id' => 'req-1'],
            ['type' => 'payment', 'data' => ['id' => '555666777']]
        );

        $r1 = $this->captureResponse(fn () => ($this->action)($request));
        $r2 = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $r1['code']);
        $this->assertSame(200, $r2['code']);
        $this->assertStringContainsString('Payment processed locally', $r1['body']);
        $this->assertStringContainsString('already processed', $r2['body']);
    }

    public function testIsPaymentProcessedFailureReturns500AndPersistsNothing(): void {
        // Todo 11 (QA- / fail-closed): si el chequeo de idempotencia falla por
        // BD, el handler responde 500 (MP reintentara) y NO persiste nada.
        $this->paymentGateway->method('verifySignature')->willReturn(true);
        $this->paymentGateway->method('getPaymentDetails')->willReturn([
            'id' => 555666777,
            'status' => 'approved',
            'external_reference' => 'CART-42',
            'transaction_amount' => 285.0,
        ]);
        $this->bookingRepo->method('isPaymentProcessed')
            ->willThrowException(new \PDOException('connection lost during idempotency check'));
        $this->bookingRepo->expects($this->never())->method('markPaymentProcessed');
        $this->bookingRepo->expects($this->never())->method('updateStatus');

        $request = new Request(
            'POST',
            '/api/webhook',
            ['x-signature' => 'ts=1,v1=valid', 'x-request-id' => 'req-1'],
            ['type' => 'payment', 'data' => ['id' => '555666777']]
        );

        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(500, $response['code']);
        $this->assertStringContainsString('Error interno', $response['body']);
    }

    public function testApprovedOnExpiredHoldMarksExpiredPaidAndRecordsAlert(): void {
        // Todo 9: webhook approved sobre hold 'expired' -> expired_paid +
        // alerta (log + tabla payment_alerts) para resolucion manual; la
        // habitacion pudo re-venderse.
        $this->paymentGateway->method('verifySignature')->willReturn(true);
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->paymentGateway->method('getPaymentDetails')->willReturn([
            'id' => 555666777,
            'status' => 'approved',
            'external_reference' => 'CART-42',
            'transaction_amount' => 285.0,
        ]);

        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn([
            'cart_id' => 'CART-42',
            'status' => 'expired',
            'price_snapshot' => 75.0,
            'checkin' => '2026-09-01',
            'checkout' => '2026-09-03',
            'id_room_type' => 2,
            'guest_data' => ['email' => 'x@test.com'],
            'room_data' => [],
        ]);

        $this->bookingRepo->expects($this->once())->method('updateStatus')->with('CART-42', 'expired_paid');
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')->with('555666777', 'CART-42', 'approved');
        $this->bookingRepo->expects($this->once())->method('recordAlert')->with('CART-42', '555666777', 'expired_paid');

        $request = new Request(
            'POST',
            '/api/webhook',
            ['x-signature' => 'ts=1,v1=valid', 'x-request-id' => 'req-1'],
            ['type' => 'payment', 'data' => ['id' => '555666777']]
        );

        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('expired_paid', $response['body']);
    }

    public function testApprovedPaymentMarksPaidAndDispatchesEvent(): void {
        $this->paymentGateway->method('verifySignature')->willReturn(true);
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->paymentGateway->method('getPaymentDetails')->willReturn([
            'id' => 555666777,
            'status' => 'approved',
            'external_reference' => 'CART-42',
            'transaction_amount' => 285.0,
        ]);

        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn([
            'cart_id' => 'CART-42',
            'status' => 'provisional',
            'price_snapshot' => 75.0,
            'checkin' => '2026-09-01',
            'checkout' => '2026-09-03',
            'id_room_type' => 2,
            'guest_data' => ['email' => 'x@test.com'],
            'room_data' => [],
        ]);

        $this->bookingRepo->expects($this->once())->method('updateStatus')->with('CART-42', 'paid');
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')->with('555666777', 'CART-42', 'approved');
        $this->eventDispatcher->expects($this->once())->method('dispatch');

        $request = new Request(
            'POST',
            '/api/webhook',
            ['x-signature' => 'ts=1,v1=valid', 'x-request-id' => 'req-1'],
            ['type' => 'payment', 'data' => ['id' => '555666777']]
        );

        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('Payment processed locally', $response['body']);
    }

    public function testAmountBelowExpectedTriggersFraudReview(): void {
        $this->paymentGateway->method('verifySignature')->willReturn(true);
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->paymentGateway->method('getPaymentDetails')->willReturn([
            'id' => 555666777,
            'status' => 'approved',
            'external_reference' => 'CART-42',
            'transaction_amount' => 50.0,
        ]);

        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn([
            'cart_id' => 'CART-42',
            'status' => 'provisional',
            'price_snapshot' => 75.0,
            'checkin' => '2026-09-01',
            'checkout' => '2026-09-03',
            'id_room_type' => 2,
            'guest_data' => [],
            'room_data' => [],
        ]);

        $this->bookingRepo->expects($this->once())->method('updateStatus')->with('CART-42', 'fraud_review');
        $this->bookingRepo->expects($this->never())->method('markPaymentProcessed');

        $request = new Request(
            'POST',
            '/api/webhook',
            ['x-signature' => 'ts=1,v1=valid', 'x-request-id' => 'req-1'],
            ['type' => 'payment', 'data' => ['id' => '555666777']]
        );

        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(400, $response['code']);
    }
}
