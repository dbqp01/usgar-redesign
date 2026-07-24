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
 * Procesa notificaciones de pago IPN de Mercado Pago con idempotencia y bloqueo pesimista.
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

        $type = $body['type'] ?? ($body['topic'] ?? null);
        $paymentId = $body['data']['id'] ?? ($body['id'] ?? null);

        if ($type !== 'payment' || !$paymentId) {
            Response::json(['success' => true, 'message' => 'Notification ignored (not a payment event).']);
        }

        $paymentIdStr = (string)$paymentId;

        // 1. Verificacion de Idempotencia previa
        if ($this->bookingRepo->isPaymentProcessed($paymentIdStr)) {
            Logger::info("HandleMercadoPagoWebhookAction: Payment ID {$paymentIdStr} ya consta como procesado en la tabla de idempotencia.");
            Response::json(['success' => true, 'message' => 'Payment already processed.']);
        }

        $webhookSecret = Config::get('MERCADO_PAGO_WEBHOOK_SECRET');
        if (empty($webhookSecret)) {
            Logger::error('HandleMercadoPagoWebhookAction: MERCADO_PAGO_WEBHOOK_SECRET no configurado.');
            Response::error('Webhook security not configured.', 500);
        }

        $signatureHeader = $request->getHeader('x-signature') ?? '';
        $requestId = $request->getHeader('x-request-id') ?? '';

        if (!$this->paymentGateway->verifySignature($signatureHeader, $requestId, $paymentIdStr)) {
            Logger::error("HandleMercadoPagoWebhookAction: Firma inválida detectada para Pago ID {$paymentIdStr}");
            Response::unauthorized('Firma de webhook inválida.');
        }

        // Obtener detalles del pago desde la API de Mercado Pago
        $paymentDetails = $this->paymentGateway->getPaymentDetails($paymentIdStr);
        if (!$paymentDetails) {
            Logger::error("HandleMercadoPagoWebhookAction Error: No se pudieron obtener detalles para Pago ID {$paymentIdStr}");
            Response::error('No se pudieron obtener los detalles del pago de Mercado Pago.', 500);
        }

        $status = $paymentDetails['status'] ?? 'pending';
        $cartId = $paymentDetails['external_reference'] ?? null;
        $amount = (float)($paymentDetails['transaction_amount'] ?? 0.0);

        if ($status !== 'approved' || !$cartId) {
            Logger::info("HandleMercadoPagoWebhookAction: Pago ID {$paymentIdStr} tiene estado '{$status}'. Omitiendo confirmación.");
            Response::json(['success' => true, 'status' => $status, 'message' => 'Payment status is not approved.']);
        }

        try {
            // 2. Transaccion Local PDO con Bloqueo Pesimista
            $this->pdo->beginTransaction();

            $hold = $this->bookingRepo->getByCartIdForUpdate((string)$cartId);
            if (!$hold) {
                Logger::error("HandleMercadoPagoWebhookAction Error: No se encontró hold para Cart ID {$cartId}");
                $this->pdo->rollBack();
                Response::error("Reserva provisional no encontrada para Cart ID {$cartId}.", 404);
            }

            $holdStatus = BookingStatus::tryFrom($hold['status']);
            if ($holdStatus === BookingStatus::Paid) {
                Logger::info("HandleMercadoPagoWebhookAction: Reserva para Cart ID {$cartId} ya fue procesada previamente.");
                $this->bookingRepo->markPaymentProcessed($paymentIdStr, (string)$cartId, 'approved');
                $this->pdo->commit();
                Response::json(['success' => true, 'message' => 'Booking already marked as paid.']);
            }

            // Marcar reserva como pagada en MySQL local y registrar idempotencia
            $this->bookingRepo->updateStatus((string)$cartId, BookingStatus::Paid->value);
            $this->bookingRepo->markPaymentProcessed($paymentIdStr, (string)$cartId, 'approved');

            $this->pdo->commit();
            Logger::info("HandleMercadoPagoWebhookAction: Transacción en BD local confirmada para Cart ID {$cartId}");

            // 3. Emision de Evento de Dominio Desacoplado
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
                Logger::error("HandleMercadoPagoWebhookAction: Fallo en integración externa durante la dispatch del evento: " . $e->getMessage());
                // En Hostinger, actualizar inmediatamente a manual_review en MySQL (<5ms)
                $this->bookingRepo->updateStatus((string)$cartId, 'manual_review');
                Response::json([
                    'success' => true,
                    'status'  => 'manual_review',
                    'message' => 'Payment recorded, but external PMS sync flagged for manual review.'
                ]);
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
}
