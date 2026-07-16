<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Database;
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
            // Retornar 200 OK para notificaciones que no nos interesen (ej. test webhooks, cobros, etc.)
            Response::json(['success' => true, 'message' => 'Notification ignored.']);
        }

        // 1. Validar la firma digital HMAC-SHA256 (Seguridad contra suplantación)
        $signatureHeader = $request->getHeader('x-signature') ?? '';
        $requestId = $request->getHeader('x-request-id') ?? '';
        
        $db = Database::getInstance();
        $hasSecret = !empty($db->getEnv('MERCADO_PAGO_WEBHOOK_SECRET'));

        if ($hasSecret && !$isMock) {
            if (!$this->mp->verifySignature($signatureHeader, $requestId, (string)$paymentId)) {
                Logger::error("WebhookController: Firma inválida detectada para Pago ID {$paymentId}");
                Response::unauthorized('Firma de webhook inválida.');
            }
        }

        // 2. Enviar respuesta rápida (Flush) a Mercado Pago.
        // Evita que Mercado Pago reintente enviar la misma notificación por lentitud en procesamiento.
        if (!$isMock) {
            $this->sendEarlyResponse();
        }

        // 3. Obtener detalles del pago directamente desde Mercado Pago
        $paymentDetails = null;
        if ($isMock && !$hasSecret) {
            $paymentDetails = [
                'status' => 'approved',
                'transaction_amount' => (float)$request->getQuery('amount', '90.0'),
                'external_reference' => $request->getQuery('bookingId', 'MOCK-CART-' . time())
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

            // Buscar el bloqueo temporal
            $hold = $this->bookingModel->getByCartId($cartId);
            if (!$hold) {
                Logger::error("WebhookController Error: No se encontró hold para Cart ID {$cartId} en base de datos.");
                $this->pdo->rollBack();
                exit(0);
            }

            if ($hold['status'] === 'paid') {
                Logger::info("WebhookController: Reserva para Cart ID {$cartId} ya fue procesada anteriormente.");
                $this->pdo->rollBack();
                exit(0);
            }

            // A. Cambiar estado local a 'paid'
            $this->bookingModel->updateStatus($cartId, 'paid');

            // B. Convertir carrito en una Orden confirmada en QloApps
            $guestName = $hold['guest_data']['name'] ?? '';
            $guestEmail = $hold['guest_data']['email'] ?? '';
            $guestPhone = $hold['guest_data']['phone'] ?? '';
            
            $qloOrderId = $this->qloApp->confirmOrder($cartId, $amount, $guestName, $guestEmail);
            
            if (!$qloOrderId) {
                throw new Exception("Fallo en QloAppService confirmOrder para Cart ID {$cartId}");
            }

            // C. Sincronizar reserva en Channex (OTAs)
            $channexSynced = $this->channex->createBooking(
                $qloOrderId,
                $hold['checkin'],
                $hold['checkout'],
                (int)$hold['id_room_type'],
                $amount,
                $guestName,
                $guestEmail,
                $guestPhone
            );

            if (!$channexSynced) {
                Logger::error("WebhookController Warning: La sincronización OTA con Channex falló para la Orden {$qloOrderId}");
            }

            $this->pdo->commit();
            Logger::info("WebhookController Success: Pago {$paymentId} procesado. Reserva local confirmada y Orden QloApps {$qloOrderId} creada.");

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Logger::error("WebhookController Exception al confirmar reserva: " . $e->getMessage());
        }

        exit(0);
    }

    /**
     * Responde a Mercado Pago cerrando la conexión HTTP para liberar el canal y continuar en segundo plano.
     */
    private function sendEarlyResponse(): void {
        ignore_user_abort(true);
        set_time_limit(60); // 1 minuto límite para procesamiento posterior
        
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
