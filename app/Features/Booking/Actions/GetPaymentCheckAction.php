<?php
declare(strict_types=1);

namespace App\Features\Booking\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\HttpException;
use App\Core\BookingHoldToken;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;

/**
 * Accion ADR: GET /api/payment-check?cart_id=...&token=...
 * Consulta si existe un pago en MercadoPago para el cart (todo 31, W5).
 * Misma validacion de ownership de cart que GetBookingStatusAction (token
 * HMAC derivado de cart_id + email del guest): NUNCA expone la existencia de
 * pagos por cartId sin verificacion.
 *
 * Orden de fuentes:
 *   1) payment_id local del hold (intento previo pending/commit-falla).
 *   2) MP por external_reference USGAR-{cartId} (findPaymentByExternalReference,
 *      W1) — con REINTENTOS por consistencia eventual de GET /v1/payments/search
 *      (2-3 intentos con backoff 1-2s antes de declarar vacio; fix MAJOR r5).
 */
class GetPaymentCheckAction {
    private ProvisionalBookingRepository $bookingRepo;
    private PaymentGatewayPortInterface $paymentGateway;
    private int $searchAttempts;
    private int $searchBackoffMs;

    public function __construct(
        ProvisionalBookingRepository $bookingRepo,
        PaymentGatewayPortInterface $paymentGateway,
        int $searchAttempts = 3,
        int $searchBackoffMs = 1000
    ) {
        $this->bookingRepo = $bookingRepo;
        $this->paymentGateway = $paymentGateway;
        $this->searchAttempts = max(1, $searchAttempts);
        $this->searchBackoffMs = max(0, $searchBackoffMs);
    }

    public function __invoke(Request $request): void {
        $cartId = (string)($request->getQuery('cart_id') ?? '');
        $providedToken = (string)($request->getQuery('token') ?? '');

        if ($cartId === '') {
            throw HttpException::badRequest('Falta el parametro cart_id.');
        }

        $hold = $this->bookingRepo->getByCartId($cartId);
        if (!$hold) {
            throw HttpException::notFound('Reserva no encontrada.');
        }

        // Ownership: token HMAC derivado del email del guest (mismo patron
        // que GetBookingStatusAction). Sin token valido -> 401, sin datos.
        $guestEmail = $hold['guest_data']['email'] ?? '';
        $expectedToken = BookingHoldToken::derive($cartId, $guestEmail);
        if (empty($providedToken) || !hash_equals($expectedToken, $providedToken)) {
            throw HttpException::unauthorized('Token de autorizacion invalido o ausente.');
        }

        // 1) Fuente local: el hold ya tiene payment_id (intento previo
        //    pending/in_process o commit-falla con attach OK).
        $localPaymentId = trim((string)($hold['payment_id'] ?? ''));
        if ($localPaymentId !== '') {
            Response::json([
                'success'    => true,
                'cart_id'    => $cartId,
                'payment_id' => $localPaymentId,
                'status'     => $hold['status'] ?? 'pending',
                'source'     => 'local',
            ]);
            return;
        }

        // 2) Fuente MP: buscar por external_reference compartida USGAR-{cartId}
        //    (todo 3). Reintentos con backoff 1-2s: el search es eventualmente
        //    consistente y el pago puede existir sin aparecer aun.
        $externalRef = 'USGAR-' . $cartId;
        $payment = null;
        for ($attempt = 1; $attempt <= $this->searchAttempts; $attempt++) {
            $payment = $this->paymentGateway->findPaymentByExternalReference($externalRef);
            if ($payment !== null) {
                break;
            }
            if ($attempt < $this->searchAttempts && $this->searchBackoffMs > 0) {
                usleep($this->searchBackoffMs * 1000);
            }
        }

        Response::json([
            'success'    => true,
            'cart_id'    => $cartId,
            'payment_id' => $payment['id'] ?? null,
            'status'     => $payment['status'] ?? '',
            'status_detail' => $payment['status_detail'] ?? '',
            'source'     => $payment !== null ? 'mercadopago' : 'none',
        ]);
    }
}
