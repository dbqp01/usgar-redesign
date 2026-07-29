<?php
declare(strict_types=1);

namespace App\Features\Webhooks\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Config;
use App\Core\Database;
use App\Core\BookingStatus;
use App\Core\Events\EventDispatcher;
use App\Features\Booking\Domain\Events\BookingPaidEvent;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Ports\PmsPortInterface;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use App\Features\Shared\Ports\ChannelManagerPortInterface;
use App\Features\Shared\Adapters\QloAppAdapter;
use App\Features\Shared\Adapters\MercadoPagoAdapter;
use App\Features\Shared\Adapters\ChannexAdapter;
use PDO;
use Exception;

/**
 * Accion ADR: POST /api/webhook y POST /api/webhook-mercado-pago
 * Procesa notificaciones de pago Webhook de Mercado Pago con idempotencia y bloqueo pesimista.
 * Usa el SDK oficial para validacion de firma HMAC-SHA256.
 */
class HandleMercadoPagoWebhookAction {
    private PDO $pdo;
    private PmsPortInterface $pms;
    private PaymentGatewayPortInterface $paymentGateway;
    private ChannelManagerPortInterface $channelManager;
    private ProvisionalBookingRepository $bookingRepo;
    private EventDispatcher $eventDispatcher;

    public function __construct(
        ?PDO $pdo = null,
        ?PmsPortInterface $pms = null,
        ?PaymentGatewayPortInterface $paymentGateway = null,
        ?ChannelManagerPortInterface $channelManager = null,
        ?EventDispatcher $eventDispatcher = null
    ) {
        $db = Database::getInstance();
        $this->pdo = $pdo ?? $db->getConnection();
        $this->pms = $pms ?? new QloAppAdapter($this->pdo);
        $this->paymentGateway = $paymentGateway ?? new MercadoPagoAdapter();
        $this->channelManager = $channelManager ?? new ChannexAdapter();
        $this->bookingRepo = new ProvisionalBookingRepository($this->pdo);
        $this->eventDispatcher = $eventDispatcher ?? EventDispatcher::getInstance();
    }

