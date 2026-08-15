<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Cron;

use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Core\Events\EventDispatcher;
use App\Features\Cron\Actions\ReconcilePaymentsAction;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use PDO;

/**
 * Tests del job de reconciliacion: recupera reservas pendientes cuyo webhook
 * nunca llego, consultando el estado real del pago en MercadoPago.
 */
final class ReconcilePaymentsActionTest extends TestCase {
    private PDO $pdo;
    private PaymentGatewayPortInterface $paymentGateway;
    private ProvisionalBookingRepository $bookingRepo;
    private EventDispatcher $eventDispatcher;
    private ReconcilePaymentsAction $action;

    protected function setUp(): void {

        $this->pdo = $this->createMock(PDO::class);
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->method('commit')->willReturn(true);
        $this->pdo->method('inTransaction')->willReturn(false);

        $this->paymentGateway = $this->createMock(PaymentGatewayPortInterface::class);
        $this->bookingRepo = $this->createMock(ProvisionalBookingRepository::class);
        $this->eventDispatcher = $this->createMock(EventDispatcher::class);

        $this->action = new ReconcilePaymentsAction(
            $this->pdo,
            $this->paymentGateway,
            $this->bookingRepo,
            $this->eventDispatcher
        );
    }

    private function hold(array $overrides = []): array {
        return array_merge([
            'cart_id' => 'CART-REC-1',
            'status' => 'pending',
            'payment_id' => '777888999',
            'price_snapshot' => 75.0,
            'checkin' => '2026-09-01',
            'checkout' => '2026-09-03',
            'id_room_type' => 2,
            'guest_data' => [],
            'room_data' => [],
        ], $overrides);
    }

    public function testSkipsWhenNoPendingHolds(): void {
        $this->bookingRepo->method('getPendingHoldsWithPaymentId')->willReturn([]);
        $this->paymentGateway->expects($this->never())->method('getPaymentDetails');

        $result = ($this->action)();

        $this->assertSame(0, $result['checked']);
        $this->assertSame(0, $result['reconciled']);
    }

    public function testApprovedPaymentCompletesHoldAndDispatchesEvent(): void {
        $this->bookingRepo->method('getPendingHoldsWithPaymentId')->willReturn([$this->hold()]);
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->paymentGateway->method('getPaymentDetails')->willReturn([
            'id' => 777888999,
            'status' => 'approved',
            'external_reference' => 'CART-REC-1',
            'transaction_amount' => 285.0,
        ]);

        $this->bookingRepo->expects($this->once())->method('updateStatus')->with('CART-REC-1', 'paid');
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')->with('777888999', 'CART-REC-1', 'approved');
        $this->eventDispatcher->expects($this->once())->method('dispatch');

        $result = ($this->action)();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['reconciled']);
    }

    public function testPendingPaymentInMpIsNotReconciled(): void {
        $this->bookingRepo->method('getPendingHoldsWithPaymentId')->willReturn([$this->hold()]);
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->paymentGateway->method('getPaymentDetails')->willReturn([
            'id' => 777888999,
            'status' => 'pending',
            'external_reference' => 'CART-REC-1',
        ]);

        $this->bookingRepo->expects($this->never())->method('updateStatus');
        $this->bookingRepo->expects($this->never())->method('markPaymentProcessed');

        $result = ($this->action)();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(0, $result['reconciled']);
    }

    public function testRejectedPaymentInMpMarksHoldFailed(): void {
        // Fix 2026-08-13 (clase rechazo): webhook nunca llegado + pago
        // rejected en MP -> pending -> failed (antes: skip sin transicion y
        // la pagina de exito mostraba 'expired' por TTL).
        $this->bookingRepo->method('getPendingHoldsWithPaymentId')->willReturn([$this->hold()]);
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(false);
        $this->paymentGateway->method('getPaymentDetails')->willReturn([
            'id' => 777888999,
            'status' => 'rejected',
            'status_detail' => 'cc_rejected_other_reason',
            'external_reference' => 'CART-REC-1',
            'transaction_amount' => 285.0,
        ]);

        $this->bookingRepo->expects($this->once())->method('updateStatus')->with('CART-REC-1', 'failed');
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')->with('777888999', 'CART-REC-1', 'rejected');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $result = ($this->action)();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['reconciled']);
    }

    public function testAlreadyProcessedPaymentIsSkipped(): void {
        $this->bookingRepo->method('getPendingHoldsWithPaymentId')->willReturn([$this->hold()]);
        $this->bookingRepo->method('isPaymentProcessed')->willReturn(true);
        $this->paymentGateway->expects($this->never())->method('getPaymentDetails');

        $result = ($this->action)();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(0, $result['reconciled']);
    }

    public function testHoldWithoutPaymentIdIsSkipped(): void {
        $this->bookingRepo->method('getPendingHoldsWithPaymentId')->willReturn([$this->hold(['payment_id' => null])]);
        $this->paymentGateway->expects($this->never())->method('getPaymentDetails');

        $result = ($this->action)();

        $this->assertSame(0, $result['checked']);
    }
}
