<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Booking\Actions;

use PHPUnit\Framework\TestCase;
use App\Core\Request;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\BookingHoldToken;
use App\Features\Booking\Actions\GetPaymentCheckAction;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;

/**
 * Todo 31 (W5): GET /api/payment-check?cart_id&token
 * Expone findPaymentByExternalReference (W1) al frontend con la MISMA
 * validacion de ownership de cart que booking-status (token HMAC derivado
 * del email del guest). Nunca expone existencia de pagos sin verificar.
 * Test double SOLO del port propio (PaymentGatewayPortInterface) — permitido
 * por el mandato r10; nunca se simulan resultados de MercadoPago.
 */
final class GetPaymentCheckActionTest extends TestCase {
    private ProvisionalBookingRepository $bookingRepo;
    private PaymentGatewayPortInterface $gateway;
    private GetPaymentCheckAction $action;

    /** @var list<string> External references consultadas en el gateway. */
    private array $searchedRefs = [];

    protected function setUp(): void {
        Config::set('APP_ENV', 'testing');
        Config::set('BOOKING_TOKEN_SECRET', 'test-booking-token-secret');

        $this->bookingRepo = $this->createMock(ProvisionalBookingRepository::class);
        $this->gateway = $this->createMock(PaymentGatewayPortInterface::class);

        $this->action = new GetPaymentCheckAction($this->bookingRepo, $this->gateway, 3, 0);
    }

    /** Gateway que registra las refs consultadas y devuelve null (default). */
    private function stubGatewaySearching(?array $result, ?int $foundOnAttempt = null): void {
        $calls = 0;
        $this->gateway->method('findPaymentByExternalReference')
            ->willReturnCallback(function (string $externalRef) use (&$calls, $result, $foundOnAttempt): ?array {
                $this->searchedRefs[] = $externalRef;
                $calls++;
                if ($foundOnAttempt !== null && $calls < $foundOnAttempt) return null;
                return $result;
            });
    }

    private function captureResponse(callable $fn): array {
        ob_start();
        try {
            $fn();
        } catch (HttpException $e) {
            // Mismo manejo que Router::dispatch en produccion.
            Response::error($e->getMessage(), $e->getStatusCode());
        }
        $body = (string) ob_get_clean();
        return ['code' => http_response_code(), 'body' => $body];
    }

    /** @return array<string,mixed> */
    private function holdFixture(?string $paymentId = null): array {
        return [
            'cart_id'     => 'CART-1',
            'status'      => 'pending',
            'guest_data'  => ['name' => 'Juan Perez', 'email' => 'juan@test.com', 'phone' => ''],
            'room_data'   => [],
            'price_snapshot' => 200.0,
            'expires_at'  => date('Y-m-d H:i:s', time() + 900),
            'payment_id'  => $paymentId,
        ];
    }

    private function request(string $cartId, string $token = ''): Request {
        // El constructor de Request lee los query params de $_GET (CLI).
        $_GET = ['cart_id' => $cartId, 'token' => $token];
        return new Request('GET', '/api/payment-check');
    }

    public function testMissingCartIdReturns400(): void {
        $response = $this->captureResponse(fn () => ($this->action)(new Request('GET', '/api/payment-check', [], [])));
        $this->assertSame(400, $response['code']);
    }

    public function testUnknownCartReturns404(): void {
        $this->bookingRepo->method('getByCartId')->willReturn(null);
        $response = $this->captureResponse(fn () => ($this->action)($this->request('CART-X')));
        $this->assertSame(404, $response['code']);
    }

    public function testInvalidTokenReturns401WithoutExposingAnything(): void {
        // Fix MINOR r5: NO exponer existencia de pagos por cartId sin verificacion.
        $this->bookingRepo->method('getByCartId')->willReturn($this->holdFixture('555'));
        $this->gateway->expects($this->never())->method('findPaymentByExternalReference');

        $response = $this->captureResponse(fn () => ($this->action)($this->request('CART-1', 'token-invalido')));

        $this->assertSame(401, $response['code']);
        $this->assertStringNotContainsString('555', $response['body']);
    }

    public function testLocalPaymentIdIsReturnedWithoutCallingGateway(): void {
        // El payment_id del hold (intento previo pending) es la fuente local:
        // no hace falta buscar en MP.
        $this->bookingRepo->method('getByCartId')->willReturn($this->holdFixture('555'));
        $this->gateway->expects($this->never())->method('findPaymentByExternalReference');

        $response = $this->captureResponse(
            fn () => ($this->action)($this->request('CART-1', BookingHoldToken::derive('CART-1', 'juan@test.com')))
        );

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('"success":true', $response['body']);
        $this->assertStringContainsString('"payment_id":"555"', $response['body']);
    }

    public function testGatewaySearchIsRetriedUntilFound(): void {
        // CONSISTENCIA EVENTUAL de GET /v1/payments/search (fix MAJOR r5):
        // el pago existe pero puede no aparecer aun -> reintentar 2-3 veces.
        $this->bookingRepo->method('getByCartId')->willReturn($this->holdFixture(null));
        $this->stubGatewaySearching(
            ['id' => 777, 'status' => 'approved', 'status_detail' => 'accredited', 'external_reference' => 'USGAR-CART-1'],
            foundOnAttempt: 2
        );

        $response = $this->captureResponse(
            fn () => ($this->action)($this->request('CART-1', BookingHoldToken::derive('CART-1', 'juan@test.com')))
        );

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('"payment_id":777', $response['body']);
        $this->assertCount(2, $this->searchedRefs, '2 intentos de busqueda hasta encontrar (consistencia eventual).');
        $this->assertSame(['USGAR-CART-1'], array_unique($this->searchedRefs), 'Busca SIEMPRE por la external_reference compartida USGAR-{cartId}.');
    }

    public function testEmptySearchReturnsNullPayment(): void {
        $this->bookingRepo->method('getByCartId')->willReturn($this->holdFixture(null));
        $this->stubGatewaySearching(null);

        $response = $this->captureResponse(
            fn () => ($this->action)($this->request('CART-1', BookingHoldToken::derive('CART-1', 'juan@test.com')))
        );

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('"payment_id":null', $response['body']);
        $this->assertCount(3, $this->searchedRefs, '3 intentos de busqueda antes de declarar vacio.');
    }

    public function testRejectedPaymentStatusIsReturnedForFrontendDecision(): void {
        // Si MP tiene un pago final-rejected para el cart, el frontend decide
        // reintentar (el pago murio) — pero NUNCA crea un segundo pago sin saberlo.
        $this->bookingRepo->method('getByCartId')->willReturn($this->holdFixture(null));
        $this->stubGatewaySearching(
            ['id' => 777, 'status' => 'rejected', 'status_detail' => 'cc_rejected_other_reason', 'external_reference' => 'USGAR-CART-1']
        );

        $response = $this->captureResponse(
            fn () => ($this->action)($this->request('CART-1', BookingHoldToken::derive('CART-1', 'juan@test.com')))
        );

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('"payment_id":777', $response['body']);
        $this->assertStringContainsString('"status":"rejected"', $response['body']);
    }
}
