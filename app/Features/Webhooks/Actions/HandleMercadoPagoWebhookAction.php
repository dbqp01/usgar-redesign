<?php
declare(strict_types=1);

namespace App\Features\Webhooks\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Config;
use App\Core\BookingStatus;
use App\Core\PriceCalculator;
use App\Core\RateLimiter;
use App\Core\Events\EventDispatcher;
use App\Features\Booking\Domain\Events\BookingPaidEvent;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use PDO;
use Exception;

/**
 * Accion ADR: POST /api/webhook
 * Procesa notificaciones de pago Webhook de Mercado Pago con idempotencia y bloqueo pesimista.
 * Usa el SDK oficial para validacion de firma HMAC-SHA256.
 */
class HandleMercadoPagoWebhookAction {
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

        // 0. Extraer tipo de evento ANTES de cualquier validacion
        $type = $body['type'] ?? ($body['action'] ?? null);
        $topic = $request->getQuery('topic');

        // 0.1 TODO 13: filtro ESTRICTO $type === 'payment'. El str_contains
        // laxo dejaba pasar topics SEPARADOS que contienen la subcadena
        // 'payment' (subscription_authorized_payment, topic_chargebacks_wh,
        // etc. — doc MP webhooks, tabla de topics). Cualquier otro type se
        // reconoce con 200 sin procesar ni validar firma.
        if ($type !== 'payment') {
            $nonPaymentMax = (int)Config::get('WEBHOOK_NON_PAYMENT_MAX_REQUESTS', '30');
            $nonPaymentWindow = (int)Config::get('WEBHOOK_NON_PAYMENT_WINDOW_SECONDS', '60');
            if (!RateLimiter::check($request->getIp(), $nonPaymentMax, $nonPaymentWindow)) {
                Logger::error('HandleMercadoPagoWebhookAction: non-payment webhook abuse limit exceeded.');
                Response::error('Too many webhook notifications.', 429, 'RATE_LIMITED');
                return;
            }
            Logger::info("HandleMercadoPagoWebhookAction: Evento no-payment ignorado. Type: " . ($type ?? 'null') . ", Topic: " . ($topic ?? 'null'));
            Response::json(['success' => true, 'message' => 'Non-payment event acknowledged.']);
            return;
        }

        // 1. TODO 13: el payment id sale SOLO de data.id del body y de
        // data.id/data_id del query (MP envia ?data.id=XXX, PHP lo convierte
        // a data_id). body['id'] es el ID de la NOTIFICACION, NO el del pago
        // (doc MP) — nunca se usa como payment id.
        $queryDataId = $request->getQuery('data_id') ?? $request->getQuery('data.id');
        $bodyDataId = $body['data']['id'] ?? null;
        if ($queryDataId !== null && $bodyDataId !== null
            && (string)$queryDataId !== (string)$bodyDataId) {
            Logger::error('HandleMercadoPagoWebhookAction: query/body payment IDs mismatch.');
            Response::error('Mismatched payment identifiers.', 400, 'PAYMENT_ID_MISMATCH');
            return;
        }
        $dataId = $queryDataId ?? $bodyDataId;
        $paymentIdStr = $dataId !== null ? (string)$dataId : '';

        // Log de entrada conciso por evento (sin headers ni datos sensibles)
        Logger::info('HandleMercadoPagoWebhookAction: Webhook recibido', ['type' => $type, 'payment_id' => $paymentIdStr]);

        if (empty($paymentIdStr)) {
            Logger::error('HandleMercadoPagoWebhookAction: No se pudo extraer payment ID del webhook.');
            Response::json(['success' => true, 'message' => 'No payment ID found, event acknowledged.']);
            return;
        }

        // 2. Validar firma HMAC del webhook usando el SDK oficial
        $webhookSecret = Config::get('MERCADO_PAGO_WEBHOOK_SECRET');
        if (empty($webhookSecret)) {
            Logger::error('HandleMercadoPagoWebhookAction: MERCADO_PAGO_WEBHOOK_SECRET no configurado.');
            Response::error('Webhook security not configured.', 500);
            return;
        }

