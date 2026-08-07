<?php
declare(strict_types=1);

namespace App\Features\Booking\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\HttpException;
use App\Core\BookingStatus;
use App\Core\BookingHoldToken;
use App\Core\Config;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Ports\PmsPortInterface;

/**
 * Accion ADR: POST /api/extend-hold
 * Extiende el bloqueo temporal del carrito por 15 minutos adicionales.
 */
class ExtendHoldAction {
    private ProvisionalBookingRepository $bookingRepo;
    private PmsPortInterface $pms;

    public function __construct(
        ProvisionalBookingRepository $bookingRepo,
        PmsPortInterface $pms
    ) {
        $this->bookingRepo = $bookingRepo;
        $this->pms = $pms;
    }

    public function __invoke(Request $request): void {
        $cartId = $request->get('cart_id');
        $providedToken = $request->get('access_token') ?? $request->getHeader('x-access-token');

        if (!$cartId) {
            throw HttpException::badRequest('Falta el parámetro cart_id.');
        }

        $hold = $this->bookingRepo->getByCartId($cartId);

        if (!$hold) {
            throw HttpException::notFound('No se encontró ningún bloqueo para el cart_id especificado.');
        }

        $guestEmail = $hold['guest_data']['email'] ?? '';
        $expectedToken = BookingHoldToken::derive($cartId, $guestEmail);
        if (empty($providedToken) || !hash_equals($expectedToken, $providedToken)) {
            throw HttpException::unauthorized('Token de autorización inválido o ausente para extender el bloqueo.');
        }

        $status = BookingStatus::tryFrom($hold['status']);
        if ($status === null || !$status->isExtendable()) {
            throw HttpException::badRequest('El bloqueo ya no está en estado pendiente.');
        }

        $newExpiration = date('Y-m-d H:i:s', strtotime(Config::get('BOOKING_HOLD_TTL', '+15 minutes')));

        if ($this->bookingRepo->extend($cartId, $newExpiration)) {
            $this->pms->extendCartSession($cartId);

            $timeLeftSeconds = max(0, strtotime($newExpiration) - time());

            Response::json([
                'success'           => true,
                'cart_id'           => $cartId,
                'expires_at'        => $newExpiration,
                'time_left_seconds' => $timeLeftSeconds,
            ]);
        } else {
            Response::error('No se pudo extender el bloqueo en la base de datos.', 500);
        }
    }
}
