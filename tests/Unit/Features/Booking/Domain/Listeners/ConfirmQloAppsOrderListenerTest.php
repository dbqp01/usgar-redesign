<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Booking\Domain\Listeners;

require_once __DIR__ . '/../../../../../fixtures/W4TestDoubles.php';

use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Features\Booking\Domain\Events\BookingPaidEvent;
use App\Features\Booking\Domain\Listeners\ConfirmQloAppsOrderListener;
use App\Test\Fixtures\W4PmsPortDouble;

/**
 * Tests del listener de QloApps (Wave 4, todo 21).
 *
 * - confirmOrder null/false -> THROW (el outbox reintenta via el cron del
 *   todo 19); sin retry loop interno (1 intento).
 * - DEDUP del consumidor: UNICO mecanismo = pre-chequeo por
 *   external_reference = USGAR-{cartId} (si la orden YA esta confirmada ->
 *   skip/exito sin llamar confirmOrder). La ventana crash entre un
 *   confirmOrder exitoso y el write COMPLETED del outbox deja el evento
 *   IN_PROGRESS y el reclaim del todo 19 lo re-entrega -> doble confirm sin
 *   el dedup.
 * - FAIL-CLOSED en el pre-chequeo: si falla (PMS caido) -> THROW, nunca skip
 *   por error. El null de un confirmOrder real SIEMPRE hace throw (el
 *   dedup-skip es un camino de exito distinto).
 * - El listener usa amount_pen (PEN) para el PMS (todo 25).
 *
 * Test double in-memory del PUERTO PmsPortInterface (sin red) que cuenta
 * llamadas — nunca un mock de resultados de MercadoPago (mandato r10).
 */
final class ConfirmQloAppsOrderListenerTest extends TestCase {
    private W4PmsPortDouble $pms;

    protected function setUp(): void {
        Config::set('APP_ENV', 'testing');
        Config::set('QLOAPPS_DEFAULT_GUEST_NAME', 'Huesped USGAR');
        Config::set('DEFAULT_GUEST_EMAIL', 'reserva@test.com');
        $this->pms = new W4PmsPortDouble();
        $this->pms->confirmResult = 'OK-ORDER-1';
    }

    private function paidEvent(int $amountPen = 28500, float $usd = 75.0): BookingPaidEvent {
        return new BookingPaidEvent(
            'CART-Q',
            '555666777',
            $usd,
            '2026-09-01',
            '2026-09-03',
            2,
            ['name' => 'Ana Test', 'email' => 'ana@test.com', 'guests' => 2],
            [],
            $amountPen,
            'PEN',
            3.80
        );
    }

    public function testConfirmOrderNullThrowsSoOutboxRetries(): void {
        $this->pms->confirmResult = null; // PMS falla en silencio (null)

        $listener = new ConfirmQloAppsOrderListener($this->pms);

        $this->expectException(\RuntimeException::class);
        $listener->handle($this->paidEvent());
    }

    public function testDoubleDispatchConfirmsExactlyOnce(): void {
        // 1a entrega: no confirmada -> confirmOrder. La orden queda confirmada;
        // 2a entrega (re-delivery del outbox tras crash): el pre-chequeo ve la
        // orden confirmada -> SKIP sin llamar confirmOrder.
        $confirmations = 0;
        $this->pms->dedupResult = false;
        $this->pms->confirmResult = 'OK-ORDER-1';
        $listener = new ConfirmQloAppsOrderListener($this->pms);

        $this->pms->confirmResult = 'OK-ORDER-1';
        $listener->handle($this->paidEvent());
        $confirmations = $this->pms->confirmOrderCalls;
        $this->assertSame(1, $confirmations, 'Primera entrega confirma la orden.');

        $this->pms->dedupResult = true; // la orden YA esta confirmada en QloApps
        $listener->handle($this->paidEvent());

        $this->assertSame(1, $this->pms->confirmOrderCalls, 'Doble entrega -> 1 sola confirmacion (dedup por external_reference).');
        $this->assertSame(2, $this->pms->dedupCalls, 'El pre-chequeo se consulta en CADA entrega (2a -> dedup-skip).');
    }

    public function testDedupSkipsConfirmWhenOrderAlreadyConfirmed(): void {
        $this->pms->dedupResult = true;

        $listener = new ConfirmQloAppsOrderListener($this->pms);
        $listener->handle($this->paidEvent());

        $this->assertSame(0, $this->pms->confirmOrderCalls, 'Orden ya confirmada -> skip sin llamar confirmOrder.');
        $this->assertSame(1, $this->pms->dedupCalls);
    }

    public function testFailClosedPrecheckThrowsNeverSkipOnError(): void {
        // PMS caido durante el pre-chequeo -> THROW (el outbox reintenta);
        // NUNCA skip por error (perderia la confirmacion).
        $this->pms->dedupThrows = true;

        $listener = new ConfirmQloAppsOrderListener($this->pms);

        $this->expectException(\Exception::class);
        $listener->handle($this->paidEvent());
    }

    public function testListenerSendsPenAmountToPms(): void {
        // Todo 25: los adapters reciben monto PEN (285.00), no USD (75.00).
        $this->pms->confirmResult = 'OK-ORDER-1';
        $this->pms->dedupResult = false;

        $listener = new ConfirmQloAppsOrderListener($this->pms);
        $listener->handle($this->paidEvent(28500, 75.0));

        $this->assertSame(1, $this->pms->confirmOrderCalls);
        $this->assertCount(1, $this->pms->confirmArgs);
        $this->assertSame('CART-Q', $this->pms->confirmArgs[0][0]);
        $this->assertSame(285.0, $this->pms->confirmArgs[0][1], 'Monto PEN (285.00), no USD (75.00).');
        $this->assertSame('Ana Test', $this->pms->confirmArgs[0][2]);
        $this->assertSame('ana@test.com', $this->pms->confirmArgs[0][3]);
    }
}