    public function __invoke(Request $request): void {
        $body = $request->getBody() ?? [];

        // ========== DIAGNOSTIC LOGGING ==========
        // Registra todos los headers y query params recibidos para depuracion
        $allHeaders = $request->getHeaders();
        $allQuery = $request->getQueryParams();
        Logger::info('WEBHOOK DIAGNOSTICS', [
            'headers'      => $this->sanitizeForLog($allHeaders),
            'query_params' => $allQuery,
            'body_keys'    => array_keys($body),
            'body_type'    => $body['type'] ?? ($body['action'] ?? 'N/A'),
            'body_data_id' => $body['data']['id'] ?? ($body['id'] ?? 'N/A'),
            'has_x_signature' => isset($allHeaders['x-signature']) ? 'YES' : 'NO',
            'has_x_request_id' => isset($allHeaders['x-request-id']) ? 'YES' : 'NO',
            'raw_server_http_x_signature' => $_SERVER['HTTP_X_SIGNATURE'] ?? 'NOT_PRESENT',
        ]);
        // ========================================

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

        // 2.1 Filtrar notificaciones IPN legacy (topic=payment en query, sin firma x-signature)
        if (empty($signatureHeader) && ($topic === 'payment' || $topic === 'merchant_order')) {
            Logger::info("HandleMercadoPagoWebhookAction: Ignorando notificacion IPN legacy (topic={$topic})");
            Response::json(['success' => true, 'message' => 'Legacy IPN ignored.']);
            return;
        }

        // 2.2 Validar firma (usa SDK oficial WebhookSignatureValidator internamente)
        Logger::info("HandleMercadoPagoWebhookAction: Validando firma. DataId: {$paymentIdStr}, RequestId: {$requestId}, SignaturePresent: " . (empty($signatureHeader) ? 'NO' : 'YES'));

        if (empty($signatureHeader) || !$this->paymentGateway->verifySignature($signatureHeader, $requestId, $paymentIdStr)) {
            Logger::error("HandleMercadoPagoWebhookAction: Firma de webhook ausente o invalida. DataID: {$paymentIdStr}, SignatureHeader: " . substr($signatureHeader, 0, 20) . '...');
            Response::unauthorized('Firma de webhook invalida o ausente.');
            return;
        }

        Logger::info("HandleMercadoPagoWebhookAction: Firma validada OK para Payment ID {$paymentIdStr}");

        // 3. Verificacion de Idempotencia previa
        if ($this->bookingRepo->isPaymentProcessed($paymentIdStr)) {
            Logger::info("HandleMercadoPagoWebhookAction: Payment ID {$paymentIdStr} ya consta como procesado en la tabla de idempotencia.");
            Response::json(['success' => true, 'message' => 'Payment already processed.']);
            return;
        }

        // 3.5 Interceptar el boton "Simular Notificacion" de Mercado Pago
        if ($paymentIdStr === '123456' && !Config::isProduction()) {
            Logger::info("HandleMercadoPagoWebhookAction: Simulacion de Mercado Pago recibida y validada correctamente.");
            Response::json(['success' => true, 'message' => 'Simulacion de Mercado Pago exitosa! La firma fue validada correctamente.']);
            return;
        }

        // 4. Obtener detalles del pago desde la API de Mercado Pago
        $paymentDetails = $this->paymentGateway->getPaymentDetails($paymentIdStr);
        if (!$paymentDetails) {
            Logger::error("HandleMercadoPagoWebhookAction Error: No se pudieron obtener detalles para Pago ID {$paymentIdStr}");
            Response::error('No se pudieron obtener los detalles del pago de Mercado Pago.', 500);
            return;
        }

        $status = $paymentDetails['status'] ?? 'pending';
        $cartId = $paymentDetails['external_reference'] ?? null;

        if ($status !== 'approved' || !$cartId) {
            Logger::info("HandleMercadoPagoWebhookAction: Pago ID {$paymentIdStr} tiene estado '{$status}'. Omitiendo confirmacion.");
            if (in_array($status, ['refunded', 'charged_back', 'cancelled'], true) && $cartId) {
                $this->bookingRepo->updateStatus((string)$cartId, BookingStatus::Failed->value);
            }
            Response::json(['success' => true, 'status' => $status, 'message' => 'Payment status is not approved.']);
            return;
        }

        try {
            // 5. Transaccion Local PDO con Bloqueo Pesimista
            $this->pdo->beginTransaction();

            $hold = $this->bookingRepo->getByCartIdForUpdate((string)$cartId);
            if (!$hold) {
                Logger::error("HandleMercadoPagoWebhookAction Error: No se encontro hold para Cart ID {$cartId}");
                $this->pdo->rollBack();
                Response::error("Reserva provisional no encontrada para Cart ID {$cartId}.", 404);
                return;
            }

            $holdStatus = BookingStatus::tryFrom($hold['status']);
            if ($holdStatus === BookingStatus::Paid) {
                Logger::info("HandleMercadoPagoWebhookAction: Reserva para Cart ID {$cartId} ya fue procesada previamente.");
                $this->bookingRepo->markPaymentProcessed($paymentIdStr, (string)$cartId, 'approved');
                $this->pdo->commit();
                Response::json(['success' => true, 'message' => 'Booking already marked as paid.']);
                return;
            }

            // Marcar reserva como pagada en MySQL local y registrar idempotencia
            $this->bookingRepo->updateStatus((string)$cartId, BookingStatus::Paid->value);
            $this->bookingRepo->markPaymentProcessed($paymentIdStr, (string)$cartId, 'approved');

            $this->pdo->commit();
            Logger::info("HandleMercadoPagoWebhookAction: Transaccion en BD local confirmada para Cart ID {$cartId}");

            // 6. Emision de Evento de Dominio Desacoplado
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
                Logger::error("HandleMercadoPagoWebhookAction: Fallo en integracion externa durante la dispatch del evento: " . $e->getMessage());
                // En Hostinger, actualizar inmediatamente a manual_review en MySQL (<5ms)
                $this->bookingRepo->updateStatus((string)$cartId, 'manual_review');
                Response::json([
                    'success' => true,
                    'status'  => 'manual_review',
                    'message' => 'Payment recorded, but external PMS sync flagged for manual review.'
                ]);
                return;
            }

            Response::json([
                'success' => true,
                'cart_id' => $cartId,
                'status'  => 'approved',
                'message' => 'Payment processed and booking confirmed.'
            ]);

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Logger::error('HandleMercadoPagoWebhookAction Exception general: ' . $e->getMessage());
            Response::error('Error interno al procesar el webhook.', 500);
        }
    }

    /**
     * Sanitiza headers para logging seguro (oculta valores sensibles).
     */
    private function sanitizeForLog(array $headers): array {
        $safe = [];
        $sensitiveKeys = ['authorization', 'cookie', 'x-signature'];
        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $sensitiveKeys, true)) {
                $safe[$key] = substr((string)$value, 0, 30) . '...[TRUNCATED]';
            } else {
                $safe[$key] = $value;
            }
        }
        return $safe;
    }
}
