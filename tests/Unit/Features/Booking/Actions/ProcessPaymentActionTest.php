<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Booking\Actions;

use PHPUnit\Framework\TestCase;
use App\Core\Request;
use App\Core\Config;
use App\Core\BookingHoldToken;
use App\Core\Events\EventDispatcher;
use App\Features\Booking\Actions\ProcessPaymentAction;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\Net\MPResponse;
use PDO;

/**
 * Todos 3/4 (W1): payload density (additional_info travel + payer completo
 * desde guest_data del hold) + currency_id explicito.
 * Test double SOLO del port propio (PaymentGatewayPortInterface) — permitido
 * por el mandato r10; nunca se simulan resultados de MercadoPago.
 */
final class ProcessPaymentActionTest extends TestCase {
    private PDO $pdo;
    private PaymentGatewayPortInterface $paymentGateway;
    private ProvisionalBookingRepository $bookingRepo;
    private EventDispatcher $eventDispatcher;
    private ProcessPaymentAction $action;

    /** @var array<string,mixed>|null Ultimo paymentData capturado por el gateway. */
    private ?array $capturedPaymentData = null;

    protected function setUp(): void {
        Config::set('APP_ENV', 'testing');
        Config::set('EXCHANGE_RATE_USD_PEN', '3.80');
        // NOTE: MERCADO_PAGO_CURRENCY NO se setea -> QA- del todo 4: default 'PEN'.
        Config::set('BOOKING_TOKEN_SECRET', 'test-booking-token-secret');

        $this->pdo = $this->createMock(PDO::class);
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->method('commit')->willReturn(true);
        $this->pdo->method('rollBack')->willReturn(true);
        $this->pdo->method('inTransaction')->willReturn(false);

        $this->paymentGateway = $this->createMock(PaymentGatewayPortInterface::class);
        $this->bookingRepo = $this->createMock(ProvisionalBookingRepository::class);
        $this->eventDispatcher = $this->createMock(EventDispatcher::class);

        $this->action = new ProcessPaymentAction(
            $this->pdo,
            $this->paymentGateway,
            $this->bookingRepo,
            $this->eventDispatcher
        );
    }

    private function captureResponse(callable $fn): array {
        ob_start();
        $fn();
        $body = (string) ob_get_clean();
        return ['code' => http_response_code(), 'body' => $body];
    }

    /** @return array<string,mixed> */
    private function holdFixture(string $guestName = 'Juan Perez', string $phone = '+51 84 1234567'): array {
        return [
            'cart_id'       => 'CART-1',
            'id_room_type'  => '3',
            'status'        => 'pending',
            'guest_data'    => [
                'name'   => $guestName,
                'email'  => 'juan@test.com',
                'phone'  => $phone,
                'guests' => 2,
            ],
            'room_data'     => [
                'room_name'       => 'Suite Deluxe',
                'price_per_night' => 100.0,
                'nights'          => 2,
            ],
            'price_snapshot' => 200.0,
            'expires_at'     => date('Y-m-d H:i:s', time() + 900),
            'payment_id'     => null,
        ];
    }

    private function stubApprovedGateway(): void {
        $this->capturedPaymentData = null;
        $this->paymentGateway->method('processPayment')
            ->willReturnCallback(function (array $paymentData): array {
                $this->capturedPaymentData = $paymentData;
                return ['id' => 987, 'status' => 'approved', 'status_detail' => 'accredited'];
            });
    }

    private function defaultRequest(): Request {
        return new Request('POST', '/api/process-payment', [], [
            'cart_id'      => 'CART-1',
            'access_token' => BookingHoldToken::derive('CART-1', 'juan@test.com'),
            'payment_data' => [
                'token'              => 'CARD_TOKEN_XYZ',
                'issuer_id'          => '310',
                'payment_method_id'  => 'visa',
                'installments'       => 1,
                'payer'              => [
                    'email'          => 'juan@test.com',
                    'identification' => ['type' => 'DNI', 'number' => '12345678'],
                ],
            ],
        ]);
    }

