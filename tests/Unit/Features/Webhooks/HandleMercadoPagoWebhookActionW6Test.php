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
 * Tests de la Wave 6 (todo 32): la comparacion de montos del webhook se
 * migra a CENTAVOS ENTEROS usando el PEN CONGELADO al cotizar
 * (price_snapshot_pen, escrito por CreateBookingAction — todo 25 W4) en
 * lugar de derivar con la tasa ACTUAL. Sin esto, un cambio de
 * EXCHANGE_RATE_USD_PEN entre la cotizacion y el cobro produce FALSOS
 * fraudes (pago correcto -> fraud_review).
 *
 * Fixtures firmados: payload oficial + firma HMAC real (mismo patron W3).
 */
final class HandleMercadoPagoWebhookActionW6Test extends TestCase {
    private PDO $pdo;
    private PaymentGatewayPortInterface $paymentGateway;
    private ProvisionalBookingRepository $bookingRepo;
    private EventDispatcher $eventDispatcher;
    private HandleMercadoPagoWebhookAction $action;

    protected function setUp(): void {
        Config::set('MERCADO_PAGO_WEBHOOK_SECRET', W3WebhookFixtures::TEST_SECRET);
        // Tasa ACTUAL: 3.90 — distinta de la congelada en los holds (3.75).
        Config::set('EXCHANGE_RATE_USD_PEN', '3.90');

        $this->pdo = $this->createMock(PDO::class);
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->method('commit')->willReturn(true);
        $this->pdo->method('rollBack')->willReturn(true);
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

    protected function tearDown(): void {
        Config::set('EXCHANGE_RATE_USD_PEN', '3.80');
    }

    private function acceptSignature(): void {
        $this->paymentGateway->method('verifySignature')->willReturn(true);
    }

    private function captureResponse(callable $fn): array {
        ob_start();
        $fn();
        $body = (string) ob_get_clean();
        return ['code' => http_response_code(), 'body' => $body];
    }

    private function signedRequest(string $dataId): Request {
        return new Request(
            'POST',
            '/api/webhook',
            [
                'x-signature' => W3WebhookFixtures::signatureHeader($dataId, 'req-w6'),
                'x-request-id' => 'req-w6',
            ],
            ['type' => 'payment', 'data' => ['id' => $dataId]]
        );
    }

    /**
     * Hold cotizado a 75 USD con tasa 3.75 -> price_snapshot_pen = 281.25
     * CONGELADO. La tasa actual del entorno es 3.90 (cambio posterior).
     */
    private function frozenHold(string $cartId, string $status = 'pending'): array {
        return [
            'cart_id'                 => $cartId,
            'status'                  => $status,
            'price_snapshot'          => 75.0,
            'price_snapshot_pen'      => 281.25,
            'exchange_rate_snapshot'  => 3.75,
            'checkin'                 => '2026-09-01',
            'checkout'                => '2026-09-03',
            'id_room_type'            => 2,
            'guest_data'              => ['email' => 'x@test.com'],
            'room_data'               => [],
        ];
    }

    private function approvedPayment(string $externalRef, float $amount): array {
        return [
            'id' => 666777888,
            'status' => 'approved',
            'external_reference' => $externalRef,
            'transaction_amount' => $amount,
        ];
    }

    public function testApprovedWithFrozenPenMatchesPaidDespiteRateChange(): void {
        // Todo 32 (QA+): hold con PEN congelado 281.25 (tasa 3.75 al
        // cotizar); el entorno ahora tiene tasa 3.90. El pago approved de
        // 281.25 DEBE quedar PAID sin falso fraude — la comparacion usa el
        // congelado, nunca la tasa actual.
        // RED: la comparacion actual deriva 75 x 3.90 = 292.50 y marca
        // fraud_review (281.25 < 292.50).
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn($this->approvedPayment('USGAR-CART-W6', 281.25));
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->frozenHold('CART-W6'));

        $this->bookingRepo->expects($this->once())->method('updateStatus')->with('CART-W6', 'paid');
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')->with('666777888', 'CART-W6', 'approved');
        $this->eventDispatcher->expects($this->once())->method('dispatch');

        $response = $this->captureResponse(fn () => ($this->action)($this->signedRequest('666777888')));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('Payment processed locally', $response['body']);
    }

    public function testChargedBelowFrozenPenCentsGoesFraudReview(): void {
        // Todo 32 (QA-): monto cobrado (280.00) MENOR al PEN congelado
        // (281.25) -> fraud_review (todo 16 sigue pasando; la comparacion en
        // centavos enteros: 28000 < 28125).
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn($this->approvedPayment('USGAR-CART-W6', 280.00));
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->frozenHold('CART-W6'));

        $this->bookingRepo->expects($this->once())->method('updateStatus')->with('CART-W6', 'fraud_review');
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')->with('666777888', 'CART-W6', 'fraud_review');
        $this->bookingRepo->expects($this->once())->method('recordAlert')->with('CART-W6', '666777888', 'fraud_review');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $response = $this->captureResponse(fn () => ($this->action)($this->signedRequest('666777888')));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('fraud_review', $response['body']);
    }

    public function testLegacyHoldWithoutFrozenPenFallsBackToCurrentRateDerivation(): void {
        // Todo 32 (back-compat): hold legacy SIN price_snapshot_pen ->
        // fallback documentado: derivar el PEN con la tasa ACTUAL
        // (75 x 3.90 = 292.50). Un pago de 292.50 queda paid sin fraude.
        $this->acceptSignature();
        $this->paymentGateway->method('getPaymentDetails')->willReturn($this->approvedPayment('USGAR-CART-LEGACY', 292.50));
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn([
            'cart_id'        => 'CART-LEGACY',
            'status'         => 'pending',
            'price_snapshot' => 75.0,
            'checkin'        => '2026-09-01',
            'checkout'       => '2026-09-03',
            'id_room_type'   => 2,
            'guest_data'     => [],
            'room_data'      => [],
        ]);

        $this->bookingRepo->expects($this->once())->method('updateStatus')->with('CART-LEGACY', 'paid');
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')->with('666777888', 'CART-LEGACY', 'approved');

        $response = $this->captureResponse(fn () => ($this->action)($this->signedRequest('666777888')));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('Payment processed locally', $response['body']);
    }
}
