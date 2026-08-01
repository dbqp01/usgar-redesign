<?php
declare(strict_types=1);

namespace App\Features\Booking\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Config;
use App\Core\BookingStatus;
use App\Core\HttpException;
use App\Core\Events\EventDispatcher;
use App\Features\Booking\Domain\Events\BookingPaidEvent;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use PDO;
use Exception;

/**
 * Accion ADR: POST /api/process-payment
 * Procesa el pago sincronamente via Checkout API.
 */
class ProcessPaymentAction {
    private PDO $pdo;
    private PaymentGatewayPortInterface $paymentGateway;
    private ProvisionalBookingRepository $bookingRepo;
    private EventDispatcher $eventDispatcher;

    public function __construct(
        PDO $pdo,
        PaymentGatewayPortInterface $paymentGateway,
        ProvisionalBookingRepository $bookingRepo,
        EventDispatcher $eventDispatcher
    ) {
        $this->pdo = $pdo;
        $this->paymentGateway = $paymentGateway;
        $this->bookingRepo = $bookingRepo;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function __invoke(Request $request): void {
        $body = $request->getBody() ?? [];
        
        $cartId = $body['cart_id'] ?? '';
        $accessToken = $body['access_token'] ?? '';
        $paymentData = $body['payment_data'] ?? [];

        if (empty($cartId) || empty($accessToken) || empty($paymentData)) {
            throw HttpException::badRequest('Faltan parametros obligatorios para procesar el pago.');
        }

        try {
            $this->pdo->beginTransaction();

            $hold = $this->bookingRepo->getByCartIdForUpdate($cartId);
            if (!$hold) {
                $this->pdo->rollBack();
                throw HttpException::notFound("No se encontro una reserva para el carrito especificado.");
            }

            // Verify access token
            $secretKey = Config::get('BOOKING_TOKEN_SECRET', Config::get('CRON_SECRET'));
            $guestData = is_array($hold['guest_data']) ? $hold['guest_data'] : [];
            $guestEmail = $guestData['email'] ?? '';
            $expectedToken = hash_hmac('sha256', $cartId . ':' . $guestEmail, $secretKey);

            if (!hash_equals($expectedToken, $accessToken)) {
                $this->pdo->rollBack();
                throw HttpException::unauthorized("Access token invalido.");
            }

            if ($hold['status'] !== BookingStatus::Pending->value) {
                $this->pdo->rollBack();
                Response::json([
                    'success' => false,
                    'message' => 'Esta reserva ya fue procesada o expiro.',
                    'status' => $hold['status']
                ]);
                return;
            }

            $this->pdo->commit();

            $paymentData['external_reference'] = $cartId;
            $exchangeRate = (float) Config::get('EXCHANGE_RATE_USD_PEN');
            $paymentData['transaction_amount'] = round($hold['price_snapshot'] * $exchangeRate, 2);

            $paymentResult = $this->paymentGateway->processPayment($paymentData);

            if (!$paymentResult || !isset($paymentResult['id'])) {
                throw new Exception("Fallo al procesar el pago con la pasarela.");
            }

            $status = $paymentResult['status'];
            $paymentIdStr = (string)$paymentResult['id'];

            if ($status === 'approved') {
                $this->pdo->beginTransaction();
                $this->bookingRepo->updateStatus($cartId, BookingStatus::Paid->value);
                $this->bookingRepo->markPaymentProcessed($paymentIdStr, $cartId, 'approved');
                $this->pdo->commit();

                // Dispatch event asynchronously if possible, or synchronously
                $amount = (float)($hold['price_snapshot'] ?? 0.0);
                
                $event = new BookingPaidEvent(
                    (string)$cartId,
                    $paymentIdStr,
                    $amount,
                    (string)($hold['checkin'] ?? ''),
                    (string)($hold['checkout'] ?? ''),
                    (int)($hold['id_room_type'] ?? 1),
                    $hold['guest_data'] ?? [],
                    $hold['room_data'] ?? []
                );

                try {
                    $this->eventDispatcher->dispatch($event);
                } catch (Exception $e) {
                    Logger::error("ProcessPaymentAction: Fallo al despachar el evento: " . $e->getMessage());
                }

                Response::json([
                    'success' => true,
                    'status' => 'approved',
                    'payment_id' => $paymentIdStr,
                    'message' => 'Pago aprobado exitosamente.'
                ]);
            } else {
                // Pago pendiente o rechazado: registrar payment_id para reconciliacion posterior
                $this->bookingRepo->attachPaymentId($cartId, $paymentIdStr);

                Response::json([
                    'success' => false,
                    'status' => $status,
                    'status_detail' => $paymentResult['status_detail'] ?? '',
                    'message' => 'El pago fue rechazado o se encuentra pendiente.'
                ]);
            }

        } catch (HttpException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Logger::error('ProcessPaymentAction Exception: ' . $e->getMessage());
            Response::error('Error interno al procesar el pago.', 500);
        }
    }
}