        $signatureHeader = $request->getHeader('x-signature') ?? '';
        $requestId = $request->getHeader('x-request-id') ?? '';

        // Mercado Pago signs the manifest with x-request-id. Reject a
        // missing request id before invoking the verifier so malformed
        // requests cannot reach signature validation with an ambiguous
        // manifest (RF-18 / SDK contract).
        if (trim($requestId) === '') {
            Logger::error("HandleMercadoPagoWebhookAction: Falta el header x-request-id.");
            Response::unauthorized('Falta el header x-request-id.');
            return;
        }

        // 2.2 Validar firma (usa SDK oficial WebhookSignatureValidator internamente)
        if (empty($signatureHeader) || !$this->paymentGateway->verifySignature($signatureHeader, $requestId, $paymentIdStr)) {
            Logger::error("HandleMercadoPagoWebhookAction: Firma de webhook ausente o invalida. DataID: {$paymentIdStr}, SignatureHeader: " . substr($signatureHeader, 0, 20) . '...');
            Response::unauthorized('Firma de webhook invalida o ausente.');
            return;
        }

        try {
            // Fetch MP details before opening the local transaction. Network
            // latency must not hold database locks.
            $paymentDetails = $this->paymentGateway->getPaymentDetails($paymentIdStr);
            if (!$paymentDetails) {
                Logger::error("HandleMercadoPagoWebhookAction Error: No se pudieron obtener detalles para Pago ID {$paymentIdStr}");
                Response::error('No se pudieron obtener los detalles del pago de Mercado Pago.', 500);
                return;
            }
            $remotePaymentId = isset($paymentDetails['id']) ? (string)$paymentDetails['id'] : '';
            if ($remotePaymentId !== '' && $remotePaymentId !== $paymentIdStr) {
                Logger::error("HandleMercadoPagoWebhookAction: API payment ID mismatch for {$paymentIdStr}.");
                Response::error('Mismatched payment identifiers.', 400, 'PAYMENT_ID_MISMATCH');
                return;
            }

            // 3. TODO 11: la transaccion inicia ANTES del chequeo de
            // idempotencia (SELECT ... FOR UPDATE sobre processed_payments)
            // para que el lock no se suelte en autocommit. El chequeo es
            // FAIL-CLOSED: si el SELECT falla -> excepcion -> 500 (MP
            // reintenta; nunca reprocesa un duplicado).
            $this->pdo->beginTransaction();

            $status = $paymentDetails['status'] ?? 'pending';
            // Coordinacion W3: el create (todo 3) envia external_reference
            // 'USGAR-{cartId}'; al resolver el hold se STRIP-ea el prefijo
            // (back-compat: ref sin prefijo se usa directo).
            $cartId = $this->resolveCartId($paymentDetails['external_reference'] ?? null);
            $transactionAmount = (float)($paymentDetails['transaction_amount'] ?? 0.0);

            // 4.5 TODO 12: idempotencia POR TIPO DE EVENTO. El indice unico
            // (payment_id, event_type) permite que un refund del mismo
            // payment_id coexista con su approved; la rama refund la refina
            // la Wave 3, aqui queda la infraestructura (valores de event_type
            // ya definidos).
            $eventType = $this->resolveEventType($status);

            if ($this->bookingRepo->isPaymentProcessed($paymentIdStr, $eventType)) {
                $this->pdo->rollBack();
                Logger::info("HandleMercadoPagoWebhookAction: Payment ID {$paymentIdStr} ya consta como procesado ({$eventType}) en la tabla de idempotencia.");
                Response::json(['success' => true, 'message' => 'Payment already processed.']);
                return;
            }

            // TODO 13 (r1/r2): status rejected/failed -> 200 +
            // markPaymentProcessed('rejected') (cart_id del hold si existe,
            // '' si no). NUNCA 400/500: un error haria que MP reintente la
            // notificacion cada 15 min por siempre (doc MP retries).
            // FIX 2026-08-13: transicion del hold pending -> failed. Sin
            // ella el hold quedaba pending hasta vencer el TTL y la pagina
            // de exito mostraba "BOOKING EXPIRED" aunque MP ya habia
            // rechazado el pago (correo de rechazo llegaba igual). El guard
            // de updateStatus (solo desde pending) la hace segura.
            if (in_array($status, ['rejected', 'failed'], true)) {
                if ($cartId !== null && $cartId !== '') {
                    // A rejected/failed payment can reference a deleted or
                    // otherwise unknown hold. ACK and persist idempotency even
                    // when no local row exists; calling updateStatus blindly
                    // would return false and create an MP retry storm.
                    $rejectedHold = $this->bookingRepo->getByCartIdForUpdate((string)$cartId);
                    if ($rejectedHold !== null
                        && !$this->bookingRepo->updateStatus((string)$cartId, BookingStatus::Failed->value)) {
                        throw new \RuntimeException('No se pudo marcar el pago rechazado.');
                    }
                }
                if (!$this->bookingRepo->markPaymentProcessed($paymentIdStr, (string)($cartId ?? ''), 'rejected')) {
                    throw new \RuntimeException('No se pudo registrar la idempotencia del rechazo.');
                }
                $this->pdo->commit();
                Logger::info("HandleMercadoPagoWebhookAction: Pago ID {$paymentIdStr} marcado como rechazado ({$status}). Reconocido para cortar reintentos.");
                Response::json(['success' => true, 'status' => $status, 'message' => 'Payment rejected/failed, acknowledged.']);
                return;
            }

            if ($status !== 'approved') {
                if (in_array($status, ['refunded', 'charged_back', 'cancelled'], true)) {
                    // Rama refund/chargeback/cancel (fix 2026-08-07): el dinero
                    // salio y el hold/PMS quedan 'paid' — la reconciliacion con
                    // QloApps (cancelar la orden) sigue siendo el refactor W3
                    // diferido. Minimo viable: idempotencia con el MISMO
                    // $eventType del chequeo (L128) + alerta para resolucion
                    // manual (mismo canal que expired_paid/fraud_review).
                    // Antes era un no-op silencioso: updateStatus('failed') es
                    // fail-closed (target no declarado en el guard del todo 9)
                    // y, sin markPaymentProcessed, cada notificacion repetida
                    // de MP reprocesaba el evento para siempre.
                    // FIX 2026-08-13: 'cancelled' SI es terminal sin dinero
                    // capturado -> pending -> failed (mismo guard); un pago
                    // cancelado no debe dejar el hold pending hasta el TTL.
                    if ($status === 'cancelled' && $cartId) {
                        // A stale/unknown external_reference must not turn a
                        // terminal cancellation into a retry storm. Only
                        // transition an existing hold; the idempotency row and
                        // alert below still acknowledge the event otherwise.
                        $cancelledHold = $this->bookingRepo->getByCartIdForUpdate((string)$cartId);
                        if ($cancelledHold !== null
                            && in_array((string)($cancelledHold['status'] ?? ''), [
                                BookingStatus::Pending->value,
                                BookingStatus::Expired->value,
                            ], true)
                            && !$this->bookingRepo->updateStatus((string)$cartId, BookingStatus::Failed->value)) {
                            throw new \RuntimeException('No se pudo marcar el pago cancelado.');
                        }
                    }
                    // A refund/cancel without external_reference is still a
                    // terminal payment event. Persist it with an empty cart id
                    // so duplicate deliveries are idempotent and auditable.
                    $alertCartId = (string)($cartId ?? '');
                    if (!$this->bookingRepo->markPaymentProcessed($paymentIdStr, $alertCartId, $eventType)
                        || !$this->bookingRepo->recordAlert($alertCartId, $paymentIdStr, $status)) {
                        throw new \RuntimeException('No se pudo registrar la idempotencia de la alerta.');
                    }
                    $this->pdo->commit();
                    Logger::error("HandleMercadoPagoWebhookAction ALERTA: Pago {$paymentIdStr} llego como '{$status}' para Cart ID {$cartId} — registrado para resolucion manual (cancelacion de orden en PMS pendiente, W3).");
                    Response::json(['success' => true, 'status' => $status, 'message' => 'Refund/chargeback acknowledged; manual resolution required.']);
                    return;
                }
                $this->pdo->rollBack();
                Logger::info("HandleMercadoPagoWebhookAction: Pago ID {$paymentIdStr} tiene estado '{$status}'. Omitiendo confirmacion.");
                Response::json(['success' => true, 'status' => $status, 'message' => 'Payment status is not approved.']);
                return;
            }

            // TODO 14: pago approved SIN external_reference localizable ->
            // orphan (el hold no puede resolverse). Se marca procesado para
            // cortar el reintento infinito de MP.
            if (!$cartId) {
                $this->markOrphan($paymentIdStr);
                return;
            }

            // 5. Transaccion Local PDO con Bloqueo Pesimista
            $hold = $this->bookingRepo->getByCartIdForUpdate((string)$cartId);
            if (!$hold) {
                // TODO 14: firma valida + hold inexistente -> 200 + fila
                // orphan (cart_id='' — la columna es NOT NULL). MP reintenta
                // cada 15 min indefinidamente ante no-200 (doc MP); marcarlo
                // procesado corta el retry. El chequeo isPaymentProcessed
                // corre DENTRO de la txn ANTES del INSERT (patron todo 11) y
                // el INSERT usa ON DUPLICATE KEY (carrera concurrente segura).
                Logger::error("HandleMercadoPagoWebhookAction ALERTA ORPHAN: Pago {$paymentIdStr} sin hold para Cart ID {$cartId}");
                $this->markOrphan($paymentIdStr);
                return;
            }

            // El webhook puede ser el unico camino despues de que el cobro
            // sincrono haya fallado. Vincular primero el pago evita perder
            // payment_id y permite que polling/reconciliacion/refunds sigan
            // encontrando el pago. La operacion es idempotente para el mismo
            // ID y fail-closed si el hold ya pertenece a otro pago.
            if (!$this->bookingRepo->attachPaymentId((string)$cartId, $paymentIdStr)) {
                throw new \RuntimeException('No se pudo vincular el payment_id al hold.');
            }

            $holdStatus = BookingStatus::tryFrom($hold['status']);

            // 5.1 TODO 9: pago approved sobre hold EXPIRADO -> expired_paid +
            // alerta (log + tabla) para resolucion manual: la habitacion pudo
            // re-venderse mientras el pago tardaba. No se revive la reserva.
            if ($holdStatus === BookingStatus::Expired) {
                Logger::error("HandleMercadoPagoWebhookAction ALERTA: Pago approved {$paymentIdStr} llego sobre hold expirado {$cartId} (posible reventa). Marcado expired_paid.");
                if (!$this->bookingRepo->updateStatus((string)$cartId, BookingStatus::ExpiredPaid->value)
                    || !$this->bookingRepo->markPaymentProcessed($paymentIdStr, (string)$cartId, 'approved')
                    || !$this->bookingRepo->recordAlert((string)$cartId, $paymentIdStr, 'expired_paid')) {
                    throw new \RuntimeException('No se pudo persistir el pago tardío.');
                }
                $this->pdo->commit();
                Response::json([
                    'success' => true,
                    'status'  => 'expired_paid',
                    'message' => 'Pago tardio registrado; requiere resolucion manual.'
                ]);
                return;
            }

            if ($holdStatus === BookingStatus::Paid) {
                if (!$this->bookingRepo->markPaymentProcessed($paymentIdStr, (string)$cartId, 'approved')) {
                    throw new \RuntimeException('No se pudo registrar la idempotencia del pago tardío.');
                }
                $this->pdo->commit();
                Response::json(['success' => true, 'message' => 'Booking already marked as paid.']);
                return;
            }

            // Approved notifications cannot bypass an operator-controlled
            // fraud/manual-review flow. RetryManualReviewAction is the only
            // path allowed to resolve these states to paid.
            if ($holdStatus === BookingStatus::FraudReview
                || $holdStatus === BookingStatus::ManualReview) {
                if ($this->bookingRepo->isPaymentProcessed($paymentIdStr, 'approved_after_review')) {
                    $this->pdo->rollBack();
                    Response::json(['success' => true, 'message' => 'Payment already acknowledged for manual review.']);
                    return;
                }
                if (!$this->bookingRepo->markPaymentProcessed($paymentIdStr, (string)$cartId, 'approved_after_review')
                    || !$this->bookingRepo->recordAlert((string)$cartId, $paymentIdStr, 'approved_after_review')) {
                    throw new \RuntimeException('No se pudo auditar el pago aprobado en revisión.');
                }
                $this->pdo->commit();
                Response::json([
                    'success' => true,
                    'status' => $holdStatus->value,
                    'message' => 'Payment acknowledged; manual review is still required.',
                ]);
                return;
            }

            // 5.5 VALIDACION DE SEGURIDAD ESTRICTA (Amount Mismatch) — todo 32.
            // Comparacion en CENTAVOS ENTEROS: NUNCA igualdad float ni
            // number_format (imprecision de punto flotante = falsos positivos).
            // expected_pen_cents se DERIVA del PEN CONGELADO al cotizar
            // (price_snapshot_pen, columna auto-heal + write del todo 25):
            // el hold fija la tasa en creacion, el cambio posterior de
            // EXCHANGE_RATE_USD_PEN NO debe producir falso fraude. Back-compat
            // documentado: hold legacy sin la columna (NULL) deriva con la
            // tasa ACTUAL via PriceCalculator::toGatewayPrice.
            $priceSnapshotPen = $hold['price_snapshot_pen'] ?? null;
            $expectedPenCents = $priceSnapshotPen !== null
                ? (int)round((float)$priceSnapshotPen * 100)
                : (int)round(PriceCalculator::toGatewayPrice((float)($hold['price_snapshot'] ?? 0.0)) * 100);
            $chargedPenCents = (int)round($transactionAmount * 100);
            if ($chargedPenCents < $expectedPenCents) {
                // TODO 16: monto insuficiente -> FraudReview + processed('fraud_review')
                // EN LA MISMA transaccion + 200 (antes 400 sin marcar -> MP
                // reintentaba cada 15 min por siempre). La reserva NO queda
                // paid; fraud_review -> paid es legal cuando el cron del todo
                // 24 re-despacha con el monto correcto (guard del todo 9).
                Logger::error("HandleMercadoPagoWebhookAction ALERTA FRAUDE: Monto cobrado ({$chargedPenCents} centavos) es menor al esperado ({$expectedPenCents} centavos) para Cart ID {$cartId}");
                if (!$this->bookingRepo->isPaymentProcessed($paymentIdStr, 'fraud_review')) {
                    if (!$this->bookingRepo->updateStatus((string)$cartId, BookingStatus::FraudReview->value)
                        || !$this->bookingRepo->markPaymentProcessed($paymentIdStr, (string)$cartId, 'fraud_review')
                        || !$this->bookingRepo->recordAlert((string)$cartId, $paymentIdStr, 'fraud_review')) {
                        throw new \RuntimeException('No se pudo persistir la revisión antifraude.');
                    }
                }
                $this->pdo->commit();
                Response::json([
                    'success' => true,
                    'status'  => 'fraud_review',
                    'message' => 'Payment under fraud review, acknowledged.'
                ]);
                return;
            }

            // Marcar reserva como pagada en MySQL local y registrar idempotencia
            if (!$this->bookingRepo->updateStatus((string)$cartId, BookingStatus::Paid->value)
                || !$this->bookingRepo->markPaymentProcessed($paymentIdStr, (string)$cartId, 'approved')) {
                throw new \RuntimeException('No se pudo persistir el pago aprobado.');
            }

            // TODO 18/23 (Wave 4): el INSERT del evento en event_outbox corre
            // DENTRO de la transaccion (dispatch -> outbox) ANTES del commit —
            // patron transactional-outbox (microservices.io): si el commit se
            // confirma, el evento YA esta persistido; no se pierde en la
            // ventana commit->ACK. Los listeners corren en el cron
            // (process_outbox, todo 19); aqui NO queda trabajo pesado tras el
            // ACK. Fail-closed (todo 11): si el INSERT del outbox falla y el
            // fallback dispatchNow lanza, la excepcion propaga -> rollback ->
            // 500 -> MP reintenta (idempotencia protege).
            $event = BookingPaidEvent::fromHold((string)$cartId, $paymentIdStr, $hold);
            $this->eventDispatcher->dispatch($event);

            $this->pdo->commit();
            Logger::info("HandleMercadoPagoWebhookAction: Transaccion en BD local confirmada para Cart ID {$cartId}");

            // 6. ACK (200) DESPUES del commit: el outbox ya tiene el evento y
            // el cron lo entrega aunque el proceso muera aqui mismo.
            Response::jsonAsync([
                'success' => true,
                'cart_id' => $cartId,
                'status'  => 'approved',
                'message' => 'Payment processed locally. Dispatching external sync.'
            ]);

            // Terminamos el script en background (los listeners corren en el cron).
            return;

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Logger::error('HandleMercadoPagoWebhookAction Exception general: ' . $e->getMessage());
            Response::error('Error interno al procesar el webhook.', 500);
        }
    }

    /**
     * Mapea el status del pago al tipo de evento de idempotencia (todo 12).
     * La rama refunded/charged_back habilita los refunds; rejected/failed
     * evita reintentos eternos de MP; orphan/fraud_review los usan las ramas
     * de la Wave 3.
     */
    private function resolveEventType(string $status): string {
        return match ($status) {
            'refunded', 'charged_back' => 'refunded',
            'rejected', 'failed', 'cancelled' => 'rejected',
            default => 'approved',
        };
    }

    /**
     * Normaliza el external_reference del pago al cartId local.
     * Coordinacion W3: el create (todo 3) envia 'USGAR-{cartId}'; se
     * STRIP-ea el prefijo. Back-compat: una ref sin prefijo (legacy) se usa
     * directa. Devuelve null si no hay ref o el prefijo deja vacio.
     */
    private function resolveCartId(?string $externalReference): ?string {
        if ($externalReference === null || $externalReference === '') {
            return null;
        }
        if (str_starts_with($externalReference, 'USGAR-')) {
            $stripped = substr($externalReference, 6);
            return $stripped === '' ? null : $stripped;
        }
        return $externalReference;
    }

    /**
     * TODO 14: marca un pago sin hold como procesado (orphan) y responde 200,
     * cortando el reintento infinito de MP. El chequeo isPaymentProcessed
     * corre DENTRO de la transaccion ANTES del INSERT (determinismo bajo
     * carrera; el INSERT usa ON DUPLICATE KEY como cinturon-y-tirantes).
     * cart_id = '' porque la columna es NOT NULL. Requiere txn activa.
     */
    private function markOrphan(string $paymentIdStr): void {
        if (!$this->bookingRepo->isPaymentProcessed($paymentIdStr, 'orphan')) {
            if (!$this->bookingRepo->markPaymentProcessed($paymentIdStr, '', 'orphan')) {
                throw new \RuntimeException('No se pudo registrar el pago huérfano.');
            }
        }
        $this->pdo->commit();
        Logger::info("HandleMercadoPagoWebhookAction: Pago {$paymentIdStr} sin hold marcado como orphan (idempotencia registrada).");
        Response::json([
            'success' => true,
            'status'  => 'orphan',
            'message' => 'Payment orphan acknowledged.'
        ]);
    }
}
