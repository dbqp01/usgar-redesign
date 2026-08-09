<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Booking\Actions;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
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
    private MockObject&PDO $pdo;
    private MockObject&PaymentGatewayPortInterface $paymentGateway;
    private MockObject&ProvisionalBookingRepository $bookingRepo;
    private MockObject&EventDispatcher $eventDispatcher;
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
        // Hallazgo real 2026-08-06: sin 'description' el email del comprador
        // muestra "Producto sin nombre" — debe ir el nombre de la habitacion.
        $this->assertSame('2 noche(s) - Suite Deluxe', $data['description']);
        // Fix F3 (2026-08-06): el create /v1/payments NO acepta currency_id
        // (400 bad_request, verificado con MCP + sandbox real); MP infiere la
        // moneda de la cuenta. La moneda se propaga via evento/PMS (todo 34).
        $this->assertArrayNotHasKey('currency_id', $data);

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

        // Todo 3 + fix F3 (2026-08-06, verificado con MCP search_documentation
        // "create payment payer" es/MPE + sandbox real): el schema del create
        // /v1/payments usa first_name/last_name — name/surname devuelve 400
        // bad_request ([payer.surname, payer.name]).
        $this->assertSame('Juan', $data['payer']['first_name']);
        $this->assertSame('Perez', $data['payer']['last_name']);
        $this->assertSame(['area_code' => '51', 'number' => '841234567'], $data['payer']['phone']);
        // Los datos del request del cliente se conservan.
        $this->assertSame('juan@test.com', $data['payer']['email']);
        $this->assertSame(['type' => 'DNI', 'number' => '12345678'], $data['payer']['identification']);
    }

    public function testPaymentPayloadUsesFrozenPenWhenRateChangesAfterQuote(): void {
        // Todo 32 (W6): el cargo usa price_snapshot_pen congelado al cotizar,
        // NO la tasa actual (un cambio de .env entre creacion y pago no debe
        // desviar el cargo: ni falso fraud_review ni sobrecobro).
        Config::set('EXCHANGE_RATE_USD_PEN', '3.90'); // cambio post-cotizacion (3.75 al cotizar)
        $hold = $this->holdFixture();
        $hold['price_snapshot']     = 75.0;   // USD
        $hold['price_snapshot_pen'] = 281.25; // congelado @3.75
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($hold);
        $this->stubApprovedGateway();

        $this->captureResponse(fn () => ($this->action)($this->defaultRequest()));

        $data = $this->capturedPaymentData;
        $this->assertNotNull($data);
        $this->assertSame(281.25, $data['transaction_amount'], 'El cargo usa el PEN congelado (281.25), no 75x3.90=292.50.');
        $this->assertSame(140.63, $data['additional_info']['items'][0]['unit_price']); // 281.25 / 2 noches
    }

    public function testGuestWithoutNameFallsBackToHuespedUsgar(): void {        // QA- del todo 3: guest sin name -> "Huesped USGAR" sin romper el payload.
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn(
            $this->holdFixture(guestName: '', phone: '')
        );
        $this->stubApprovedGateway();

        $this->captureResponse(fn () => ($this->action)($this->defaultRequest()));

        $data = $this->capturedPaymentData;
        $this->assertNotNull($data);
        $this->assertSame('Huesped USGAR', $data['payer']['first_name']);
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

    // =====================================================================
    // Todo 8 (W2): cobro DENTRO de la transaccion del lock pesimista.
    // La gateway solo se llama si el hold esta pending, NO expirado y
    // payment_id IS NULL; el attach ocurre dentro de la txn en AMBAS ramas
    // (approved y pending/in_process); commit-falla -> attach best-effort.
    // =====================================================================

    public function testHoldWithExistingPaymentIdSkipsGatewayAndReturnsPending(): void {
        // QA+2 (fix r3): un intento previo pending ya dejo payment_id en el
        // hold -> el gate 'payment_id IS NULL' bloquea un segundo cobro.
        $hold = $this->holdFixture();
        $hold['payment_id'] = '555';
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($hold);
        $this->paymentGateway->expects($this->never())->method('processPayment');

        $response = $this->captureResponse(fn () => ($this->action)($this->defaultRequest()));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('"success":false', $response['body']);
        $this->assertStringContainsString('"status":"pending"', $response['body']);
    }

    public function testExpiredHoldIsNotCharged(): void {
        $hold = $this->holdFixture();
        $hold['expires_at'] = date('Y-m-d H:i:s', time() - 60);
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($hold);
        $this->paymentGateway->expects($this->never())->method('processPayment');

        $response = $this->captureResponse(fn () => ($this->action)($this->defaultRequest()));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('"success":false', $response['body']);
        $this->assertStringContainsString('"status":"expired"', $response['body']);
    }

    public function testPendingGatewayResultAttachesPaymentIdInsideTransaction(): void {
        // QA+2 (carrera CONT): create OK in_process -> attach DENTRO de la
        // txn + commit + success:false(status in_process); el payment_id
        // queda persistido para reconciliacion (todo 27).
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->holdFixture());
        $this->paymentGateway->method('processPayment')->willReturn([
            'id' => 987, 'status' => 'in_process', 'status_detail' => 'pending_contingency',
        ]);
        $this->bookingRepo->expects($this->once())->method('attachPaymentId')->with('CART-1', '987');

        $response = $this->captureResponse(fn () => ($this->action)($this->defaultRequest()));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('"success":false', $response['body']);
        $this->assertStringContainsString('"status":"in_process"', $response['body']);
    }

    public function testApprovedAttachesPaymentIdAndStatusPaidInsideTransaction(): void {
        // fix MINOR r4: el attach aplica TAMBIEN a la rama approved (el
        // polling del todo 27 y los refunds del todo 12 dependen de que
        // payment_id este persistido).
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->holdFixture());
        $this->stubApprovedGateway();
        $this->bookingRepo->expects($this->once())->method('attachPaymentId')->with('CART-1', '987');
        $this->bookingRepo->expects($this->once())->method('updateStatus')->with('CART-1', 'paid');
        $this->bookingRepo->expects($this->once())->method('markPaymentProcessed')->with('987', 'CART-1', 'approved');

        $response = $this->captureResponse(fn () => ($this->action)($this->defaultRequest()));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('"success":true', $response['body']);
        $this->assertStringContainsString('"payment_id":"987"', $response['body']);
    }

    public function testRejectedReturnedStatusRollsBackWithoutPersisting(): void {
        // QA-1: la gateway devuelve un pago rechazado (sin excepcion) ->
        // rollback, NADA persistido (ni payment_id ni status).
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->holdFixture());
        $this->paymentGateway->method('processPayment')->willReturn([
            'id' => 987, 'status' => 'rejected', 'status_detail' => 'cc_rejected_other_reason',
        ]);
        $this->bookingRepo->expects($this->never())->method('attachPaymentId');
        $this->bookingRepo->expects($this->never())->method('updateStatus');
        $this->pdo->expects($this->atLeastOnce())->method('rollBack');

        $response = $this->captureResponse(fn () => ($this->action)($this->defaultRequest()));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('"success":false', $response['body']);
        $this->assertStringContainsString('"status":"rejected"', $response['body']);
    }

    public function testCommitFailureAfterGatewayAttachesBestEffortAndReturnsPending(): void {
        // QA-2 (ramas commit-falla): la gateway tuvo exito pero commit() lanza
        // -> attach best-effort ANTES de responder + success:false(status
        // pending) para que polling/webhook reconcilien; el hold NO permite
        // un segundo cobro.
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->holdFixture());
        $this->stubApprovedGateway();
        $this->pdo->method('commit')->willThrowException(new \PDOException('lock wait timeout exceeded'));
        $attached = [];
        $this->bookingRepo->method('attachPaymentId')->willReturnCallback(
            function (string $cartId, string $paymentId) use (&$attached): bool {
                $attached[] = [$cartId, $paymentId];
                return true;
            }
        );

        $response = $this->captureResponse(fn () => ($this->action)($this->defaultRequest()));

        $this->assertNotEmpty($attached, 'El attach best-effort debe ejecutarse.');
        $this->assertSame(['CART-1', '987'], $attached[array_key_last($attached)]);
        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('"success":false', $response['body']);
        $this->assertStringContainsString('"status":"pending"', $response['body']);
    }

    public function testCommitFailureWithFailedBestEffortAttachReturns500(): void {
        // QA-2 variante: si ni el attach best-effort funciona -> 500 (el todo
        // 31 consulta MP por external_reference antes de reintentar).
        $this->bookingRepo->method('getByCartIdForUpdate')->willReturn($this->holdFixture());
        $this->stubApprovedGateway();
        $this->pdo->method('commit')->willThrowException(new \PDOException('connection lost'));
        $this->bookingRepo->method('attachPaymentId')->willReturn(false);

        $response = $this->captureResponse(fn () => ($this->action)($this->defaultRequest()));

        $this->assertSame(500, $response['code']);
        $this->assertStringContainsString('Error interno', $response['body']);
    }
}
