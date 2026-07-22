<?php
declare(strict_types=1);

namespace App\Features\Booking\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\HttpException;
use App\Core\BookingStatus;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Ports\PmsPortInterface;
use App\Features\Shared\Adapters\QloAppAdapter;

/**
 * Acción ADR: POST /api/extend-hold
 * Extiende el bloqueo temporal del carrito por 15 minutos adicionales.
 */
class ExtendHoldAction {
    private ProvisionalBookingRepository $bookingRepo;
    private PmsPortInterface $pms;

    public function __construct(
        ?ProvisionalBookingRepository $bookingRepo = null,
        ?PmsPortInterface $pms = null
    ) {
        $this->bookingRepo = $bookingRepo ?? new ProvisionalBookingRepository();
        $this->pms = $pms ?? new QloAppAdapter();
    }

    public function __invoke(Request $request): void {
        $cartId = $request->get('cart_id');

        if (!$cartId) {
            throw HttpException::badRequest('Falta el parámetro cart_id.');
        }

        $hold = $this->bookingRepo->getByCartId($cartId);

        if (!$hold) {
            throw HttpException::notFound('No se encontró ningún bloqueo para el cart_id especificado.');
        }

        $status = BookingStatus::tryFrom($hold['status']);
        if ($status === null || !$status->isExtendable()) {
            throw HttpException::badRequest('El bloqueo ya no está en estado pendiente.');
        }

        $newExpiration = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        if ($this->bookingRepo->extend($cartId, $newExpiration)) {
            $this->pms->extendCartSession($cartId);

            Response::json([
                'success'    => true,
                'expires_at' => $newExpiration,
            ]);
        } else {
            Response::error('No se pudo extender el bloqueo en la base de datos.', 500);
        }
    }
}
