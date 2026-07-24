<?php
declare(strict_types=1);

namespace App\Features\Booking\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Config;
use App\Features\Booking\Domain\ProvisionalBookingRepository;

/**
 * Accion ADR: GET /api/booking-status
 * Retorna el estado actual de la reserva protegiendo PII sensible.
 */
class GetBookingStatusAction {
    private ProvisionalBookingRepository $bookingRepo;

    public function __construct(?ProvisionalBookingRepository $bookingRepo = null) {
        $this->bookingRepo = $bookingRepo ?? new ProvisionalBookingRepository();
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
        $secretKey = Config::get('BOOKING_TOKEN_SECRET', Config::get('CRON_SECRET'));
        if (empty($secretKey)) {
            Logger::error("GetBookingStatusAction: BOOKING_TOKEN_SECRET no está configurado en servidor.");
            throw HttpException::internal("Configuración de seguridad de token no disponible.");
        }

        $expectedToken = hash_hmac('sha256', $cartId . ':' . $guestEmail, $secretKey);
        $isAuthenticated = (!empty($providedToken) && hash_equals($expectedToken, $providedToken));

        $payload = [
            'success'         => true,
            'cart_id'         => $hold['cart_id'],
            'status'          => $hold['status'],
            'checkin'         => $hold['checkin'],
            'checkout'        => $hold['checkout'],
            'id_room_type'    => (int)$hold['id_room_type'],
            'room_name'       => $hold['room_data']['room_name'] ?? '',
            'price_per_night' => (float)($hold['room_data']['price_per_night'] ?? 0),
            'nights'          => (int)($hold['room_data']['nights'] ?? 1),
            'price'           => (float)$hold['price_snapshot'],
            'expires_at'      => $hold['expires_at'] ?? null,
        ];

        if ($isAuthenticated) {
            $payload['guest_name']  = $hold['guest_data']['name'] ?? '';
            $payload['guest_email'] = $guestEmail;
            $payload['guest_phone'] = $hold['guest_data']['phone'] ?? '';
        }

        Response::json($payload);
    }
}
