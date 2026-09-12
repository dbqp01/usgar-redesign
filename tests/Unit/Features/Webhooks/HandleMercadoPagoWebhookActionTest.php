<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Webhooks;

require_once __DIR__ . '/../../../fixtures/W3WebhookFixtures.php';

use PHPUnit\Framework\TestCase;
use App\Core\Request;
use App\Core\Config;
use App\Core\Events\EventDispatcher;
use App\Features\Webhooks\Actions\HandleMercadoPagoWebhookAction;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use App\Test\Fixtures\W3WebhookFixtures;
use PDO;

/**
 * Tests del action de webhook (W3 — ciclo de vida): todos 13 (filtro estricto
 * type === 'payment' + rama rejected/failed), 14 (orphan 404 -> 200 + marcado),
 * 16 (rama fraude -> 200 + fraud_review) y la coordinacion del prefijo
 * USGAR- al resolver el hold.
 *
 * Fixtures FIRMADOS: payload oficial {"type":"payment","data":{"id":...}} con
 * header x-signature HMAC-SHA256 REAL (W3WebhookFixtures::signatureHeader,
 * mismo algoritmo del SDK WebhookSignatureValidator) — mandato r10: sin
 * tarjetas ni mocks del comportamiento de MP; el port de firma se mockea, pero
 * la firma del fixture es la real.
 */
final class HandleMercadoPagoWebhookActionTest extends TestCase {
    private PDO $pdo;
    private PaymentGatewayPortInterface $paymentGateway;
    private ProvisionalBookingRepository $bookingRepo;
    private EventDispatcher $eventDispatcher;
    private HandleMercadoPagoWebhookAction $action;

    protected function setUp(): void {
        Config::set('MERCADO_PAGO_WEBHOOK_SECRET', W3WebhookFixtures::TEST_SECRET);
        Config::set('EXCHANGE_RATE_USD_PEN', '3.80');

        $this->pdo = $this->createMock(PDO::class);
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->method('commit')->willReturn(true);
        $this->pdo->method('rollBack')->willReturn(true);
        $this->pdo->method('inTransaction')->willReturn(false);

        $this->paymentGateway = $this->createMock(PaymentGatewayPortInterface::class);
        $this->bookingRepo = $this->createMock(ProvisionalBookingRepository::class);
        // Persistencia exitosa por defecto: los casos que prueban fallo
        // deben configurar explicitamente false/throws para mantener el
        // contrato fail-closed del webhook.
        $this->bookingRepo->method('updateStatus')->willReturn(true);
        $this->bookingRepo->method('attachPaymentId')->willReturn(true);
        $this->bookingRepo->method('markPaymentProcessed')->willReturn(true);
        $this->bookingRepo->method('recordAlert')->willReturn(true);
        $this->eventDispatcher = $this->createMock(EventDispatcher::class);

        $this->action = new HandleMercadoPagoWebhookAction(
            $this->pdo,
            $this->paymentGateway,
            $this->bookingRepo,
            $this->eventDispatcher
        );
    }

    /** Firma valida (solo tests que llegan al chequeo de firma). */
    private function acceptSignature(): void {
        $this->paymentGateway->method('verifySignature')->willReturn(true);
    }

    private function captureResponse(callable $fn): array {
        ob_start();
        $fn();
        $body = (string) ob_get_clean();
        return ['code' => http_response_code(), 'body' => $body];
    }

    /** Payload oficial + firma HMAC real (todo 13: data.id es el ID del pago). */
    private function signedRequest(string $dataId, array $body = [], array $extraHeaders = []): Request {
        $body = array_merge(['type' => 'payment', 'data' => ['id' => $dataId]], $body);
        $headers = array_merge([
            'x-signature' => W3WebhookFixtures::signatureHeader($dataId, 'req-w3'),
            'x-request-id' => 'req-w3',
        ], $extraHeaders);
        return new Request('POST', '/api/webhook', $headers, $body);
    }

    private function approvedPayment(string $externalRef, float $amount = 285.0): array {
        return [
            'id' => 555666777,
            'status' => 'approved',
            'external_reference' => $externalRef,
            'transaction_amount' => $amount,
        ];
    }

    private function hold(string $cartId, string $status = 'provisional', float $priceSnapshot = 75.0): array {
        return [
            'cart_id' => $cartId,
            'status' => $status,
            'price_snapshot' => $priceSnapshot,
            'checkin' => '2026-09-01',
            'checkout' => '2026-09-03',
            'id_room_type' => 2,
            'guest_data' => ['email' => 'x@test.com'],
            'room_data' => [],
        ];
    }

