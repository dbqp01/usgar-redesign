<?php
declare(strict_types=1);

namespace App\Features\Webhooks\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Config;
use App\Core\Database;
use App\Core\BookingStatus;
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
 * Acción ADR: POST /api/webhook
 * Procesa notificaciones de pago IPN de Mercado Pago y confirma reservas en QloApps / Channex.
 */
class HandleMercadoPagoWebhookAction {
    private PDO $pdo;
    private PmsPortInterface $pms;
    private PaymentGatewayPortInterface $paymentGateway;
    private ChannelManagerPortInterface $channelManager;
    private ProvisionalBookingRepository $bookingRepo;

    public function __construct(
        ?PDO $pdo = null,
        ?PmsPortInterface $pms = null,
        ?PaymentGatewayPortInterface $paymentGateway = null,
        ?ChannelManagerPortInterface $channelManager = null
    ) {
        $db = Database::getInstance();
        $this->pdo = $pdo ?? $db->getConnection();
        $this->pms = $pms ?? new QloAppAdapter($this->pdo);
        $this->paymentGateway = $paymentGateway ?? new MercadoPagoAdapter();
        $this->channelManager = $channelManager ?? new ChannexAdapter();
        $this->bookingRepo = new ProvisionalBookingRepository($this->pdo);
    }

    public function __invoke(Request $request): void {
        $body = $request->getBody() ?? [];

        $type = $body['type'] ?? ($body['topic'] ?? null);
        $paymentId = $body['data']['id'] ?? ($body['id'] ?? null);

        if ($type !== 'payment' || !$paymentId) {
            Response::json(['success' => true, 'message' => 'Notification ignored.']);
        }

        $webhookSecret = Config::get('MERCADO_PAGO_WEBHOOK_SECRET');

        if (empty($webhookSecret)) {
            Logger::error('HandleMercadoPagoWebhookAction: MERCADO_PAGO_WEBHOOK_SECRET no configurado.');
            Response::error('Webhook security not configured.', 500);
        }

        $signatureHeader = $request->getHeader('x-signature') ?? '';
        $requestId = $request->getHeader('x-request-id') ?? '';

        if (!$this->paymentGateway->verifySignature($signatureHeader, $requestId, (string)$paymentId)) {
            Logger::error("HandleMercadoPagoWebhookAction: Firma inválida detectada para Pago ID {$paymentId}");
            Response::unauthorized('Firma de webhook inválida.');
        }

        $this->sendEarlyResponse();

        $paymentDetails = $this->paymentGateway->getPaymentDetails((string)$paymentId);

        if (!$paymentDetails) {
            Logger::error("HandleMercadoPagoWebhookAction Error: No se pudieron obtener detalles para Pago ID {$paymentId}");
            return;
        }

        $status = $paymentDetails['status'] ?? 'pending';
        $cartId = $paymentDetails['external_reference'] ?? null;
        $amount = (float)($paymentDetails['transaction_amount'] ?? 0.0);

        if ($status !== 'approved' || !$cartId) {
            Logger::info("HandleMercadoPagoWebhookAction: Pago ID {$paymentId} tiene estado {$status}. Omitiendo creación de orden.");
            return;
        }

        try {
            $this->pdo->beginTransaction();

            $hold = $this->bookingRepo->getByCartId($cartId);
            if (!$hold) {
                Logger::error("HandleMercadoPagoWebhookAction Error: No se encontró hold para Cart ID {$cartId}");
                $this->pdo->rollBack();
                return;
            }

            $holdStatus = BookingStatus::tryFrom($hold['status']);
            if ($holdStatus === BookingStatus::Paid) {
                Logger::info("HandleMercadoPagoWebhookAction: Reserva para Cart ID {$cartId} ya fue procesada anteriormente.");
                $this->pdo->rollBack();
                return;
            }

            $this->bookingRepo->updateStatus($cartId, BookingStatus::Paid->value);

            $guestName  = $hold['guest_data']['name'] ?? '';
            $guestEmail = $hold['guest_data']['email'] ?? '';
            $guestPhone = $hold['guest_data']['phone'] ?? '';

            $qloOrderId = $this->pms->confirmOrder($cartId, $amount, $guestName, $guestEmail);

            if (!$qloOrderId) {
                throw new Exception("Fallo en PmsPort confirmOrder para Cart ID {$cartId}");
            }

            $maxGuests = (int)($hold['room_data']['max_guests'] ?? 2);
            $channexSynced = $this->channelManager->createBooking(
                $qloOrderId,
                $hold['checkin'],
                $hold['checkout'],
                (int)$hold['id_room_type'],
                $amount,
                $guestName,
                $guestEmail,
                $guestPhone,
                $maxGuests
            );

            if (!$channexSynced) {
                Logger::warning("HandleMercadoPagoWebhookAction: La sincronización OTA con Channex falló para Orden {$qloOrderId}");
            }

            $this->pdo->commit();
            Logger::info("HandleMercadoPagoWebhookAction Success: Pago {$paymentId} procesado. Orden QloApps {$qloOrderId} creada.");

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Logger::error('HandleMercadoPagoWebhookAction Exception al confirmar reserva: ' . $e->getMessage());
        }
    }

    private function sendEarlyResponse(): void {
        ignore_user_abort(true);
        set_time_limit(60);

        http_response_code(200);
        header('Connection: close');
        header('Content-Length: 0');

        if (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }
}
