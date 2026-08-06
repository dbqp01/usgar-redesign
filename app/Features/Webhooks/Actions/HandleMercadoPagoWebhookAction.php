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

        // 0.1 Filtrar eventos que NO son de pago (merchant_order, chargebacks, etc.)
        // Devolver 200 OK para que Mercado Pago deje de reintentar
        $isPaymentEvent = false;
        if ($type !== null && (str_contains((string)$type, 'payment') || $type === 'payment')) {
            $isPaymentEvent = true;
        }

        if (!$isPaymentEvent) {
            Logger::info("HandleMercadoPagoWebhookAction: Evento no-payment ignorado. Type: " . ($type ?? 'null') . ", Topic: " . ($topic ?? 'null'));
            Response::json(['success' => true, 'message' => 'Non-payment event acknowledged.']);
            return;
        }

        // 1. Extraer data ID del query string (MP envia ?data.id=XXX, PHP lo convierte a data_id)
        $dataId = $request->getQuery('data_id')
            ?? $request->getQuery('data.id')
            ?? $request->getQuery('id')
            ?? $body['data']['id']
            ?? ($body['id'] ?? null);
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
            $cartId = $paymentDetails['external_reference'] ?? null;
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

            if ($status !== 'approved' || !$cartId) {
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

            // 5. Transaccion Local PDO con Bloqueo Pesimista
            $hold = $this->bookingRepo->getByCartIdForUpdate((string)$cartId);
            if (!$hold) {
                Logger::error("HandleMercadoPagoWebhookAction Error: No se encontro hold para Cart ID {$cartId}");
                $this->pdo->rollBack();
                Response::error("Reserva provisional no encontrada para Cart ID {$cartId}.", 404);
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
            // Usar bccomp para evitar falsos positivos por imprecision de punto flotante
            $expectedPen = PriceCalculator::toGatewayPrice((float)($hold['price_snapshot'] ?? 0.0));
            $transactionStr = number_format($transactionAmount, 2, '.', '');
            $expectedStr = number_format($expectedPen, 2, '.', '');
            if (bccomp($transactionStr, $expectedStr, 2) < 0) {
                Logger::error("HandleMercadoPagoWebhookAction ALERTA FRAUDE: Monto cobrado ({$transactionStr}) es menor al esperado ({$expectedStr}) para Cart ID {$cartId}");
                $this->bookingRepo->updateStatus((string)$cartId, BookingStatus::FraudReview->value);
                $this->pdo->commit();
                Response::error("El monto de la transaccion no coincide con el valor de la reserva.", 400);
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
}
