<?php
declare(strict_types=1);

namespace App\Features\Panel\Actions;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\PriceCalculator;
use App\Core\BookingStatus;
use App\Core\HttpException;
use App\Core\Validator;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Panel\Domain\AvailabilityRepository;
use App\Features\Panel\Domain\PanelAuth;
use App\Features\Shared\Ports\PmsPortInterface;
use PDO;
use Exception;
use Throwable;

/**
 * Accion ADR: POST /api/panel/booking
 * Crea una reserva manual desde el panel del dueño (walk-in / teléfono /
 * dueño / bloqueo interno con huésped). Escribe la cadena COMPLETA en
 * QloApps via confirmOrder (mismo camino atómico verificado que la venta web:
 * cliente → carrito → orden → booking_detail) con module propio
 * ('panel-{channel}') y la habitación FÍSICA elegida — se refleja en el CMS y
 * descuenta disponibilidad. Requiere cookie del panel.
 *
 * Body: { room_id, room_type_id, checkin, checkout, guest_name, guest_email,
 *         guest_phone, guests, price_usd?, channel? }
 */
class PanelBookingAction {
    private PmsPortInterface $pms;
    private ProvisionalBookingRepository $bookingRepo;
    private AvailabilityRepository $panelRepo;
    private PDO $pdo;

    public function __construct(
        PmsPortInterface $pms,
        ProvisionalBookingRepository $bookingRepo,
        AvailabilityRepository $panelRepo,
        PDO $pdo
    ) {
        $this->pms = $pms;
        $this->bookingRepo = $bookingRepo;
        $this->panelRepo = $panelRepo;
        $this->pdo = $pdo;
    }