    public function testPaymentPayloadIncludesAdditionalInfoPayerAndCurrency(): void {
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->holdFixture());
        $this->stubApprovedGateway();

        $this->captureResponse(fn () => ($this->action)($this->defaultRequest()));

        $data = $this->capturedPaymentData;
        $this->assertNotNull($data, 'La pasarela debe ser llamada.');

        // Todo 3: external_reference compartida con todo 22 (dedup por USGAR-{cartId}).
        $this->assertSame('USGAR-CART-1', $data['external_reference']);
        // Todo 4: currency_id explicito (default PEN, env sin la var).
        $this->assertSame('PEN', $data['currency_id']);

        // Todo 3: additional_info.items[] con categoria travel.
        $items = $data['additional_info']['items'] ?? null;
        $this->assertIsArray($items);
        $this->assertCount(1, $items);
        $item = $items[0];
        $this->assertSame('3', $item['id']);
        $this->assertSame('Suite Deluxe', $item['title']);
        $this->assertSame(2, $item['quantity']);
        $this->assertSame(380.0, $item['unit_price']); // 200 USD x 3.80 / 2 noches
        $this->assertSame('travel', $item['category_id']);

        // Todo 3: payer.name/surname (split de guest_data del hold) + phone.
        $this->assertSame('Juan', $data['payer']['name']);
        $this->assertSame('Perez', $data['payer']['surname']);
        $this->assertSame(['area_code' => 84, 'number' => 1234567], $data['payer']['phone']);
        // Los datos del request del cliente se conservan.
        $this->assertSame('juan@test.com', $data['payer']['email']);
        $this->assertSame(['type' => 'DNI', 'number' => '12345678'], $data['payer']['identification']);
    }

    public function testGuestWithoutNameFallsBackToHuespedUsgar(): void {
        // QA- del todo 3: guest sin name -> "Huesped USGAR" sin romper el payload.
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn(
            $this->holdFixture(guestName: '', phone: '')
        );
        $this->stubApprovedGateway();

        $this->captureResponse(fn () => ($this->action)($this->defaultRequest()));

        $data = $this->capturedPaymentData;
        $this->assertNotNull($data);
        $this->assertSame('Huésped USGAR', $data['payer']['name']);
        $this->assertArrayNotHasKey('phone', $data['payer'], 'Sin telefono persistido no se envia payer.phone.');
        $this->assertArrayHasKey('additional_info', $data, 'El payload debe seguir construyendose.');
        $this->assertSame('Suite Deluxe', $data['additional_info']['items'][0]['title']);
    }

    public function testMpApiExceptionReturnsMpStatusWithStatusDetail(): void {
        // Todo 6 (QA+): MPApiException -> passthrough del status MP (400/422)
        // con details.status_detail; nunca 500 generico.
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->holdFixture());
        $this->paymentGateway->method('processPayment')
            ->willThrowException(new MPApiException(
                'Api error. Check response for details',
                new MPResponse(422, ['status_detail' => 'cc_rejected_bad_filled_security_code'])
            ));

        $response = $this->captureResponse(fn () => ($this->action)($this->defaultRequest()));

        $this->assertSame(422, $response['code']);
        $this->assertStringContainsString('cc_rejected_bad_filled_security_code', $response['body']);
        $this->assertStringNotContainsString('Error interno', $response['body']);
    }

    public function testNetworkErrorReturns500(): void {
        // Todo 6 (QA-): error NO-MP (red/transporte) -> 500 generico.
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->holdFixture());
        $this->paymentGateway->method('processPayment')
            ->willThrowException(new \Exception('Connection timed out'));

        $response = $this->captureResponse(fn () => ($this->action)($this->defaultRequest()));

        $this->assertSame(500, $response['code']);
        $this->assertStringContainsString('Error interno', $response['body']);
    }
}
