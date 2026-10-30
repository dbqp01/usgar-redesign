<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Booking\Actions;

use App\Core\BookingHoldToken;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Features\Booking\Actions\GetBookingStatusAction;
use App\Features\Booking\Actions\GetPaymentCheckAction;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use PHPUnit\Framework\TestCase;

/**
 * Transporte del token de reserva: la cabecera X-Booking-Token es el canal
 * nuevo (no queda en URL, historial, Referer ni access logs); el query
 * `token` sigue aceptandose para no romper enlaces de recibo ya emitidos.
 */
final class BookingTokenTransportTest extends TestCase {
    private const CART = 'CART-TOKEN-1';
    private const EMAIL = 'guest@test.com';

    protected function setUp(): void {
        Config::set('BOOKING_TOKEN_SECRET', 'test-booking-token-secret');
        Config::set('EXCHANGE_RATE_USD_PEN', '4.20');
        $_GET = [];
    }

    protected function tearDown(): void {
        $_GET = [];
    }

    /** @return array<string,mixed> */
    private function hold(): array {
        return [
            'cart_id' => self::CART,
            'status' => 'pending',
            'id_room_type' => 1,
            'guest_data' => ['name' => 'Juan Perez', 'email' => self::EMAIL, 'phone' => '999'],
            'room_data' => [],
            'price_snapshot' => 100.0,
            'checkin' => '2030-01-10',
            'checkout' => '2030-01-12',
            'expires_at' => date('Y-m-d H:i:s', time() + 900),
        ];
    }

    /** @return array{code:int, body:string} */
    private function capture(callable $fn): array {
        ob_start();
        try {
            $fn();
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->getStatusCode());
        }
        $body = (string) ob_get_clean();
        return ['code' => http_response_code(), 'body' => $body];
    }

    private function bookingStatusAction(?array $hold = null): GetBookingStatusAction {
        $repo = $this->createMock(ProvisionalBookingRepository::class);
        $repo->method('getByCartId')->willReturn($hold ?? $this->hold());
        return new GetBookingStatusAction($repo);
    }

    private function paymentCheckAction(): GetPaymentCheckAction {
        $repo = $this->createMock(ProvisionalBookingRepository::class);
        $repo->method('getByCartId')->willReturn(array_replace($this->hold(), ['payment_id' => '555']));
        $gateway = $this->createMock(PaymentGatewayPortInterface::class);
        $gateway->expects($this->never())->method('findPaymentByExternalReference');
        return new GetPaymentCheckAction($repo, $gateway, 1, 0);
    }

    public function testBookingStatusAcceptsHeaderTokenAndIgnoresInvalidQueryToken(): void {
        // Query invalido a proposito: si la action siguiera leyendo el query,
        // el PII no se devolveria y este test fallaria.
        $_GET = ['cart_id' => self::CART, 'token' => 'token-viejo-o-manipulado'];
        $request = new Request('GET', '/api/booking-status', [
            'x-booking-token' => BookingHoldToken::derive(self::CART, self::EMAIL),
        ], []);

        $response = $this->capture(fn () => ($this->bookingStatusAction())($request));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('"guest_email":"guest@test.com"', $response['body']);
    }

    public function testBookingStatusStillAcceptsLegacyQueryToken(): void {
        // Enlaces de recibo emitidos antes del cambio: mismo token en query.
        $_GET = [
            'cart_id' => self::CART,
            'token' => BookingHoldToken::derive(self::CART, self::EMAIL),
        ];

        $response = $this->capture(
            fn () => ($this->bookingStatusAction())(new Request('GET', '/api/booking-status', [], []))
        );

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('"guest_email":"guest@test.com"', $response['body']);
    }

    public function testPaymentCheckAcceptsHeaderTokenAndIgnoresInvalidQueryToken(): void {
        $_GET = ['cart_id' => self::CART, 'token' => 'token-viejo-o-manipulado'];
        $request = new Request('GET', '/api/payment-check', [
            'x-booking-token' => BookingHoldToken::derive(self::CART, self::EMAIL),
        ], []);

        $response = $this->capture(fn () => ($this->paymentCheckAction())($request));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('"payment_id":"555"', $response['body']);
    }

    public function testPaymentCheckStillAcceptsLegacyQueryToken(): void {
        $_GET = [
            'cart_id' => self::CART,
            'token' => BookingHoldToken::derive(self::CART, self::EMAIL),
        ];

        $response = $this->capture(
            fn () => ($this->paymentCheckAction())(new Request('GET', '/api/payment-check', [], []))
        );

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('"payment_id":"555"', $response['body']);
    }

    public function testDerivedTokenStaysStableForAlreadyIssuedLinks(): void {
        // El algoritmo es contrato con reservas y enlaces ya emitidos:
        // cambiarlo invalidaria todos los recibos existentes.
        $expected = hash_hmac('sha256', self::CART . ':' . self::EMAIL, 'test-booking-token-secret');

        $this->assertSame($expected, BookingHoldToken::derive(self::CART, self::EMAIL));
    }

    public function testStatusWithoutTokenStillHidesPii(): void {
        $_GET = ['cart_id' => self::CART];

        $response = $this->capture(
            fn () => ($this->bookingStatusAction())(new Request('GET', '/api/booking-status', [], []))
        );

        $this->assertSame(200, $response['code']);
        $this->assertStringNotContainsString('"guest_email"', $response['body']);
        $this->assertStringNotContainsString('"guest_name"', $response['body']);
    }
}