    public function __invoke(Request $request): void {
        PanelAuth::requireAuth();
        PanelAuth::assertCsrf($request);

        $body = $request->getBody() ?? [];
        $roomId = (int)($body['room_id'] ?? 0);
        $roomTypeId = (int)($body['room_type_id'] ?? 0);
        $checkin = (string)($body['checkin'] ?? '');
        $checkout = (string)($body['checkout'] ?? '');
        $guestName = trim((string)($body['guest_name'] ?? ''));
        $guestEmail = trim((string)($body['guest_email'] ?? ''));
        $guestPhone = trim((string)($body['guest_phone'] ?? ''));
        $guests = (int)($body['guests'] ?? 2);
        $priceUsd = null;
        $maxPanelPrice = (float)(Config::get('PANEL_MAX_PRICE_USD', '100000') ?? '100000');
        if (!is_finite($maxPanelPrice) || $maxPanelPrice <= 0) {
            $maxPanelPrice = 100000.0;
        }
        if (array_key_exists('price_usd', $body) && $body['price_usd'] !== '') {
            $rawPrice = $body['price_usd'];
            if (!is_scalar($rawPrice) || !is_numeric($rawPrice)) {
                throw HttpException::badRequest('price_usd debe ser un número válido.');
            }
            $priceUsd = (float)$rawPrice;
            if (!is_finite($priceUsd) || $priceUsd < 1 || $priceUsd > $maxPanelPrice) {
                throw HttpException::badRequest('price_usd debe estar entre 1 y ' . $maxPanelPrice . ' USD.');
            }
        }
        $channel = in_array($body['channel'] ?? '', ['walkin', 'phone', 'ota', 'owner'], true)
            ? (string)$body['channel']
            : 'walkin';
        $hotelId = (int)Config::get('DEFAULT_HOTEL_ID', '1');

        if ($roomId < 1 || $roomTypeId < 1) {
            Response::badRequest('Habitación y tipo requeridos.');
        }
        if ($guestName === '' || $guestEmail === '') {
            Response::badRequest('Nombre y email del huésped requeridos.');
        }
        if (mb_strlen($guestName) > 120) {
            throw HttpException::badRequest('El nombre del huésped no puede superar 120 caracteres.');
        }
        $guestEmail = Validator::email($guestEmail);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkin) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkout)
            || !checkdate((int)substr($checkin, 5, 2), (int)substr($checkin, 8, 2), (int)substr($checkin, 0, 4))
            || !checkdate((int)substr($checkout, 5, 2), (int)substr($checkout, 8, 2), (int)substr($checkout, 0, 4))
            || strtotime($checkout) <= strtotime($checkin)) {
            Response::badRequest('Fechas inválidas (checkout debe ser posterior a checkin).');
        }
        if ($guests < 1 || $guests > 8) {
            Response::badRequest('Número de huéspedes inválido.');
        }

        $room = $this->panelRepo->findRoomForBooking($roomId, $roomTypeId, $hotelId);
        if ($room === null) {
            Response::badRequest('La habitación no pertenece al tipo o hotel seleccionado.');
        }
        if (!$this->panelRepo->isRoomAvailable($roomId, $checkin, $checkout, $hotelId)) {
            Response::badRequest('La habitación ya no está disponible para esas fechas.');
        }

        // Precio: el que manda el recepcionista (puede negociar walk-in), o el
        // del tipo × noches si no lo envía. Siempre ≥ 1 USD.
        $nights = (int)round((strtotime($checkout) - strtotime($checkin)) / 86400);
        if ($priceUsd === null) {
            $types = $this->panelRepo->getRoomAvailability($checkin, $checkout, $hotelId);
            $priceUsd = 0.0;
            foreach ($types['types'] as $t) {
                if ((int)$t['id_room_type'] === $roomTypeId) {
                    $priceUsd = (float)$t['price'];
                    break;
                }
            }
            if ($priceUsd < 1) {
                Response::badRequest('No se pudo resolver el precio del tipo; envía price_usd.');
            }
            $priceUsd = $priceUsd * $nights;
        }
        if (!is_finite($priceUsd) || $priceUsd < 1 || $priceUsd > $maxPanelPrice) {
            throw HttpException::badRequest('El precio de la reserva debe estar entre 1 y ' . $maxPanelPrice . ' USD.');
        }

        $cartId = '';
        $orderId = null;
        try {
            if (!$this->bookingRepo->ensureTablesExist()) {
                throw new Exception('No se pudo preparar el esquema local de reservas.');
            }
            $this->pdo->beginTransaction();
            if (!$this->bookingRepo->lockRoom($hotelId . ':' . $roomTypeId)
                || !$this->panelRepo->isRoomAvailable($roomId, $checkin, $checkout, $hotelId)) {
                throw HttpException::badRequest('La habitación ya no está disponible para esas fechas.');
            }

            // 1. Hold local PENDING (descuenta disponibilidad web mientras
            //    confirmOrder escribe la orden PMS; luego pasa a paid).
            $cartId = 'USGAR-' . bin2hex(random_bytes(6));
            $exchangeRate = $this->pms->getExchangeRatePEN();
            $pricePen = PriceCalculator::toGatewayPrice($priceUsd, $exchangeRate);

            $roomName = '';
            $types = $this->panelRepo->getRoomAvailability($checkin, $checkout, $hotelId);
            foreach ($types['types'] as $t) {
                if ((int)$t['id_room_type'] === $roomTypeId) {
                    $roomName = (string)$t['name'];
                    foreach ($t['rooms'] as $r) {
                        if ((int)$r['room_id'] === $roomId) {
                            $roomName .= ' ' . $r['room_num'];
                        }
                    }
                    break;
                }
            }

            $holdData = [
                'cart_id'                 => $cartId,
                'user_id'                 => null,
                'id_hotel'                => $hotelId,
                'id_room_type'            => $roomTypeId,
                'guest_data'              => [
                    'name'   => $guestName,
                    'email'  => $guestEmail,
                    'phone'  => $guestPhone !== '' ? $guestPhone : null,
                    'guests' => $guests,
                    'locale' => 'es',
                    'channel' => $channel,
                ],
                'room_data'               => [[
                    'id_room_type'    => $roomTypeId,
                    'id_product'      => (int)$room['id_product'],
                    'id_room'         => $roomId,
                    'room_name'       => $roomName !== '' ? $roomName : 'Habitación',
                    'price_per_night' => $nights > 0 ? round($priceUsd / $nights, 2) : 0,
                    'nights'          => $nights,
                    'rate_type'       => 'standard',
                    'guests'          => $guests,
                    'base_occupancy'  => $guests,
                    'extra_guests'    => 0,
                    'extra_charge'    => 30,
                    'room_total'      => $priceUsd,
                ]],
                'price_snapshot'          => $priceUsd,
                'price_snapshot_pen'      => $pricePen,
                'exchange_rate_snapshot'  => $exchangeRate,
                'checkin'                 => $checkin,
                'checkout'                => $checkout,
                // Keep the local hold pending until the PMS write succeeds;
                // a failed manual booking must not leave paid inventory behind.
                'status'                  => BookingStatus::Pending->value,
                'expires_at'              => date('Y-m-d H:i:s', strtotime('+30 days')),
            ];
            if (!$this->bookingRepo->create($holdData)) {
                throw new Exception('Fallo al insertar el hold local de la reserva manual.');
            }

            // 2. Cadena completa en QloApps (confirmOrder atómico) con la
            //    habitación física elegida y module propio del panel.
            $orderId = $this->pms->confirmOrder(
                $cartId,
                $pricePen,
                $guestName,
                $guestEmail,
                $roomId,
                'panel-' . $channel,
                'Panel (' . $channel . ')'
            );
            if ($orderId === null) {
                throw new Exception('confirmOrder devolvió null para la reserva manual.');
            }
            if (!$this->bookingRepo->updateStatus($cartId, BookingStatus::Paid->value)) {
                throw new Exception('No se pudo marcar como pagada la reserva manual.');
            }

            $this->pdo->commit();

            Response::json([
                'success'  => true,
                'order_id' => $orderId,
                'cart_id'  => $cartId,
                'price_usd' => $priceUsd,
                'message'  => 'Reserva manual creada y confirmada en QloApps.',
            ]);
        } catch (HttpException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } catch (Throwable $e) {
            // Null means no PMS order was returned: rollback the outer tx.
            // A returned order id requires durable compensation instead.
            if ($this->pdo->inTransaction()) {
                if ($orderId === null) {
                    $this->pdo->rollBack();
                } else {
                    try {
                        $this->pdo->commit();
                    } catch (Throwable $commitError) {
                        try {
                            $this->pdo->rollBack();
                        } catch (Throwable $rollbackError) {
                            Logger::error('PanelBookingAction rollback after ambiguous commit failed: ' . $rollbackError->getMessage());
                        }
                        Logger::error('PanelBookingAction ambiguous commit after PMS order: ' . $commitError->getMessage());
                    }
                }
            }
            if ($orderId !== null && $cartId !== '') {
                if (!$this->bookingRepo->updateStatus($cartId, BookingStatus::ManualReview->value)) {
                    // Do not hide a failed compensation transition: the
                    // request remains 500 and the durable PMS order plus cart
                    // id are logged for a safe manual retry.
                    Logger::error(
                        'PanelBookingAction: no se pudo marcar manual_review; retry requerido',
                        ['cart_id' => $cartId, 'order_id' => $orderId]
                    );
                }
            }
            Logger::error('PanelBookingAction Error: ' . $e->getMessage());
            Response::error('No se pudo crear la reserva manual.', 500, 'PANEL_BOOKING_FAILED');
        }
    }
}
