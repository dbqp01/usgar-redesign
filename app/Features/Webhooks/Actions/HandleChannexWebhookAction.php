<?php
declare(strict_types=1);

namespace App\Features\Webhooks\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Config;
use App\Core\Database;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\ChannexRoomMapper;
use App\Features\Shared\Ports\ChannelManagerPortInterface;
use App\Features\Shared\Adapters\ChannexAdapter;
use PDO;
use Exception;

/**
 * Accion ADR: POST /api/webhook/channex
 * Recibe notificaciones de reservas que ingresan desde OTAs a traves de Channex.
 */
class HandleChannexWebhookAction {
    private PDO $pdo;
    private ChannexRoomMapper $roomMapper;
    private ProvisionalBookingRepository $bookingRepo;
    private ChannelManagerPortInterface $channexAdapter;

    public function __construct(
        ?PDO $pdo = null,
        ?ChannexRoomMapper $roomMapper = null,
        ?ProvisionalBookingRepository $bookingRepo = null,
        ?ChannelManagerPortInterface $channexAdapter = null
    ) {
        $db = Database::getInstance();
        $this->pdo = $pdo ?? $db->getConnection();
        $this->roomMapper = $roomMapper ?? new ChannexRoomMapper();
        $this->bookingRepo = $bookingRepo ?? new ProvisionalBookingRepository($this->pdo);
        $this->channexAdapter = $channexAdapter ?? new ChannexAdapter();
    }

    public function __invoke(Request $request): void {
        $body = $request->getBody() ?? [];
        $event = $body['event'] ?? ($body['type'] ?? 'booking');
        $payload = $body['payload'] ?? $body;

        Logger::info("HandleChannexWebhookAction: Evento recibido [{$event}]");

        $channexSecret = Config::get('CHANNEX_WEBHOOK_SECRET');
        if (!empty($channexSecret)) {
            $headerSecret = $request->getHeader('x-channex-secret');
            if (empty($headerSecret) || !hash_equals($channexSecret, $headerSecret)) {
                Logger::error("HandleChannexWebhookAction: Secreto de webhook Channex invalido o ausente.");
                Response::unauthorized('Invalid Channex webhook secret header.');
                return;
            }
        } elseif (Config::isProduction()) {
            Logger::error("HandleChannexWebhookAction: CHANNEX_WEBHOOK_SECRET no esta configurado en entorno de produccion.");
            Response::unauthorized('Channex webhook secret not configured in production.');
            return;
        }

        $revisionId = $payload['revision_id'] ?? ($body['revision_id'] ?? null);
        $bookingData = $payload['booking'] ?? $payload;

        // Si se provee revision_id y los detalles de la reserva no estan embebidos, se consulta a la API de Channex
        if ($revisionId && (!isset($bookingData['arrival_date']) && !isset($bookingData['id']))) {
            $fetchedRevision = $this->channexAdapter->fetchBookingRevision((string)$revisionId);
            if (is_array($fetchedRevision)) {
                $bookingData = $fetchedRevision['booking'] ?? $fetchedRevision;
            }
        }

        $reservationId = $bookingData['id'] ?? ($bookingData['reservation_id'] ?? ($payload['booking_id'] ?? null));
        $status = $bookingData['status'] ?? 'new';

        if (!$reservationId) {
            Response::json(['success' => true, 'message' => 'Notification processed without reservation ID.']);
            return;
        }

        $arrivalDate   = $bookingData['arrival_date'] ?? date('Y-m-d');
        $departureDate = $bookingData['departure_date'] ?? date('Y-m-d', strtotime('+1 day'));
        $otaName       = $bookingData['ota_name'] ?? 'OTA-Channex';
        $customer      = $bookingData['customer'] ?? [];
        $guestName     = trim(($customer['name'] ?? 'Huesped') . ' ' . ($customer['surname'] ?? 'OTA'));
        $guestEmail    = $customer['mail'] ?? Config::get('OTA_DEFAULT_EMAIL', 'guest@ota.com');
        $guestPhone    = $customer['phone'] ?? '';

        $idRoomType = $this->roomMapper->resolveRoomTypeId($bookingData);

        Logger::info("HandleChannexWebhookAction: Reserva OTA [{$reservationId}] de {$otaName} ({$guestName}) para {$arrivalDate} -> {$departureDate} | RoomType ID: {$idRoomType}");

        try {
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
                'expires_at'     => date('Y-m-d H:i:s', strtotime('+1 year')),
            ];

            $this->bookingRepo->create($holdData);
            Logger::info("HandleChannexWebhookAction: Sincronizado inventario de {$otaName} en BD local con Cart ID {$cartId}");

            // Si vino un revision_id, se confirma mediante ACK para no perderla en la ventana de 30 minutos
            if ($revisionId) {
                $this->channexAdapter->acknowledgeRevision((string)$revisionId);
            }
        } catch (Exception $e) {
            Logger::error("HandleChannexWebhookAction Exception al sincronizar: " . $e->getMessage());
        }

        Response::json([
            'success'        => true,
            'reservation_id' => $reservationId,
            'status'         => 'processed',
        ]);
    }
}
