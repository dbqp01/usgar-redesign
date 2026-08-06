<?php
declare(strict_types=1);

namespace App\Features\Booking\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\BookingStatus;
use App\Core\BookingHoldToken;
use App\Core\PriceCalculator;
use App\Core\GuestName;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\Events\EventDispatcher;
use App\Features\Booking\Domain\Events\BookingPaidEvent;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use MercadoPago\Exceptions\MPApiException;
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
            $guestData = is_array($hold['guest_data']) ? $hold['guest_data'] : [];
            $guestEmail = $guestData['email'] ?? '';
            $expectedToken = BookingHoldToken::derive($cartId, $guestEmail);

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

            // --- Todo 3/4 (W1): densidad antifraude desde el HOLD persistido ---
            // payer.name/surname/phone salen de guest_data (no del request del
            // cliente); additional_info.items[] con categoria travel (noches PEN).
            $guestData = is_array($hold['guest_data']) ? $hold['guest_data'] : [];
            $roomData  = is_array($hold['room_data']) ? $hold['room_data'] : [];

            $gatewayPrice = PriceCalculator::toGatewayPrice((float)$hold['price_snapshot']);
            $nights  = max(1, (int)($roomData['nights'] ?? 1));
            $roomName = trim((string)($roomData['room_name'] ?? ''));

            $guestName = trim((string)($guestData['name'] ?? ''));

            $payer = is_array($paymentData['payer'] ?? null) ? $paymentData['payer'] : [];
            if ($guestName === '') {
                // QA- (todo 3): guest sin name -> nombre por defecto sin romper el payload
                $payer['name']    = 'Huésped USGAR';
                $payer['surname'] = '';
            } else {
                $nameParts  = GuestName::split($guestName);
                $payer['name']    = $nameParts[0] ?? '';
                $payer['surname'] = trim($nameParts[1] ?? '');
            }

            $phoneDigits = preg_replace('/\D+/', '', (string)($guestData['phone'] ?? '')) ?? '';
            if ($phoneDigits !== '') {
                if (str_starts_with($phoneDigits, '51') && strlen($phoneDigits) > 9) {
                    $phoneDigits = substr($phoneDigits, 2); // quitar prefijo pais PE
                }
                $payer['phone'] = [
                    'area_code' => (int) substr($phoneDigits, 0, 2),
                    'number'    => (int) substr($phoneDigits, 2),
                ];
            }

            $paymentData['external_reference'] = 'USGAR-' . $cartId; // formato compartido con todo 22 (dedup PMS)
            $paymentData['transaction_amount'] = $gatewayPrice;
            $paymentData['currency_id']        = Config::get('MERCADO_PAGO_CURRENCY', 'PEN'); // todo 4
            $paymentData['payer']              = $payer;
            $paymentData['additional_info']    = [
                'items' => [[
                    'id'          => (string)($hold['id_room_type'] ?? ''),
                    'title'       => $roomName !== '' ? $roomName : 'Habitación',
                    'description' => sprintf('%d noche(s) - %s', $nights, $roomName !== '' ? $roomName : 'Habitación'),
                    'quantity'    => $nights,
                    'unit_price'  => round($gatewayPrice / $nights, 2),
                    'category_id' => 'travel',
                ]],
            ];

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
                $event = BookingPaidEvent::fromHold((string)$cartId, $paymentIdStr, $hold);

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
        } catch (MPApiException $e) {
            // Todo 6: errores de MercadoPago -> passthrough del status real (400/422)
            // con details.status_detail para el frontend (todo 26); nunca 500 generico.
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $statusCode = $e->getStatusCode();
            $apiBody = $e->getApiResponse() ? $e->getApiResponse()->getContent() : null;
            $statusDetail = is_array($apiBody) ? (string)($apiBody['status_detail'] ?? '') : '';
            Logger::error('ProcessPaymentAction MPApiException status=' . $statusCode . ' detail=' . ($statusDetail !== '' ? $statusDetail : 'n/a'));
            Response::error(
                'El pago fue rechazado por la pasarela.',
                $statusCode,
                'PAYMENT_REJECTED',
                $statusDetail !== '' ? ['status_detail' => $statusDetail] : []
            );
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Logger::error('ProcessPaymentAction Exception: ' . $e->getMessage());
            Response::error('Error interno al procesar el pago.', 500);
        }
    }
}
