<?php
declare(strict_types=1);

namespace App\Features\Webhooks\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Config;
use App\Core\BookingStatus;
use App\Core\PriceCalculator;
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
            Logger::info("HandleMercadoPagoWebhookAction: Evento no-payment ignorado. Type: " . ($type ?? 'null') . ", Topic: " . ($topic ?? 'null'));
            Response::json(['success' => true, 'message' => 'Non-payment event acknowledged.']);
            return;
        }

        // 1. TODO 13: el payment id sale SOLO de data.id del body y de
        // data.id/data_id del query (MP envia ?data.id=XXX, PHP lo convierte
        // a data_id). body['id'] es el ID de la NOTIFICACION, NO el del pago
        // (doc MP) — nunca se usa como payment id.
        $dataId = $request->getQuery('data_id')
            ?? $request->getQuery('data.id')
            ?? $body['data']['id'] ?? null;
        $paymentIdStr = $dataId ? (string)$dataId : '';

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

        // 2.2 Validar firma (usa SDK oficial WebhookSignatureValidator internamente)
        if (empty($signatureHeader) || !$this->paymentGateway->verifySignature($signatureHeader, $requestId, $paymentIdStr)) {
            Logger::error("HandleMercadoPagoWebhookAction: Firma de webhook ausente o invalida. DataID: {$paymentIdStr}, SignatureHeader: " . substr($signatureHeader, 0, 20) . '...');
            Response::unauthorized('Firma de webhook invalida o ausente.');
            return;
        }

        try {
            // 3. TODO 11: la transaccion inicia ANTES del chequeo de
            // idempotencia (SELECT ... FOR UPDATE sobre processed_payments)
            // para que el lock no se suelte en autocommit. El chequeo es
            // FAIL-CLOSED: si el SELECT falla -> excepcion -> 500 (MP
            // reintenta; nunca reprocesa un duplicado).
            $this->pdo->beginTransaction();

            // 4. Obtener detalles del pago desde la API de Mercado Pago
            // (UN solo intento, timeout total 8s — todo 17 en W3).
            $paymentDetails = $this->paymentGateway->getPaymentDetails($paymentIdStr);
            if (!$paymentDetails) {
                $this->pdo->rollBack();
                Logger::error("HandleMercadoPagoWebhookAction Error: No se pudieron obtener detalles para Pago ID {$paymentIdStr}");
                Response::error('No se pudieron obtener los detalles del pago de Mercado Pago.', 500);
                return;
            }

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
            if (in_array($status, ['rejected', 'failed'], true)) {
                $this->bookingRepo->markPaymentProcessed($paymentIdStr, (string)($cartId ?? ''), 'rejected');
                $this->pdo->commit();
                Logger::info("HandleMercadoPagoWebhookAction: Pago ID {$paymentIdStr} marcado como rechazado ({$status}). Reconocido para cortar reintentos.");
                Response::json(['success' => true, 'status' => $status, 'message' => 'Payment rejected/failed, acknowledged.']);
                return;
            }

            if ($status !== 'approved') {
                $this->pdo->rollBack();
                Logger::info("HandleMercadoPagoWebhookAction: Pago ID {$paymentIdStr} tiene estado '{$status}'. Omitiendo confirmacion.");
                if (in_array($status, ['refunded', 'charged_back', 'cancelled'], true) && $cartId) {
                    // Rama refund legacy (refactor completo en W3): el guard de
                    // updateStatus (todo 9) rechaza la transicion a 'failed'
                    // hoy — infraestructura lista, sin tragado de eventos.
                    $this->bookingRepo->updateStatus((string)$cartId, BookingStatus::Failed->value);
                }
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
                Logger::error("HandleMercadoPagoWebhookAction ALERTA ORPHAN: Pago {$paymentIdStr} sin hold para Cart ID " . ($cartId ?? 'n/a'));
                $this->markOrphan($paymentIdStr);
                return;
            }

            $holdStatus = BookingStatus::tryFrom($hold['status']);

            // 5.1 TODO 9: pago approved sobre hold EXPIRADO -> expired_paid +
            // alerta (log + tabla) para resolucion manual: la habitacion pudo
            // re-venderse mientras el pago tardaba. No se revive la reserva.
            if ($holdStatus === BookingStatus::Expired) {
                Logger::error("HandleMercadoPagoWebhookAction ALERTA: Pago approved {$paymentIdStr} llego sobre hold expirado {$cartId} (posible reventa). Marcado expired_paid.");
                $this->bookingRepo->updateStatus((string)$cartId, BookingStatus::ExpiredPaid->value);
                $this->bookingRepo->markPaymentProcessed($paymentIdStr, (string)$cartId, 'approved');
                $this->bookingRepo->recordAlert((string)$cartId, $paymentIdStr, 'expired_paid');
                $this->pdo->commit();
                Response::json([
                    'success' => true,
                    'status'  => 'expired_paid',
                    'message' => 'Pago tardio registrado; requiere resolucion manual.'
                ]);
                return;
            }

            if ($holdStatus === BookingStatus::Paid) {
                $this->bookingRepo->markPaymentProcessed($paymentIdStr, (string)$cartId, 'approved');
                $this->pdo->commit();
                Response::json(['success' => true, 'message' => 'Booking already marked as paid.']);
                return;
            }

            // 5.5 VALIDACION DE SEGURIDAD ESTRICTA (Amount Mismatch)
            // Usar bccomp para evitar falsos positivos por imprecision de punto flotante.
            // Comparacion actual (W3): price_snapshot (USD) x EXCHANGE_RATE_USD_PEN ->
            // PEN esperado vs transaction_amount (PEN). W6 (todo 32) introduce
            // price_snapshot_pen persistido; esta comparacion se migra ahi.
            $expectedPen = PriceCalculator::toGatewayPrice((float)($hold['price_snapshot'] ?? 0.0));
            $transactionStr = number_format($transactionAmount, 2, '.', '');
            $expectedStr = number_format($expectedPen, 2, '.', '');
            if (bccomp($transactionStr, $expectedStr, 2) < 0) {
                // TODO 16: monto insuficiente -> FraudReview + processed('fraud_review')
                // EN LA MISMA transaccion + 200 (antes 400 sin marcar -> MP
                // reintentaba cada 15 min por siempre). La reserva NO queda
                // paid; fraud_review -> paid es legal cuando el cron del todo
                // 24 re-despacha con el monto correcto (guard del todo 9).
                Logger::error("HandleMercadoPagoWebhookAction ALERTA FRAUDE: Monto cobrado ({$transactionStr}) es menor al esperado ({$expectedStr}) para Cart ID {$cartId}");
                if (!$this->bookingRepo->isPaymentProcessed($paymentIdStr, 'fraud_review')) {
                    $this->bookingRepo->updateStatus((string)$cartId, BookingStatus::FraudReview->value);
                    $this->bookingRepo->markPaymentProcessed($paymentIdStr, (string)$cartId, 'fraud_review');
                    $this->bookingRepo->recordAlert((string)$cartId, $paymentIdStr, 'fraud_review');
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
            $this->bookingRepo->updateStatus((string)$cartId, BookingStatus::Paid->value);
            $this->bookingRepo->markPaymentProcessed($paymentIdStr, (string)$cartId, 'approved');

            $this->pdo->commit();
            Logger::info("HandleMercadoPagoWebhookAction: Transaccion en BD local confirmada para Cart ID {$cartId}");

            // 5.6 Extender tiempo de ejecucion para listeners en shared hosting
            if (function_exists('set_time_limit')) {
                @set_time_limit(120);
            }
            
            // 6. CERRAR CONEXION HTTP TEMPRANAMENTE (Evitar timeout de Mercado Pago)
            // Se responde 200 OK inmediatamente a MP para que no reintente
            Response::jsonAsync([
                'success' => true,
                'cart_id' => $cartId,
                'status'  => 'approved',
                'message' => 'Payment processed locally. Dispatching external sync.'
            ]);

            // 7. Emision de Evento de Dominio Desacoplado (Ahora ejecutado en Background)
            $event = BookingPaidEvent::fromHold((string)$cartId, $paymentIdStr, $hold);

            try {
                $this->eventDispatcher->dispatch($event);
            } catch (Exception $e) {
                Logger::error("HandleMercadoPagoWebhookAction: Fallo en integracion externa durante la dispatch del evento: " . $e->getMessage());
                // En background, actualizar a manual_review si los reintentos locales fallaron
                $this->bookingRepo->updateStatus((string)$cartId, BookingStatus::ManualReview->value);
                // Nota: La respuesta HTTP 200 ya fue enviada a MP, no podemos usar Response::json() aqui.
                return;
            }

            // Terminamos el script en background
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
            $this->bookingRepo->markPaymentProcessed($paymentIdStr, '', 'orphan');
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