    // ---------------------------------------------------------------- todo 13

    public function testNonPaymentEventIsAcknowledgedWithoutProcessing(): void {
        $this->bookingRepo->expects($this->never())->method('isPaymentProcessed');
        $this->paymentGateway->expects($this->never())->method('verifySignature');

        $request = new Request('POST', '/api/webhook', [], ['type' => 'merchant_order']);

        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('Non-payment event acknowledged', $response['body']);
    }

    public function testSubscriptionAuthorizedPaymentIsStrictlyIgnored(): void {
        // Todo 13: 'subscription_authorized_payment' CONTIENE 'payment' pero es
        // un topico SEPARADO (doc MP webhooks — tabla de topicos). El filtro
        // estricto $type === 'payment' lo ignora con 200 y sin validar firma
        // ni persistir nada.
        $this->bookingRepo->expects($this->never())->method('isPaymentProcessed');
        $this->bookingRepo->expects($this->never())->method('markPaymentProcessed');
        $this->paymentGateway->expects($this->never())->method('verifySignature');
        $this->paymentGateway->expects($this->never())->method('getPaymentDetails');

        $request = new Request('POST', '/api/webhook', [], [
            'type' => 'subscription_authorized_payment',
            'data' => ['id' => '777777777'],
        ]);

        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('Non-payment event acknowledged', $response['body']);
    }

    public function testNotificationIdFallbackIsNotUsedAsPaymentId(): void {
        // Todo 13: body['id'] es el ID de la NOTIFICACION (doc MP), no el del
        // pago. Sin data.id en body ni query -> 200 sin procesar.
        $this->paymentGateway->expects($this->never())->method('getPaymentDetails');
        $this->bookingRepo->expects($this->never())->method('isPaymentProcessed');

        $request = new Request('POST', '/api/webhook', [], [
            'type' => 'payment',
            'id' => '12345', // ID de notificacion — NO debe usarse como payment id
        ]);

        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('No payment ID found', $response['body']);
    }

    public function testQueryAndBodyPaymentIdsMustMatch(): void {
        $this->paymentGateway->expects($this->never())->method('verifySignature');
        $this->paymentGateway->expects($this->never())->method('getPaymentDetails');
        $_GET['data_id'] = '222';
        try {
            $request = new Request('POST', '/api/webhook', [], [
                'type' => 'payment',
                'data' => ['id' => '111'],
            ]);
            $response = $this->captureResponse(fn () => ($this->action)($request));
            $this->assertSame(400, $response['code']);
            $this->assertStringContainsString('PAYMENT_ID_MISMATCH', $response['body']);
        } finally {
            unset($_GET['data_id']);
        }
    }

    public function testRemotePaymentIdMustMatchWebhookId(): void {
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn([
            'id' => 999,
            'status' => 'approved',
            'external_reference' => 'USGAR-CART-42',
            'transaction_amount' => 285.0,
        ]);
        $this->pdo->expects($this->never())->method('beginTransaction');

        $response = $this->captureResponse(fn () => ($this->action)($this->signedRequest('555666777')));
        $this->assertSame(400, $response['code']);
        $this->assertStringContainsString('PAYMENT_ID_MISMATCH', $response['body']);
    }

