<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Booking\Actions;

use App\Core\BookingHoldToken;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Features\Booking\Actions\GetBookingStatusAction;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use PHPUnit\Framework\TestCase;

final class GetBookingStatusActionTest extends TestCase {
    private ProvisionalBookingRepository $bookingRepo;
    private GetBookingStatusAction $action;

    protected function setUp(): void {
        Config::set('BOOKING_TOKEN_SECRET', 'test-booking-token-secret');
        Config::set('EXCHANGE_RATE_USD_PEN', '4.20');
        $this->bookingRepo = $this->createMock(ProvisionalBookingRepository::class);
        $this->action = new GetBookingStatusAction($this->bookingRepo);
    }

    /** @param array<string,mixed> $hold */
    private function requestWithHold(array $hold): string {
        $this->bookingRepo->method('getByCartId')->willReturn($hold);
        $_GET = [
            'cart_id' => 'CART-1',
            'token' => BookingHoldToken::derive('CART-1', 'guest@test.com'),
        ];
        ob_start();
        ($this->action)(new Request('GET', '/api/booking-status'));
        return (string)ob_get_clean();
    }

    /** @return array<string,mixed> */
    private function hold(array $overrides = []): array {
        return array_replace([
            'cart_id' => 'CART-1',
            'status' => 'pending',
            'id_room_type' => 1,
            'guest_data' => ['email' => 'guest@test.com'],
            'room_data' => [],
            'price_snapshot' => 100.0,
            'checkin' => '2030-01-10',
            'checkout' => '2030-01-12',
            'expires_at' => date('Y-m-d H:i:s', time() + 900),
        ], $overrides);
    }

    public function testUsesFrozenPenAndExchangeRateSnapshots(): void {
        $payload = json_decode($this->requestWithHold($this->hold([
            'price_snapshot_pen' => 350.0,
            'exchange_rate_snapshot' => 3.50,
        ])), true, 512, JSON_THROW_ON_ERROR);

        $this->assertEquals(3.5, $payload['exchange_rate']);
        $this->assertEquals(350.0, $payload['gateway_price']);
    }

    public function testLegacyHoldFallsBackToConfiguredExchangeRate(): void {
        $payload = json_decode($this->requestWithHold($this->hold()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertEquals(4.2, $payload['exchange_rate']);
        $this->assertEquals(420.0, $payload['gateway_price']);
    }
}
