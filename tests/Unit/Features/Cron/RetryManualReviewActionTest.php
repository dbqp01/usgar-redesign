<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Cron;

use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Core\Request;
use App\Core\Events\EventDispatcher;
use App\Features\Cron\Actions\RetryManualReviewAction;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use PDO;
use PDOStatement;

/**
 * Tests del cron de ManualReview/FraudReview (Wave 4, todo 24).
 *
 * - list: bookings en manual_review/fraud_review con contador de re-despachos
 *   (auditoria en payment_alerts) — endpoint protegido por CRON_SECRET en
 *   HTTP (mismo patron que CleanExpiredCartsAction; en CLI se omite).
 * - redispatch: re-chequea el pago contra la gateway; si el monto ya
 *   coincide -> fraud_review -> paid (guard del todo 9) + re-despacho del
 *   evento; si no coincide -> permanece en revisión (nunca confirmar PMS con
 *   monto errado). Log de auditoría por re-despacho.
 * - expire: libera el hold (status -> expired; FROM-set manual/fraud_review)
 *   para resolucion manual tras N re-despachos sin coincidencia.
 */
final class RetryManualReviewActionTest extends TestCase {
    private PDO $pdo;
    private PaymentGatewayPortInterface $paymentGateway;
    private ProvisionalBookingRepository $bookingRepo;
    private EventDispatcher $eventDispatcher;
    /** @var array<int,string> */
    private array $recorded = [];

    /** @var array<string, array<int,array<string,mixed>>> */
    private array $rowsByNeedle = [];

    /** @var array<string,int> */
    private array $rowCounts = [];

    /** @var array<int,string> */
    private array $statusCalls = [];

    private bool $dispatched = false;

    protected function setUp(): void {
        Config::set('APP_ENV', 'testing');
        Config::set('CRON_SECRET', 'w4-test-secret');
        Config::set('EXCHANGE_RATE_USD_PEN', '3.80');

        $this->recorded = [];
        $this->rowsByNeedle = [];
        $this->rowCounts = [];
        $this->statusCalls = [];
        $this->dispatched = false;

        $this->pdo = $this->createMock(PDO::class);
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->method('commit')->willReturnCallback(function (): bool {
            $this->recorded[] = 'commit';
            return true;
        });
        $this->pdo->method('rollBack')->willReturn(true);
        $this->pdo->method('inTransaction')->willReturn(false);
        $this->pdo->method('prepare')->willReturnCallback(function (string $sql): PDOStatement {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('rowCount')->willReturnCallback(function () use ($sql): int {
                foreach ($this->rowCounts as $needle => $n) {
                    if (str_contains($sql, $needle)) {
                        return $n;
                    }
                }
                return 0;
            });
            $stmt->method('fetchAll')->willReturnCallback(function () use ($sql): array {
                foreach ($this->rowsByNeedle as $needle => $rows) {
                    if (str_contains($sql, $needle)) {
                        return $rows;
                    }
                }
                return [];
            });
            return $stmt;
        });

        $this->paymentGateway = $this->createMock(PaymentGatewayPortInterface::class);
        $this->bookingRepo = $this->createMock(ProvisionalBookingRepository::class);
        $this->bookingRepo->method('recordAlert')->willReturnCallback(function (string $cartId, string $paymentId, string $type): bool {
            $this->recorded[] = "alert:{$type}";
            return true;
        });
        $this->bookingRepo->method('updateStatus')->willReturnCallback(function (string $cartId, string $status): bool {
            $this->statusCalls[] = $status;
            return true;
        });
        $this->bookingRepo->method('markPaymentProcessed')->willReturn(true);

        $this->eventDispatcher = $this->createMock(EventDispatcher::class);
        $this->eventDispatcher->method('dispatch')->willReturnCallback(function (): void {
            $this->dispatched = true;
        });

        $this->action = new RetryManualReviewAction(
            $this->pdo,
            $this->paymentGateway,
            $this->bookingRepo,
            $this->eventDispatcher
        );
    }

    private RetryManualReviewAction $action;

    private function reviewHold(string $cartId, string $status = 'fraud_review', ?string $paymentId = '777888999', float $price = 75.0): array {
        return [
            'cart_id'       => $cartId,
            'status'        => $status,
            'payment_id'    => $paymentId,
            'price_snapshot'=> $price,
            'price_snapshot_pen' => null,
            'exchange_rate_snapshot' => null,
            'checkin'       => '2026-09-01',
            'checkout'      => '2026-09-03',
            'id_room_type'  => 2,
            'guest_data'    => [],
            'room_data'     => [],
            'expires_at'    => date('Y-m-d H:i:s', time() + 600),
            'created_at'    => date('Y-m-d H:i:s'),
        ];
    }

