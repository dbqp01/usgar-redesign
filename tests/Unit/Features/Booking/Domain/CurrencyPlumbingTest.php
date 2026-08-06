<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Booking\Domain;

use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Features\Booking\Domain\Events\BookingPaidEvent;
use App\Features\Shared\Adapters\MercadoPagoAdapter;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Common\RequestOptions;
use MercadoPago\Resources\Payment;

/**
 * Tests del plumbing de moneda (Wave 6, todo 34): UNA UNICA fuente de
 * verdad para la moneda de cobro — Config::get('MERCADO_PAGO_CURRENCY') —
 * usada en el create (currency_id, todo 4), en el evento (currency, todo
 * 25) y propagada en el payload del outbox. Sin literales en el flujo de
 * pago (los listeners reciben amount_pen/currency del evento).
 */
final class CurrencyPlumbingTest extends TestCase {
    protected function setUp(): void {
        Config::set('APP_ENV', 'testing');
        Config::set('EXCHANGE_RATE_USD_PEN', '3.80');
        Config::set('MERCADO_PAGO_ACCESS_TOKEN', 'TEST-123456789');
        Config::set('MERCADO_PAGO_CURRENCY', 'PEN');
    }

    protected function tearDown(): void {
        Config::set('MERCADO_PAGO_CURRENCY', 'PEN');
        Config::set('MERCADO_PAGO_ACCESS_TOKEN', '');
    }

    private function hold(string $cartId): array {
        return [
            'cart_id'                 => $cartId,
            'price_snapshot'          => 75.0,
            'price_snapshot_pen'      => 285.0,
            'exchange_rate_snapshot'  => 3.80,
            'checkin'                 => '2026-09-01',
            'checkout'                => '2026-09-03',
            'id_room_type'            => 2,
            'guest_data'              => [],
            'room_data'               => [],
        ];
    }

    public function testEventCurrencyComesFromConfigSingleSource(): void {
        // RED: fromHold hardcodea 'PEN'; debe salir de
        // Config::get('MERCADO_PAGO_CURRENCY').
        Config::set('MERCADO_PAGO_CURRENCY', 'USD');

        $event = BookingPaidEvent::fromHold('CART-USD', '555', $this->hold('CART-USD'));

        $this->assertSame('USD', $event->getCurrency(), 'La moneda del evento sale de Config, no de un literal.');
        $this->assertSame('USD', $event->getPayload()['currency'], 'El payload del outbox propaga la misma moneda.');
        $this->assertSame(28500, $event->getAmountPen(), 'amount_pen se conserva (centavos del PEN congelado).');
    }

    public function testEventCurrencyPenWhenEnvIsPen(): void {
        // QA+: env PEN -> pipeline PEN completo (el caso de produccion).
        Config::set('MERCADO_PAGO_CURRENCY', 'PEN');

        $event = BookingPaidEvent::fromHold('CART-PEN', '555', $this->hold('CART-PEN'));

        $this->assertSame('PEN', $event->getCurrency());
        $this->assertSame('PEN', $event->getPayload()['currency']);
    }

    public function testAdapterCreateCurrencyIdFollowsConfig(): void {
        // Complementa el test de caracterizacion (currency_id='PEN' con env
        // PEN): con env USD el create debe enviar 'USD' — prueba que el
        // payload NO tiene la moneda hardcodeada (single source).
        Config::set('MERCADO_PAGO_CURRENCY', 'USD');

        $paymentClient = $this->createMock(PaymentClient::class);
        $paymentClient->expects($this->once())
            ->method('create')
            ->with(
                $this->callback(function (array $payload): bool {
                    return $payload['currency_id'] === 'USD';
                }),
                $this->isInstanceOf(RequestOptions::class)
            )
            ->willReturn($this->makePayment(999, 'approved', 'accredited', 'CART-USD', 285.0));

        $adapter = new MercadoPagoAdapter($paymentClient);
        $result = $adapter->processPayment([
            'external_reference' => 'CART-USD',
            'transaction_amount' => 285.0,
            'payment_method_id' => 'card',
            'payer' => ['email' => 'x@test.com'],
        ]);

        $this->assertSame(999, $result['id']);
    }

    private function makePayment(int $id, string $status, string $statusDetail, string $externalRef, float $amount): Payment {
        $payment = new Payment();
        $payment->id = $id;
        $payment->status = $status;
        $payment->status_detail = $statusDetail;
        $payment->external_reference = $externalRef;
        $payment->transaction_amount = $amount;
        return $payment;
    }
}
