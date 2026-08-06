<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Booking\Domain\Listeners;

require_once __DIR__ . '/../../../../../fixtures/W4TestDoubles.php';

use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Features\Booking\Domain\Events\BookingPaidEvent;
use App\Features\Booking\Domain\Listeners\SyncChannexBookingListener;
use App\Test\Fixtures\W4ChannexPortDouble;

/**
 * Tests del listener de Channex (Wave 4, todo 22).
 *
 * - createBooking con fallo -> exception (no false): el adapter ya NO traga
 *   errores; el listener hace 1 intento y deja propagar (el outbox reintenta
 *   via el cron del todo 19).
 * - DEDUP del consumidor: antes de crear, consulta si ya existe booking con
 *   external_reference = USGAR-{cartId}; si existe -> no recrear (ventana
 *   crash entre createBooking exitoso y el write COMPLETED del outbox).
 * - FAIL-CLOSED: si el pre-chequeo falla (Channex caido) -> THROW, nunca
 *   "no existe" por error.
 * - Todo 25: Channex recibe monto PEN (amount_pen), no USD.
 *
 * Test double in-memory del PUERTO ChannelManagerPortInterface (sin red) que
 * cuenta llamadas — nunca un mock de resultados de MercadoPago (mandato r10).
 */
final class SyncChannexBookingListenerTest extends TestCase {
    private W4ChannexPortDouble $channex;

    protected function setUp(): void {
        Config::set('APP_ENV', 'testing');
        Config::set('CHANNEX_DEFAULT_GUEST_NAME', 'Huesped USGAR');
        Config::set('DEFAULT_GUEST_EMAIL', 'reserva@test.com');
        $this->channex = new W4ChannexPortDouble();
        $this->channex->createResult = true;
    }

    private function paidEvent(int $amountPen = 28500, float $usd = 75.0): BookingPaidEvent {
        return new BookingPaidEvent(
            'CART-CX',
            '555666777',
            $usd,
            '2026-09-01',
            '2026-09-03',
            2,
            ['name' => 'Ana Test', 'email' => 'ana@test.com', 'phone' => '+51999999999', 'guests' => 2],
            [],
            $amountPen,
            'PEN',
            3.80
        );
    }

    public function testCreateFailureThrowsSoOutboxRetries(): void {
        // El adapter lanza ante fallo (todo 22, no false): el listener deja
        // propagar -> el outbox marca FAILED y reintenta.
        $listener = new SyncChannexBookingListener($this->channex);
        $this->channex->createResult = true;
        // Simula el adapter lanzando (fallo de red / HTTP error).
        $throwing = new class implements \App\Features\Shared\Ports\ChannelManagerPortInterface {
            public function createBooking(
                string $bookingId,
                string $checkIn,
                string $checkOut,
                int $idRoomType,
                float $totalPrice,
                string $guestName,
                string $guestEmail,
                string $guestPhone = '',
                int $adults = 2
            ): bool {
                throw new \RuntimeException('Channex HTTP 500 en createBooking');
            }
            public function fetchBookingRevision(string $revisionId): ?array { return null; }
            public function acknowledgeRevision(string $revisionId): bool { return true; }
            public function findBookingByExternalReference(string $externalReference): ?array { return null; }
        };

        $listener = new SyncChannexBookingListener($throwing);
        $this->expectException(\RuntimeException::class);
        $listener->handle($this->paidEvent());
    }

    public function testDoubleDispatchCreatesExactlyOnce(): void {
        // 1a entrega: no existe -> createBooking. 2a entrega (re-delivery del
        // outbox tras crash): el pre-chequeo encuentra el booking ->
        // NO recrear.
        $listener = new SyncChannexBookingListener($this->channex);

        $listener->handle($this->paidEvent());
        $this->assertSame(1, $this->channex->createCalls, 'Primera entrega crea el booking.');

        // Simula que el booking ya existe en Channex (createBooking fue exitoso
        // pero el outbox no llego a marcar COMPLETED).
        $this->channex->existingBooking = ['id' => 'cx-1', 'attributes' => ['reservation_id' => 'USG-CART-CX']];
        $listener->handle($this->paidEvent());

        $this->assertSame(1, $this->channex->createCalls, 'Doble entrega -> 1 sola creacion (dedup por external_reference).');
    }

    public function testDedupSkipsCreateWhenBookingAlreadyExists(): void {
        $this->channex->existingBooking = ['id' => 'cx-1'];

        $listener = new SyncChannexBookingListener($this->channex);
        $listener->handle($this->paidEvent());

        $this->assertSame(0, $this->channex->createCalls, 'Booking ya existe -> no recrear.');
    }

    public function testFailClosedPrecheckThrowsNeverSkipOnError(): void {
        // Channex caido durante el pre-chequeo -> THROW (el outbox reintenta);
        // NUNCA "no existe" por error (recrearia y duplicaria).
        $this->channex->findThrows = true;

        $listener = new SyncChannexBookingListener($this->channex);

        $this->expectException(\Exception::class);
        $listener->handle($this->paidEvent());
    }

    public function testListenerSendsPenAmountToChannex(): void {
        // Todo 25: Channex recibe monto PEN (285.00), no USD (75.00).
        $listener = new SyncChannexBookingListener($this->channex);
        $listener->handle($this->paidEvent(28500, 75.0));

        $this->assertSame(1, $this->channex->createCalls);
        $this->assertCount(1, $this->channex->createArgs);
        $args = $this->channex->createArgs[0];
        $this->assertSame('CART-CX', $args[0]);
        $this->assertSame('2026-09-01', $args[1]);
        $this->assertSame('2026-09-03', $args[2]);
        $this->assertSame(285.0, $args[4], 'Monto PEN (285.00), no USD (75.00).');
        $this->assertSame('Ana Test', $args[5]);
        $this->assertSame('ana@test.com', $args[6]);
    }
}
