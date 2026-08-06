<?php
declare(strict_types=1);

namespace App\Features\Booking\Domain\Events;

use App\Core\Config;
use App\Core\Events\EventInterface;
use DateTimeImmutable;

/**
 * Evento de Dominio: Se ha confirmado un pago de reserva en la plataforma.
 *
 * Todo 25 (Wave 4): el evento (y su payload de outbox) lleva amount_pen
 * (int centavos), currency = 'PEN' y exchange_rate CONGELADO del hold
 * (exchange_rate_snapshot / price_snapshot_pen, escritos al cotizar por
 * CreateBookingAction). Los listeners usan amount_pen para el PMS: los
 * adapters reciben monto PEN, no USD.
 */
class BookingPaidEvent implements EventInterface {
    private string $cartId;
    private string $paymentId;
    private float $amount;
    private int $amountPen;
    private string $currency;
    private float $exchangeRate;
    private string $checkIn;
    private string $checkOut;
    private int $idRoomType;
    private array $guestData;
    private array $roomData;
    private DateTimeImmutable $occurredAt;

    public function __construct(
        string $cartId,
        string $paymentId,
        float $amount,
        string $checkIn = '',
        string $checkOut = '',
        int $idRoomType = 1,
        array $guestData = [],
        array $roomData = [],
        ?int $amountPen = null,
        string $currency = 'PEN',
        ?float $exchangeRate = null
    ) {
        $this->cartId = $cartId;
        $this->paymentId = $paymentId;
        $this->amount = $amount;
        $this->checkIn = $checkIn;
        $this->checkOut = $checkOut;
        $this->idRoomType = $idRoomType;
        $this->guestData = $guestData;
        $this->roomData = $roomData;
        $this->amountPen = $amountPen ?? (int)round($amount * 100);
        $this->currency = $currency;
        $this->exchangeRate = $exchangeRate ?? (float)Config::get('EXCHANGE_RATE_USD_PEN');
        $this->occurredAt = new DateTimeImmutable();
    }

    /**
     * Construye el evento a partir de un hold de reserva con los defaults del dominio.
     *
     * Todo 25: usa el PEN y la tasa CONGELADOS del hold
     * (price_snapshot_pen / exchange_rate_snapshot). Back-compat: holds
     * legacy sin las columnas derivan el PEN con la tasa actual.
     *
     * @param array<string, mixed> $hold
     */
    public static function fromHold(string $cartId, string $paymentId, array $hold): self {
        $priceSnapshot = (float)($hold['price_snapshot'] ?? 0.0);
        $pricePen = $hold['price_snapshot_pen'] ?? null;
        $exchangeRate = $hold['exchange_rate_snapshot'] ?? null;

        $amountPen = $pricePen !== null
            ? (int)round((float)$pricePen * 100)
            : (int)round(\App\Core\PriceCalculator::toGatewayPrice($priceSnapshot) * 100);

        return new self(
            $cartId,
            $paymentId,
            $priceSnapshot,
            (string)($hold['checkin'] ?? ''),
            (string)($hold['checkout'] ?? ''),
            (int)($hold['id_room_type'] ?? 1),
            $hold['guest_data'] ?? [],
            $hold['room_data'] ?? [],
            $amountPen,
            (string)Config::get('MERCADO_PAGO_CURRENCY', 'PEN'), // todo 34: moneda de Config (unica fuente)
            $exchangeRate !== null ? (float)$exchangeRate : null
        );
    }

    public function getName(): string {
        return 'booking.paid';
    }

    public function getCartId(): string {
        return $this->cartId;
    }

    public function getPaymentId(): string {
        return $this->paymentId;
    }

    public function getAmount(): float {
        return $this->amount;
    }

    /** Monto PEN en centavos enteros (todo 25) — lo que reciben los PMS. */
    public function getAmountPen(): int {
        return $this->amountPen;
    }

    public function getCurrency(): string {
        return $this->currency;
    }

    /** Tasa USD->PEN congelada al cotizar (del hold). */
    public function getExchangeRate(): float {
        return $this->exchangeRate;
    }

    public function getCheckIn(): string {
        return $this->checkIn;
    }

    public function getCheckOut(): string {
        return $this->checkOut;
    }

    public function getIdRoomType(): int {
        return $this->idRoomType;
    }

    public function getGuestData(): array {
        return $this->guestData;
    }

    public function getRoomData(): array {
        return $this->roomData;
    }

    public function getPayload(): array {
        return [
            'cart_id'       => $this->cartId,
            'payment_id'    => $this->paymentId,
            'amount'        => $this->amount,
            'amount_pen'    => $this->amountPen,
            'currency'      => $this->currency,
            'exchange_rate' => $this->exchangeRate,
            'checkin'       => $this->checkIn,
            'checkout'      => $this->checkOut,
            'id_room_type'  => $this->idRoomType,
            'guest_data'    => $this->guestData,
            'room_data'     => $this->roomData,
        ];
    }

    public function getOccurredAt(): DateTimeImmutable {
        return $this->occurredAt;
    }
}
