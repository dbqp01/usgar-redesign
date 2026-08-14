<?php
declare(strict_types=1);

namespace App\Features\Booking\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\HttpException;
use App\Core\Config;
use App\Core\BookingStatus;
use App\Core\BookingHoldToken;
use App\Core\PriceCalculator;
use App\Features\Booking\Domain\ProvisionalBookingRepository;

use App\Features\Shared\RoomTypeRegistry;

/**
 * Accion ADR: GET /api/booking-status
 * Retorna el estado actual de la reserva protegiendo PII sensible.
 */
class GetBookingStatusAction {
    private ProvisionalBookingRepository $bookingRepo;

    public function __construct(ProvisionalBookingRepository $bookingRepo) {
        $this->bookingRepo = $bookingRepo;
    }

    public function __invoke(Request $request): void {
        $cartId = $request->getQuery('cart_id');
        $providedToken = $request->getQuery('token', '');

        if (!$cartId) {
            throw HttpException::badRequest('Falta el parámetro cart_id.');
        }

        $hold = $this->bookingRepo->getByCartId($cartId);

        if (!$hold) {
            throw HttpException::notFound('Reserva no encontrada.');
        }

        $guestEmail = $hold['guest_data']['email'] ?? '';
        $expectedToken = BookingHoldToken::derive($cartId, $guestEmail);
        $isAuthenticated = (!empty($providedToken) && hash_equals($expectedToken, $providedToken));

        $expiresAtStr = $hold['expires_at'] ?? null;
        $expiresTimestamp = $expiresAtStr ? strtotime($expiresAtStr) : 0;
        $now = time();
        $isExpired = ($hold['status'] === BookingStatus::Pending->value && $expiresTimestamp < $now);
        $timeLeftSeconds = max(0, $expiresTimestamp - $now);
        $idRoomType = (int)$hold['id_room_type'];
        $slug = RoomTypeRegistry::getSlugById($idRoomType);

        $exchangeRate = (float) Config::get('EXCHANGE_RATE_USD_PEN');
        $priceUSD = (float)$hold['price_snapshot'];
        $gatewayPricePEN = PriceCalculator::toGatewayPrice($priceUSD);

        // room_data: LISTA desde 2026-08-12 (multi-room). Los campos legacy
        // (room_name/price_per_night/nights) se resuelven del PRIMER room;
        // nights es identico para todas las habitaciones de la reserva.
        $roomData = $hold['room_data'] ?? [];
        if (isset($roomData['room_name'])) {
            $roomData = [$roomData]; // hold legacy pre-multi-room (objeto unico)
        }
        $firstRoom = $roomData[0] ?? [];
        $roomSummaries = array_map(static function (array $r): array {
            return [
                'room_name'       => $r['room_name'] ?? '',
                'price_per_night' => (float)($r['price_per_night'] ?? 0),
                'nights'          => (int)($r['nights'] ?? 1),
                'guests'          => (int)($r['guests'] ?? 0),
                'rate_type'       => $r['rate_type'] ?? null,
            ];
        }, $roomData);

        $payload = [
            'success'           => true,
            'cart_id'           => $hold['cart_id'],
            'status'            => $isExpired ? BookingStatus::Expired->value : $hold['status'],
            'checkin'           => $hold['checkin'],
            'checkout'          => $hold['checkout'],
            'id_room_type'      => $idRoomType,
            'slug'              => $slug,
            'room_name'         => $firstRoom['room_name'] ?? '',
            'price_per_night'   => (float)($firstRoom['price_per_night'] ?? 0),
            'nights'            => (int)($firstRoom['nights'] ?? 1),
            'room_summary'      => $roomSummaries,
            'currency'          => Config::get('HOTEL_BASE_CURRENCY', 'USD'),
            'price'             => $priceUSD,
            'exchange_rate'     => $exchangeRate,
            'gateway_currency'  => Config::get('MERCADO_PAGO_CURRENCY', 'PEN'),
            'gateway_price'     => $gatewayPricePEN,
            'expires_at'        => $expiresAtStr,
            'is_expired'        => $isExpired,
            'time_left_seconds' => $timeLeftSeconds,
        ];

        // PII SOLO con token valido (auditoria 2026-08-11): los cart_id del
        // webservice son INTs secuenciales; devolver nombre/email/telefono con
        // solo el status paid hacia la PII enumerable sin autenticacion.
        if ($isAuthenticated) {
            // payment_id: contrato del retry plan del frontend (todo 31;
            // paymentStatus.ts -> resolvePaymentOutcome/planRetryAfterFailure
            // leen localStatus.payment_id). Sin esta clave la rama local era
            // codigo muerto y todo retry caia al payment-check contra MP.
            $payload['payment_id']  = $hold['payment_id'] ?? null;
            $payload['guest_name']  = $hold['guest_data']['name'] ?? '';
            $payload['guest_email'] = $guestEmail;
            $payload['guest_phone'] = $hold['guest_data']['phone'] ?? '';
            // FIX 2026-08-14: pickup del aeropuerto persistido — el recibo de
            // exito inline lo muestra para que el huesped sepa que quedo
            // registrado (y el hotel lo coordine).
            $payload['special_requests'] = $hold['guest_data']['special_requests'] ?? '';
        }

        Response::json($payload);
    }
}