    public function testPaymentIdFromQueryDataIdIsUsed(): void {
        // Todo 13: MP envia ?data.id=XXX (PHP lo convierte a data_id); el id
        // del query SI es fuente valida de payment id.
        $this->acceptSignature();
        $_GET['data_id'] = '555666777';
        try {
            $this->paymentGateway->method('getPaymentDetails')->willReturn($this->approvedPayment('USGAR-CART-42'));
            $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
            $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->hold('CART-42'));

            $request = new Request('POST', '/api/webhook', [
                'x-signature' => W3WebhookFixtures::signatureHeader('555666777', 'req-q'),
                'x-request-id' => 'req-q',
            ], ['type' => 'payment']);

            $response = $this->captureResponse(fn () => ($this->action)($request));

            $this->assertSame(200, $response['code']);
        } finally {
            unset($_GET['data_id']);
        }
    }

    public function testRejectedPaymentIsMarkedProcessedAndAcked(): void {
        // Todo 13 (r1/r2): status rejected -> 200 + markPaymentProcessed('rejected')
        // (nunca 400/500 — causaria reintentos eternos de MP).
        // Fix 2026-08-13: ademas transiciona el hold pending -> failed (si no,
        // la pagina de exito mostraba 'expired' por TTL aunque MP habia rechazado).
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn([
            'id' => 555666777,
            'status' => 'rejected',
            'status_detail' => 'cc_rejected_insufficient_amount',
            'external_reference' => 'USGAR-CART-42',
            'transaction_amount' => 285.0,
        ]);
        $this->bookingRepo->method('isPaymentProcessed')->willReturnOnConsecutiveCalls(false, true);
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->hold('CART-42'));
        $this->bookingRepo->expects($this->once())->method('updateStatus')->with('CART-42', 'failed');
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')->with('555666777', 'CART-42', 'rejected');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $request = $this->signedRequest('555666777');
        $r1 = $this->captureResponse(fn () => ($this->action)($request));
        $r2 = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $r1['code']);
        $this->assertSame(200, $r2['code']);
        $this->assertStringContainsString('rejected', $r1['body']);
        $this->assertStringContainsString('already processed', $r2['body']);
    }

    public function testFailedPaymentIsMarkedProcessedAndAcked(): void {
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn([
            'id' => 555666777,
            'status' => 'failed',
            'status_detail' => 'bad_filled_card_data',
            'external_reference' => 'USGAR-CART-42',
            'transaction_amount' => 285.0,
        ]);
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->hold('CART-42'));
        $this->bookingRepo->expects($this->once())->method('updateStatus')->with('CART-42', 'failed');
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')->with('555666777', 'CART-42', 'rejected');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $request = $this->signedRequest('555666777');
        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $response['code']);
    }

    public function testRejectedPaymentWithoutExternalReferenceIsAcked(): void {
        // rejected sin external_reference: se marca igualmente (cart_id='')
        // para cortar el reintento de MP; nunca 400/500.
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn([
            'id' => 555666777,
            'status' => 'rejected',
            'external_reference' => null,
            'transaction_amount' => 285.0,
        ]);
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')->with('555666777', '', 'rejected');

        $request = $this->signedRequest('555666777');
        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $response['code']);
    }

    public function testRejectedPaymentWithUnknownExternalReferenceIsAckedAndIdempotent(): void {
        // A stale/deleted hold must not turn a terminal rejection into a 500
        // retry storm. Persist the rejection with the resolved cart id anyway.
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn([
            'id' => 555666777,
            'status' => 'rejected',
            'external_reference' => 'USGAR-MISSING-CART',
            'transaction_amount' => 285.0,
        ]);
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn(null);
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')
            ->with('555666777', 'MISSING-CART', 'rejected');
        $this->bookingRepo->expects($this->never())->method('updateStatus');

        $response = $this->captureResponse(fn () => ($this->action)($this->signedRequest('555666777')));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('acknowledged', $response['body']);
    }

    public function testCancelledPaymentWithoutExternalReferenceIsAuditedAndAcked(): void {
        // A terminal cancellation may arrive without external_reference. It
        // still needs an idempotency row and alert; otherwise every delivery
        // is treated as new and the cancellation is not auditable.
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn([
            'id' => 555666777,
            'status' => 'cancelled',
            'external_reference' => null,
            'transaction_amount' => 285.0,
        ]);
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')
            ->with('555666777', '', 'rejected');
        $this->bookingRepo->expects($this->once())->method('recordAlert')
            ->with('', '555666777', 'cancelled');
        $this->bookingRepo->expects($this->never())->method('getByCartIdForUpdate');
        $this->bookingRepo->expects($this->never())->method('updateStatus');

        $response = $this->captureResponse(fn () => ($this->action)($this->signedRequest('555666777')));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('manual resolution', $response['body']);
    }

    public function testCancelledPaymentWithUnknownExternalReferenceIsAuditedAndAcked(): void {
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn([
            'id' => 555666777,
            'status' => 'cancelled',
            'external_reference' => 'USGAR-MISSING-CART',
            'transaction_amount' => 285.0,
        ]);
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn(null);
        $this->bookingRepo->expects($this->never())->method('updateStatus');
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')
            ->with('555666777', 'MISSING-CART', 'rejected');
        $this->bookingRepo->expects($this->once())->method('recordAlert')
            ->with('MISSING-CART', '555666777', 'cancelled');

        $response = $this->captureResponse(fn () => ($this->action)($this->signedRequest('555666777')));

        $this->assertSame(200, $response['code']);
    }

    public function testCancelledPaymentOnExpiredHoldTransitionsToFailed(): void {
        // RF-22: cancellation is terminal even when the hold expired before
        // Mercado Pago delivered the notification.
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn([
            'id' => 555666777,
            'status' => 'cancelled',
            'external_reference' => 'USGAR-CART-42',
            'transaction_amount' => 285.0,
        ]);
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->hold('CART-42', 'expired'));
        $this->bookingRepo->expects($this->once())->method('updateStatus')->with('CART-42', 'failed');
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')
            ->with('555666777', 'CART-42', 'rejected');
        $this->bookingRepo->expects($this->once())->method('recordAlert')
            ->with('CART-42', '555666777', 'cancelled');

        $response = $this->captureResponse(fn () => ($this->action)($this->signedRequest('555666777')));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('cancelled', $response['body']);
    }

    public function testMissingRequestIdReturns401BeforeSignatureVerification(): void {
        $this->paymentGateway->expects($this->never())->method('verifySignature');
        $this->paymentGateway->expects($this->never())->method('getPaymentDetails');
        $request = new Request('POST', '/api/webhook', [
            'x-signature' => 'ts=1,v1=irrelevant',
        ], ['type' => 'payment', 'data' => ['id' => '555666777']]);

        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(401, $response['code']);
    }

    // ---------------------------------------------------------------- todo 14

    public function testValidPaymentWithoutHoldIsMarkedOrphanAndAcked(): void {
        // Todo 14: firma valida + hold inexistente -> processed('orphan') +
        // 200 (corta el retry infinito de MP cada 15 min). Segunda entrega ->
        // 200 inmediato por idempotencia (SELECT in-txn antes del INSERT).
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn($this->approvedPayment('USGAR-ORPHAN-9'));
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn(null);
        $this->bookingRepo->method('isPaymentProcessed')->willReturnCallback(function (string $pid, string $eventType): bool {
            if ($eventType === 'orphan') {
                static $orphanChecks = 0;
                $orphanChecks++;
                return $orphanChecks > 1; // 1a entrega: no procesado; 2a: si
            }
            return false;
        });
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')->with('555666777', '', 'orphan');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $request = $this->signedRequest('555666777');
        $r1 = $this->captureResponse(fn () => ($this->action)($request));
        $r2 = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $r1['code']);
        $this->assertSame(200, $r2['code']);
        $this->assertStringContainsString('orphan', $r1['body']);
        $this->assertStringContainsString('orphan', $r2['body']);
    }

    public function testApprovedPaymentWithoutExternalReferenceIsMarkedOrphan(): void {
        // approved sin external_reference: el hold no es localizable -> orphan.
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn([
            'id' => 555666777,
            'status' => 'approved',
            'external_reference' => null,
            'transaction_amount' => 285.0,
        ]);
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')->with('555666777', '', 'orphan');
        $this->bookingRepo->expects($this->never())->method('getByCartIdForUpdate');

        $request = $this->signedRequest('555666777');
        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $response['code']);
    }

    // ---------------------------------------------------------------- todo 16

    public function testFraudBranchAcks200AndMarksProcessed(): void {
        // Todo 16: monto insuficiente -> updateStatus(FraudReview) +
        // markPaymentProcessed('fraud_review') EN LA MISMA txn + 200 (antes
        // 400 sin marcar -> loop de reintentos de MP). Segunda entrega -> 200
        // por idempotencia, sin repetir updateStatus ni el INSERT.
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn($this->approvedPayment('USGAR-CART-42', 50.0));
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->hold('CART-42'));
        $this->bookingRepo->method('isPaymentProcessed')->willReturnCallback(function (string $pid, string $eventType): bool {
            if ($eventType === 'fraud_review') {
                static $fraudChecks = 0;
                $fraudChecks++;
                return $fraudChecks > 1;
            }
            return false;
        });
        $this->bookingRepo->expects($this->once())->method('updateStatus')->with('CART-42', 'fraud_review');
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')->with('555666777', 'CART-42', 'fraud_review');
        $this->bookingRepo->expects($this->once())->method('recordAlert')->with('CART-42', '555666777', 'fraud_review');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $request = $this->signedRequest('555666777');
        $r1 = $this->captureResponse(fn () => ($this->action)($request));
        $r2 = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $r1['code']);
        $this->assertSame(200, $r2['code']);
        $this->assertStringContainsString('fraud_review', $r1['body']);
    }

    public function testApprovedWebhookDoesNotBypassFraudReview(): void {
        // An approved webhook after fraud_review cannot bypass the explicit
        // RetryManualReviewAction operator flow.
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn($this->approvedPayment('USGAR-CART-42', 285.0));
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->hold('CART-42', 'fraud_review'));
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')->with('555666777', 'CART-42', 'approved_after_review');
        $this->bookingRepo->expects($this->once())->method('recordAlert')->with('CART-42', '555666777', 'approved_after_review');
        $this->bookingRepo->expects($this->never())->method('updateStatus');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $response = $this->captureResponse(fn () => ($this->action)($this->signedRequest('555666777')));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('manual review', $response['body']);
    }

    public function testApprovedWebhookDoesNotBypassManualReview(): void {
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn($this->approvedPayment('USGAR-CART-MANUAL', 285.0));
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->hold('CART-MANUAL', 'manual_review'));
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')->with('555666777', 'CART-MANUAL', 'approved_after_review');
        $this->bookingRepo->expects($this->once())->method('recordAlert')->with('CART-MANUAL', '555666777', 'approved_after_review');
        $this->bookingRepo->expects($this->never())->method('updateStatus');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $response = $this->captureResponse(fn () => ($this->action)($this->signedRequest('555666777')));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('manual_review', $response['body']);
    }

    // ------------------------------------------------ coordinacion USGAR- prefix

    public function testExternalReferencePrefixIsStrippedBeforeHoldLookup(): void {
        // Coordinacion W3: el create (todo 3) envia external_reference
        // 'USGAR-{cartId}'; el webhook debe STRIP-ear el prefijo antes de
        // resolver el hold.
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn($this->approvedPayment('USGAR-CART-STRIP'));
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->bookingRepo->expects($this->once())->method('getByCartIdForUpdate')
            ->with('CART-STRIP')->willReturn($this->hold('CART-STRIP'));

        $request = $this->signedRequest('555666777');
        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $response['code']);
    }

    public function testExternalReferenceWithoutPrefixResolvesBackCompat(): void {
        // Back-compat: ref sin prefijo (legacy) se usa directo.
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn($this->approvedPayment('CART-LEGACY'));
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->bookingRepo->expects($this->once())->method('getByCartIdForUpdate')
            ->with('CART-LEGACY')->willReturn($this->hold('CART-LEGACY'));

        $request = $this->signedRequest('555666777');
        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $response['code']);
    }

    // ------------------------------------------------------------ regresiones

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
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn($this->approvedPayment('USGAR-CART-42'));
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(true);
        $this->bookingRepo->expects($this->never())->method('markPaymentProcessed');
        $this->bookingRepo->expects($this->never())->method('updateStatus');

        $request = $this->signedRequest('555666777');
        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('already processed', $response['body']);
    }

    public function testDoubleDeliveryRegistersExactlyOneProcessedRow(): void {
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn($this->approvedPayment('USGAR-CART-42'));
        $this->bookingRepo->method('isPaymentProcessed')->willReturnOnConsecutiveCalls(false, true);
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->hold('CART-42'));
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')->with('555666777', 'CART-42', 'approved');
        $this->eventDispatcher->expects($this->once())->method('dispatch');

        $request = $this->signedRequest('555666777');
        $r1 = $this->captureResponse(fn () => ($this->action)($request));
        $r2 = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $r1['code']);
        $this->assertSame(200, $r2['code']);
        $this->assertStringContainsString('Payment processed locally', $r1['body']);
        $this->assertStringContainsString('already processed', $r2['body']);
    }

    public function testIsPaymentProcessedFailureReturns500AndPersistsNothing(): void {
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn($this->approvedPayment('USGAR-CART-42'));
        $this->bookingRepo->method('isPaymentProcessed')
            ->willThrowException(new \PDOException('connection lost during idempotency check'));
        $this->bookingRepo->expects($this->never())->method('markPaymentProcessed');
        $this->bookingRepo->expects($this->never())->method('updateStatus');

        $request = $this->signedRequest('555666777');
        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(500, $response['code']);
        $this->assertStringContainsString('Error interno', $response['body']);
    }

    public function testApprovedOnExpiredHoldMarksExpiredPaidAndRecordsAlert(): void {
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn($this->approvedPayment('USGAR-CART-42'));
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->hold('CART-42', 'expired'));

        $this->bookingRepo->expects($this->once())->method('updateStatus')->with('CART-42', 'expired_paid');
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')->with('555666777', 'CART-42', 'approved');
        $this->bookingRepo->expects($this->once())->method('recordAlert')->with('CART-42', '555666777', 'expired_paid');

        $request = $this->signedRequest('555666777');
        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('expired_paid', $response['body']);
    }

    public function testApprovedPaymentMarksPaidAndDispatchesEvent(): void {
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn($this->approvedPayment('USGAR-CART-42'));
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->hold('CART-42'));

        $this->bookingRepo->expects($this->once())->method('updateStatus')->with('CART-42', 'paid');
        $this->bookingRepo->expects($this->once())->method('attachPaymentId')->with('CART-42', '555666777');
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')->with('555666777', 'CART-42', 'approved');
        $this->eventDispatcher->expects($this->once())->method('dispatch');

        $request = $this->signedRequest('555666777');
        $response = $this->captureResponse(fn () => ($this->action)($request));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('Payment processed locally', $response['body']);
    }

    public function testApprovedWebhookPersistsPaymentIdBeforePaidTransition(): void {
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn($this->approvedPayment('USGAR-CART-42'));
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->hold('CART-42'));
        $order = [];
        $this->bookingRepo->method('attachPaymentId')->willReturnCallback(function () use (&$order): bool {
            $order[] = 'attachPaymentId';
            return true;
        });
        $this->bookingRepo->method('updateStatus')->willReturnCallback(function () use (&$order): bool {
            $order[] = 'updateStatus';
            return true;
        });
        $this->bookingRepo->method('markPaymentProcessed')->willReturnCallback(function () use (&$order): bool {
            $order[] = 'markPaymentProcessed';
            return true;
        });
        $this->eventDispatcher->expects($this->once())->method('dispatch');

        $response = $this->captureResponse(fn () => ($this->action)($this->signedRequest('555666777')));

        $this->assertSame(200, $response['code']);
        $this->assertSame(['attachPaymentId', 'updateStatus', 'markPaymentProcessed'], $order);
    }

    // ------------------------------------------------------------ todo 23

    public function testOrderIsCommitAfterOutboxInsertAndAckLast(): void {
        // Todo 23: orden final beginTransaction -> lock -> validar -> fetch ->
        // updateStatus + markPaymentProcessed + INSERT outbox (dispatch) ->
        // commit -> jsonAsync 200. NADA despues del commit (los listeners
        // corren en el cron; el ACK no deja ventana de perdida post-200).
        $order = [];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturnCallback(function () use (&$order): bool {
            $order[] = 'begin';
            return true;
        });
        $pdo->method('commit')->willReturnCallback(function () use (&$order): bool {
            $order[] = 'commit';
            return true;
        });
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        $repo = $this->createMock(ProvisionalBookingRepository::class);
        $repo->method('isPaymentProcessed')->willReturn(false);
        $repo->method('getByCartIdForUpdate')->willReturn($this->hold('CART-42'));
        $repo->method('attachPaymentId')->willReturnCallback(function () use (&$order): bool {
            $order[] = 'attachPaymentId';
            return true;
        });
        $repo->method('updateStatus')->willReturnCallback(function () use (&$order): bool {
            $order[] = 'updateStatus';
            return true;
        });
        $repo->method('markPaymentProcessed')->willReturnCallback(function () use (&$order): bool {
            $order[] = 'markProcessed';
            return true;
        });

        $dispatcher = $this->createMock(EventDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function () use (&$order): void {
            $order[] = 'dispatch';
        });

        $gateway = $this->createMock(PaymentGatewayPortInterface::class);
        $gateway->method('verifySignature')->willReturn(true);
        $gateway->method('getPaymentDetails')->willReturn($this->approvedPayment('USGAR-CART-42'));

        $action = new HandleMercadoPagoWebhookAction($pdo, $gateway, $repo, $dispatcher);
        $response = $this->captureResponse(fn () => ($action)($this->signedRequest('555666777')));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('Payment processed locally', $response['body']);
        $this->assertSame(
            ['begin', 'attachPaymentId', 'updateStatus', 'markProcessed', 'dispatch', 'commit'],
            $order,
            'INSERT outbox (dispatch) ANTES del commit; ACK despues; sin trabajo pesado tras el commit.'
        );
    }
}
