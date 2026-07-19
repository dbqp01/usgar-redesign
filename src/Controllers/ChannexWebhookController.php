<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Config;
use App\Models\ProvisionalBooking;
use App\Services\QloAppService;
use App\Services\ChannexRoomMapper;
use PDO;
use Exception;

/**
 * Controlador de Webhooks de Channex (Channel Manager).
 * Recibe notificaciones de reservas que ingresan desde OTAs (Booking.com, Airbnb, Expedia)
 * y sincroniza el inventario bloqueando las habitaciones en QloApps / MySQL local.
 * Refactorizado: Validación estricta de firma HMAC/Secret, mapeo dinámico SOLID de habitación y SHA-256.
 */
class ChannexWebhookController {
    private ?PDO $pdo;
    private QloAppService $qloApp;
    private ChannexRoomMapper $roomMapper;
    private ?ProvisionalBooking $bookingModel = null;

    public function __construct(
        ?PDO $pdo = null,
        ?QloAppService $qloApp = null,
        ?ChannexRoomMapper $roomMapper = null
    ) {
        $db = \App\Core\Database::getInstance();
        $this->pdo = $pdo ?? $db->getConnection();
        $this->qloApp = $qloApp ?? new QloAppService($this->pdo);
        $this->roomMapper = $roomMapper ?? new ChannexRoomMapper();

        if ($this->pdo) {
            $this->bookingModel = new ProvisionalBooking($this->pdo);
        }
    }

    /**
     * Endpoint: POST /api/webhook/channex
     * Recibe la reserva entrante desde Channex u OTA.
     */
    public function handle(Request $request): void {
        $body = $request->getBody() ?? [];
        $event = $body['event'] ?? ($body['type'] ?? 'booking');
        $payload = $body['payload'] ?? $body;

        Logger::info("ChannexWebhookController: Evento recibido [{$event}]");

        // 1. Validar secret/firma de cabecera de Channex de forma estricta (SEC-01)
        $channexSecret = Config::get('CHANNEX_WEBHOOK_SECRET');
        if (!empty($channexSecret)) {
            $headerSecret = $request->getHeader('x-channex-secret');
            if (empty($headerSecret) || !hash_equals($channexSecret, $headerSecret)) {
                Logger::error("ChannexWebhookController: Secreto de webhook Channex inválido o ausente.");
                Response::unauthorized('Invalid Channex webhook secret header.');
            }
        } elseif (Config::isProduction()) {
            Logger::error("ChannexWebhookController: CHANNEX_WEBHOOK_SECRET no está configurado en entorno de producción.");
            Response::unauthorized('Channex webhook secret not configured in production.');
        }

        $bookingData = $payload['booking'] ?? $payload;
        $reservationId = $bookingData['id'] ?? ($bookingData['reservation_id'] ?? null);
        $status = $bookingData['status'] ?? 'new';

        if (!$reservationId) {
            Response::json(['success' => true, 'message' => 'Notification processed without reservation ID.']);
        }

        $arrivalDate = $bookingData['arrival_date'] ?? date('Y-m-d');
        $departureDate = $bookingData['departure_date'] ?? date('Y-m-d', strtotime('+1 day'));
        $otaName = $bookingData['ota_name'] ?? 'OTA-Channex';
        $customer = $bookingData['customer'] ?? [];
        $guestName = trim(($customer['name'] ?? 'Huésped') . ' ' . ($customer['surname'] ?? 'OTA'));
        $guestEmail = $customer['mail'] ?? 'guest@ota.com';
        $guestPhone = $customer['phone'] ?? '';

        // 2. Mapeo dinámico de id_room_type según el payload de la reserva (SEC-02 + SOLID)
        $idRoomType = $this->roomMapper->resolveRoomTypeId($bookingData);

        Logger::info("ChannexWebhookController: Reserva OTA [{$reservationId}] de {$otaName} ({$guestName}) para {$arrivalDate} -> {$departureDate} | RoomType ID: {$idRoomType}");

        if ($this->pdo && $this->bookingModel) {
            try {
                // 3. Generación de cartId usando SHA-256 (SEC-05)
                $hashId = strtoupper(substr(hash('sha256', (string)$reservationId), 0, 12));
                $cartId = 'OTA-' . $hashId;
                
                $holdData = [
                    'cart_id'       => $cartId,
                    'id_hotel'      => 1,
                    'id_room_type'  => $idRoomType,
                    'guest_data'    => [
                        'name'     => $guestName,
                        'email'    => $guestEmail,
                        'phone'    => $guestPhone,
                        'ota_name' => $otaName,
                    ],
                    'room_data'     => [
                        'room_name'       => $bookingData['room_name'] ?? "Reserva OTA ({$otaName})",
                        'price_per_night' => (float)($bookingData['amount'] ?? 0),
                        'nights'          => (int)max(1, round((strtotime($departureDate) - strtotime($arrivalDate)) / 86400)),
                    ],
                    'price_snapshot' => (float)($bookingData['amount'] ?? 0),
                    'checkin'        => $arrivalDate,
                    'checkout'       => $departureDate,
                    'status'         => ($status === 'cancelled') ? 'cancelled' : 'paid',
                    'expires_at'     => date('Y-m-d H:i:s', strtotime('+1 year')), // Hold permanente para reservas pagadas en OTA
                ];

                $this->bookingModel->create($holdData);
                Logger::info("ChannexWebhookController: Sincronizado inventario de {$otaName} en BD local con Cart ID {$cartId}");
            } catch (Exception $e) {
                Logger::error("ChannexWebhookController Exception al sincronizar: " . $e->getMessage());
            }
        }

        Response::json([
            'success'        => true,
            'reservation_id' => $reservationId,
            'status'         => 'processed',
        ]);
    }
}
