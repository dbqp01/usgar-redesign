<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Booking\Domain;

use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Features\Booking\Domain\Events\BookingPaidEvent;

/**
 * Tests del evento de pago consciente de moneda (Wave 4, todo 25).
 *
 * El evento (y su payload de outbox) lleva amount_pen (int centavos),
 * currency = 'PEN' y exchange_rate CONGELADO del hold (escrito al cotizar
 * por CreateBookingAction — exchange_rate_snapshot / price_snapshot_pen).
 * Los listeners lo usan para el PMS: los adapters reciben monto PEN, no USD
 * (descalce USD/PEN eliminado — auditor 4).
 */
final class BookingPaidEventTest extends TestCase {
    protected function setUp(): void {
        Config::set('APP_ENV', 'testing');
        Config::set('EXCHANGE_RATE_USD_PEN', '3.80');
    }

    public function testFromHoldUsesFrozenPenColumns(): void {
        // Hold con las columnas congeladas (escritas al cotizar, todo 25):
        // amount_pen = (int)round(price_snapshot_pen * 100); exchange_rate =
        // exchange_rate_snapshot (la tasa de creacion, no la actual).
        $hold = [
            'price_snapshot'         => 75.0,
            'price_snapshot_pen'     => 281.25,   // 75 USD x 3.75 congelado
            'exchange_rate_snapshot' => 3.75,
            'checkin'                => '2026-09-01',
            'checkout'               => '2026-09-03',
            'id_room_type'           => 2,
            'guest_data'             => ['email' => 'x@test.com'],
            'room_data'              => [],
        ];

        $event = BookingPaidEvent::fromHold('CART-PEN', '555', $hold);

        $this->assertSame(28125, $event->getAmountPen(), 'amount_pen = centavos PEN del precio congelado.');
        $this->assertSame('PEN', $event->getCurrency());
        $this->assertSame(3.75, $event->getExchangeRate(), 'Tasa CONGELADA del hold (no la actual 3.80).');
    }

    public function testFromHoldLegacyWithoutColumnsDerivesFromUsdAndCurrentRate(): void {
        // Back-compat: hold legacy sin las columnas nuevas -> deriva el PEN
        // con la tasa actual (PriceCalculator::toGatewayPrice) y la reporta.
        $hold = [
            'price_snapshot' => 75.0,
            'checkin'        => '2026-09-01',
            'checkout'       => '2026-09-03',
            'id_room_type'   => 2,
            'guest_data'     => [],
            'room_data'      => [],
        ];

        $event = BookingPaidEvent::fromHold('CART-LEGACY', '555', $hold);

        $this->assertSame(28500, $event->getAmountPen(), '75 USD x 3.80 = 285.00 PEN -> 28500 centavos.');
        $this->assertSame('PEN', $event->getCurrency());
        $this->assertSame(3.80, $event->getExchangeRate());
    }

    public function testConstructorCarriesCurrencyFields(): void {
        $event = new BookingPaidEvent(
            'CART-X',
            '555',
            75.0,
            '2026-09-01',
            '2026-09-03',
            2,
            [],
            [],
            28125,
            'PEN',
            3.75
        );

        $this->assertSame(28125, $event->getAmountPen());
        $this->assertSame('PEN', $event->getCurrency());
        $this->assertSame(3.75, $event->getExchangeRate());
        $this->assertSame(75.0, $event->getAmount(), 'getAmount() USD se conserva (back-compat).');
    }

    public function testPayloadIncludesCurrencyFieldsForOutbox(): void {
        $event = BookingPaidEvent::fromHold('CART-PAY', '555', [
            'price_snapshot'         => 100.0,
            'price_snapshot_pen'     => 380.0,
            'exchange_rate_snapshot' => 3.80,
            'checkin'                => '2026-09-01',
            'checkout'               => '2026-09-03',
            'id_room_type'           => 1,
            'guest_data'             => [],
            'room_data'              => [],
        ]);

        $payload = $event->getPayload();

        $this->assertSame(38000, $payload['amount_pen']);
        $this->assertSame('PEN', $payload['currency']);
        $this->assertSame(3.80, $payload['exchange_rate']);
        $this->assertSame('CART-PAY', $payload['cart_id']);
    }
}