    /** @return array{code:int, body:string} */
    private function invoke(array $body, array $query = []): array {
        $queryString = '';
        foreach ($query as $k => $v) {
            $queryString .= ($queryString === '' ? '?' : '&') . $k . '=' . $v;
        }
        $request = new Request('POST', '/api/cron/manual-review' . $queryString, ['x-cron-secret' => 'w4-test-secret'], $body);
        ob_start();
        ($this->action)($request);
        return ['code' => http_response_code(), 'body' => (string)ob_get_clean()];
    }

    // ------------------------------------------------------------------ list

    public function testListReturnsManualAndFraudReviewHolds(): void {
        $this->rowsByNeedle['FROM provisional_bookings pb'] = [
            ['cart_id' => 'CART-MR', 'status' => 'manual_review', 'payment_id' => '111', 'price_snapshot' => '75.00', 'redispatch_count' => 2],
            ['cart_id' => 'CART-FR', 'status' => 'fraud_review', 'payment_id' => '222', 'price_snapshot' => '75.00', 'redispatch_count' => 5],
        ];

        $r = $this->invoke(['action' => 'list']);

        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('CART-MR', $r['body']);
        $this->assertStringContainsString('CART-FR', $r['body']);
        $this->assertStringContainsString('manual_review', $r['body']);
        $this->assertStringContainsString('fraud_review', $r['body']);
        $this->assertStringContainsString('redispatch_count', $r['body']);
    }

    // ------------------------------------------------------------ redispatch

    public function testRedispatchFraudReviewWithMatchingPaymentBecomesPaid(): void {
        // NOTA r2: si el monto ya coincide tras el re-despacho, la transicion
        // fraud_review -> paid DEBE ejecutarse (guard del todo 9 lo permite) y
        // el evento se re-despacha (outbox -> listeners).
        $this->bookingRepo->method('getByCartId')->willReturn($this->reviewHold('CART-FR'));
        $this->paymentGateway->method('getPaymentDetails')->willReturn([
            'id' => 777888999,
            'status' => 'approved',
            'external_reference' => 'USGAR-CART-FR',
            'transaction_amount' => 285.0, // 75 USD x 3.80 = 285 PEN -> coincide
        ]);

        $r = $this->invoke(['action' => 'redispatch', 'cart_id' => 'CART-FR']);

        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('paid', $r['body']);
        $this->assertSame(['paid'], $this->statusCalls, 'fraud_review -> paid tras re-despacho con monto correcto.');
        $this->assertTrue($this->dispatched, 'El evento se re-despacha (outbox).');
        $this->assertContains('alert:redispatch', $this->recorded, 'Auditoria por re-despacho.');
    }

    public function testRedispatchWithoutMatchKeepsFraudReview(): void {
        // Monto cobrado (50) < esperado (285): permanece en fraud_review;
        // NUNCA confirmar el PMS con monto errado (no dispatch).
        $this->bookingRepo->method('getByCartId')->willReturn($this->reviewHold('CART-FR'));
        $this->paymentGateway->method('getPaymentDetails')->willReturn([
            'id' => 777888999,
            'status' => 'approved',
            'external_reference' => 'USGAR-CART-FR',
            'transaction_amount' => 50.0,
        ]);

        $r = $this->invoke(['action' => 'redispatch', 'cart_id' => 'CART-FR']);

        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('still_fraud_review', $r['body']);
        $this->assertNotContains('paid', $this->statusCalls, 'Sin coincidencia -> no transiciona a paid.');
        $this->assertFalse($this->dispatched, 'Sin coincidencia -> no se despacha el evento (monto errado).');
        $this->assertContains('alert:redispatch', $this->recorded, 'Auditoria del re-despacho sin exito.');
    }

    public function testRedispatchRequiresPaymentId(): void {
        $this->bookingRepo->method('getByCartId')->willReturn($this->reviewHold('CART-MR', 'manual_review', null));
        $this->paymentGateway->expects($this->never())->method('getPaymentDetails');

        $r = $this->invoke(['action' => 'redispatch', 'cart_id' => 'CART-MR']);

        $this->assertSame(400, $r['code']);
        $this->assertStringContainsString('payment', $r['body']);
    }

    // --------------------------------------------------------------- expire

    public function testExpireReleasesFraudReviewHold(): void {
        // NOTA r4: tras N re-despachos sin coincidencia, el hold puede
        // EXPIRARSE (guard ->expired incluye fraud_review) y quedar para
        // resolucion manual (reembolso/manual).
        $this->rowCounts['SET status = \'expired\''] = 1;
        $this->bookingRepo->method('getByCartId')->willReturn($this->reviewHold('CART-FR'));

        $r = $this->invoke(['action' => 'expire', 'cart_id' => 'CART-FR']);

        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('expired', $r['body']);
        $this->assertContains('alert:expired_manual', $this->recorded, 'Auditoria del release.');
    }

    public function testExpireWithoutReviewHoldReturnsError(): void {
        $this->rowCounts['SET status = \'expired\''] = 0;

        $r = $this->invoke(['action' => 'expire', 'cart_id' => 'CART-NADA']);

        $this->assertSame(404, $r['code']);
    }
}
