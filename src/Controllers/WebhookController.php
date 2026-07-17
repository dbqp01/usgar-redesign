<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\BookingStatus;
use App\Models\ProvisionalBooking;
use App\Services\QloAppService;
use App\Services\MercadoPagoService;
use App\Services\ChannexService;
use PDO;
use Exception;

/**
 * Controlador de Webhooks.
 * Procesa notificaciones asíncronas de Mercado Pago, valida firmas de seguridad,
 * confirma reservas en QloApps y sincroniza con Channex.
 *
 * Fix: En producción, rechazar webhooks si MERCADO_PAGO_WEBHOOK_SECRET no está configurado.
 */
class WebhookController {
    private ?PDO $pdo;
    private QloAppService $qloApp;
    private MercadoPagoService $mp;
    private ChannexService $channex;
    private ?ProvisionalBooking $bookingModel = null;

    public function __construct(
        ?PDO $pdo = null,
        ?QloAppService $qloApp = null,
        ?MercadoPagoService $mp = null,
        ?ChannexService $channex = null
    ) {
        $db = Database::getInstance();
        $this->pdo = $pdo ?? $db->getConnection();

        $this->qloApp = $qloApp ?? new QloAppService($this->pdo);
        $this->mp = $mp ?? new MercadoPagoService();
        $this->channex = $channex ?? new ChannexService();

        if ($this->pdo) {
            $this->bookingModel = new ProvisionalBooking($this->pdo);
        }
    }

    /**
     * Endpoint: POST /api/webhook
     * Recibe notificaciones de pago IPN de Mercado Pago.
     */
    public function handle(Request $request): void {
        $body = $request->getBody() ?? [];

        // Obtener tipo de evento e ID del recurso
        $type = $body['type'] ?? ($body['topic'] ?? null);
        $paymentId = $body['data']['id'] ?? ($body['id'] ?? null);

        // Manejar pruebas locales o simulación sandbox
        $isMock = $request->getQuery('mock') === 'true';

        if (($type !== 'payment' || !$paymentId) && !$isMock) {
            // Retornar 200 OK para notificaciones que no nos interesen
            Response::json(['success' => true, 'message' => 'Notification ignored.']);
        }

        // 1. Validar la firma digital HMAC-SHA256 (Seguridad contra suplantación)
        $webhookSecret = Config::get('MERCADO_PAGO_WEBHOOK_SECRET', '');

        if (!$isMock) {
            if (empty($webhookSecret)) {
                if (Config::isProduction()) {
                    // En producción, RECHAZAR si no hay secret configurado
                    Logger::error('WebhookController: MERCADO_PAGO_WEBHOOK_SECRET no configurado en producción.');
                    Response::error('Webhook security not configured.', 500);
                }
                // En desarrollo, solo advertir
                Logger::warning('WebhookController: Webhook Secret ausente. Saltando validación en desarrollo.');
            } else {
                $signatureHeader = $request->getHeader('x-signature') ?? '';
                $requestId = $request->getHeader('x-request-id') ?? '';

                if (!$this->mp->verifySignature($signatureHeader, $requestId, (string)$paymentId)) {
                    Logger::error("WebhookController: Firma inválida detectada para Pago ID {$paymentId}");
                    Response::unauthorized('Firma de webhook inválida.');
                }
            }
        }

        // 2. Enviar respuesta rápida (Flush) a Mercado Pago
        if (!$isMock) {
            $this->sendEarlyResponse();
        }

        // 3. Obtener detalles del pago
        $paymentDetails = null;
        if ($isMock && empty($webhookSecret)) {
            $paymentDetails = [
                'status'             => 'approved',
                'transaction_amount' => (float)$request->getQuery('amount', '90.0'),
                'external_reference' => $request->getQuery('bookingId', 'MOCK-CART-' . time()),
            ];
        } else {
            $paymentDetails = $this->mp->getPaymentDetails((string)$paymentId);
        }

        if (!$paymentDetails) {
            Logger::error("WebhookController Error: No se pudieron obtener detalles para Pago ID {$paymentId}");
            exit(0);
        }

        $status = $paymentDetails['status'] ?? 'pending';
        $cartId = $paymentDetails['external_reference'] ?? null;
        $amount = (float)($paymentDetails['transaction_amount'] ?? 0.0);

        if ($status !== 'approved' || !$cartId) {
            Logger::info("WebhookController: Pago ID {$paymentId} tiene estado {$status}. Omitiendo creación de orden.");
            exit(0);
        }

        if (!$this->pdo || !$this->bookingModel) {
            Logger::error("WebhookController Error: Base de datos desconectada. No se pudo procesar Cart ID {$cartId}");
            exit(0);
        }

        // 4. Confirmar y Procesar Reserva en Base de Datos
        try {
            $this->pdo->beginTransaction();

            $hold = $this->bookingModel->getByCartId($cartId);
            if (!$hold) {
                Logger::error("WebhookController Error: No se encontró hold para Cart ID {$cartId}");
                $this->pdo->rollBack();
                exit(0);
            }

            $holdStatus = BookingStatus::tryFrom($hold['status']);
            if ($holdStatus === BookingStatus::Paid) {
                Logger::info("WebhookController: Reserva para Cart ID {$cartId} ya fue procesada anteriormente.");
                $this->pdo->rollBack();
                exit(0);
            }

            // A. Cambiar estado local a 'paid'
            $this->bookingModel->updateStatus($cartId, BookingStatus::Paid->value);

            // B. Convertir carrito en una Orden confirmada en QloApps
            $guestName  = $hold['guest_data']['name'] ?? '';
            $guestEmail = $hold['guest_data']['email'] ?? '';
            $guestPhone = $hold['guest_data']['phone'] ?? '';

            $qloOrderId = $this->qloApp->confirmOrder($cartId, $amount, $guestName, $guestEmail);

            if (!$qloOrderId) {
                throw new Exception("Fallo en QloAppService confirmOrder para Cart ID {$cartId}");
            }

            // C. Sincronizar reserva en Channex (OTAs)
            $maxGuests = (int)($hold['room_data']['max_guests'] ?? 2);
            $channexSynced = $this->channex->createBooking(
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
                Logger::warning("WebhookController: La sincronización OTA con Channex falló para Orden {$qloOrderId}");
            }

            $this->pdo->commit();
            Logger::info("WebhookController Success: Pago {$paymentId} procesado. Orden QloApps {$qloOrderId} creada.");

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Logger::error('WebhookController Exception al confirmar reserva: ' . $e->getMessage());
        }

        exit(0);
    }

    /**
     * Responde a Mercado Pago cerrando la conexión HTTP para continuar en segundo plano.
     */
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
