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

            // --- Todo 8 (W2): gates del lock pesimista ---
            // El cobro ocurre DENTRO de la transaccion del lock (FOR UPDATE):
            // la ventana commit-antes-de-cobrar desaparece y un segundo
            // intento concurrente sobre el mismo hold no puede volver a cobrar.

            // Gate 1: hold expirado -> no cobrar (el cron lo marcara expired).
            if (strtotime((string)($hold['expires_at'] ?? '')) <= time()) {
                $this->pdo->rollBack();
                Response::json([
                    'success' => false,
                    'message' => 'Esta reserva ya fue procesada o expiro.',
                    'status' => 'expired'
                ]);
                return;
            }

            // Gate 2 (fix BLOCKER r3): si el hold YA tiene payment_id (intento
            // previo pending/in_process), NO llamar la gateway — responder
            // success:false(status pending) y dejar que polling/webhook
            // reconcilien. Sin este gate, un reintento concurrente vuelve a
            // cobrar sobre el mismo cart.
            $existingPaymentId = trim((string)($hold['payment_id'] ?? ''));
            if ($existingPaymentId !== '') {
                $this->pdo->rollBack();
                Response::json([
                    'success' => false,
                    'status' => 'pending',
                    'message' => 'Ya existe un pago en curso para esta reserva; se confirmara automaticamente.'
                ]);
                return;
            }

            // --- Todo 3/4 (W1): densidad antifraude desde el HOLD persistido ---
            // payer.name/surname/phone salen de guest_data (no del request del
            // cliente); additional_info.items[] con categoria travel (noches PEN).
            $guestData = is_array($hold['guest_data']) ? $hold['guest_data'] : [];
            $roomData  = is_array($hold['room_data']) ? $hold['room_data'] : [];

            // Todo 32 (W6): usar el PEN congelado al cotizar (sin re-leer la tasa
            // actual); fallback legacy a la derivacion USD x tasa actual.
            $frozenPen = $hold['price_snapshot_pen'] ?? null;
            $gatewayPrice = $frozenPen !== null
                ? (float)$frozenPen
                : PriceCalculator::toGatewayPrice((float)$hold['price_snapshot']);
            $nights  = max(1, (int)($roomData['nights'] ?? 1));
            $roomName = trim((string)($roomData['room_name'] ?? ''));

            $guestName = trim((string)($guestData['name'] ?? ''));

            $payer = is_array($paymentData['payer'] ?? null) ? $paymentData['payer'] : [];
            // Fix F3 (2026-08-06, verificado con mercadopago-mcp-server
            // search_documentation "create payment payer" es/MPE + sandbox real):
            // el schema del create /v1/payments usa first_name/last_name; el
            // envio de name/surname devuelve 400 bad_request ("The name of the
            // following parameters is wrong : [payer.surname, payer.name]").
            if ($guestName === '') {
                // QA- (todo 3): guest sin name -> nombre por defecto sin romper el payload
                $payer['first_name'] = 'Huesped USGAR';
                $payer['last_name']  = '';
            } else {
                $nameParts  = GuestName::split($guestName);
                $payer['first_name'] = $nameParts[0] ?? '';
                $payer['last_name']  = trim($nameParts[1] ?? '');
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
            // Fix F3 (2026-08-06): el create /v1/payments NO acepta
            // currency_id (verificado con MCP + sandbox real; MP infiere la
            // moneda de la cuenta). La moneda de cobro (Config, todo 34) se
            // propaga via BookingPaidEvent al PMS, no en el create.
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

            // La transaccion del lock SIGUE ABIERTA durante la llamada a la
            // gateway (timeout total <= 15s < lock_wait_timeout de MySQL):
            // el pago y el attach/status se hacen atomicos.
            $paymentResult = $this->paymentGateway->processPayment($paymentData);

            if (!$paymentResult || !isset($paymentResult['id'])) {
                throw new Exception("Fallo al procesar el pago con la pasarela.");
            }

            $status = $paymentResult['status'];
            $paymentIdStr = (string)$paymentResult['id'];

            if ($status === 'approved') {
                // Todo 8 (fix MINOR r4): persistir payment_id + status paid
                // DENTRO de la txn (polling todo 27 y refunds todo 12
                // dependen de payment_id persistido).
                $this->bookingRepo->attachPaymentId($cartId, $paymentIdStr);
                $this->bookingRepo->updateStatus($cartId, BookingStatus::Paid->value);
                $this->bookingRepo->markPaymentProcessed($paymentIdStr, $cartId, 'approved');
            } elseif (in_array($status, ['pending', 'in_process'], true)) {
                // QA+2: attach DENTRO de la txn + commit; success:false(status
                // pending) — no se pierde el payment_id para reconciliacion.
                $this->bookingRepo->attachPaymentId($cartId, $paymentIdStr);
            } else {
                // QA-1: la gateway devolvio un pago rechazado SIN excepcion ->
                // rollback, nada persistido (ni payment_id ni status).
                $this->pdo->rollBack();
                Response::json([
                    'success' => false,
                    'status' => $status,
                    'status_detail' => $paymentResult['status_detail'] ?? '',
                    'message' => 'El pago fue rechazado por la pasarela.'
                ]);
                return;
            }

            // --- RAMA COMMIT-FALLA (revision r1): la gateway tuvo exito pero
            // commit() lanza (error BD/red) -> el pago existe en MP y el hold
            // quedaria pending SIN payment_id; un reintento seria doble cobro.
            // Best-effort: transaccion corta para attachPaymentId ANTES de
            // responder + success:false(status pending) para que el
            // polling/webhook (todo 31) reconcilien; si ni el attach funciona
            // -> 500.
            try {
                $this->pdo->commit();
            } catch (Exception $e) {
                Logger::error('ProcessPaymentAction: commit fallo tras cobro exitoso (payment_id=' . $paymentIdStr . '): ' . $e->getMessage());
                if ($this->pdo->inTransaction()) {
                    try {
                        $this->pdo->rollBack();
                    } catch (Exception $ignore) {
                        // la conexion pudo morir; seguir con el attach best-effort
                    }
                }
                $attached = $this->bookingRepo->attachPaymentId($cartId, $paymentIdStr);
                if (!$attached) {
                    Logger::error('ProcessPaymentAction: attach best-effort fallo para cart ' . $cartId . ' (payment_id=' . $paymentIdStr . ').');
                    throw new Exception(
                        'El pago se proceso en la pasarela pero no se pudo registrar en la BD.',
                        0,
                        $e
                    );
                }
                Response::json([
                    'success' => false,
                    'status' => 'pending',
                    'payment_id' => $paymentIdStr,
                    'message' => 'El pago se proceso, pero la confirmacion local fallo; se reconciliara automaticamente.'
                ]);
                return;
            }

            if ($status === 'approved') {
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
                // Pago pending/in_process: el payment_id ya quedo persistido
                // dentro de la txn; polling (todo 27) y webhook resolveran.
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
