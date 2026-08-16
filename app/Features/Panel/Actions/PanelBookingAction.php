<?php
declare(strict_types=1);

namespace App\Features\Panel\Actions;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\PriceCalculator;
use App\Core\Validator;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Panel\Domain\AvailabilityRepository;
use App\Features\Panel\Domain\PanelAuth;
use App\Features\Shared\Ports\PmsPortInterface;
use Exception;

/**
 * Accion ADR: POST /api/panel/booking
 * Crea una reserva manual desde el panel del dueño (walk-in / teléfono /
 * dueño / bloqueo interno con huésped). Escribe la cadena COMPLETA en
 * QloApps via confirmOrder (mismo camino atómico verificado que la venta web:
 * cliente → carrito → orden → booking_detail) con module propio
 * ('panel-walkin') y la habitación FÍSICA elegida — se refleja en el CMS y
 * descuenta disponibilidad. Requiere cookie del panel.
 *
 * Body: { room_id, room_type_id, checkin, checkout, guest_name, guest_email,
 *         guest_phone, guests, price_usd?, channel? }
 */
class PanelBookingAction {
    private PmsPortInterface $pms;
    private ProvisionalBookingRepository $bookingRepo;
    private AvailabilityRepository $panelRepo;

    public function __construct(
        PmsPortInterface $pms,
        ProvisionalBookingRepository $bookingRepo,
        AvailabilityRepository $panelRepo
    ) {
        $this->pms = $pms;
        $this->bookingRepo = $bookingRepo;
        $this->panelRepo = $panelRepo;
    }

    public function __invoke(Request $request): void {
        PanelAuth::requireAuth();

        $body = $request->getBody() ?? [];
        $roomId = (int)($body['room_id'] ?? 0);
        $roomTypeId = (int)($body['room_type_id'] ?? 0);
        $checkin = (string)($body['checkin'] ?? '');
        $checkout = (string)($body['checkout'] ?? '');
        $guestName = trim((string)($body['guest_name'] ?? ''));
        $guestEmail = trim((string)($body['guest_email'] ?? ''));
        $guestPhone = trim((string)($body['guest_phone'] ?? ''));
        $guests = (int)($body['guests'] ?? 2);
        $priceUsd = isset($body['price_usd']) && $body['price_usd'] !== '' ? (float)$body['price_usd'] : null;
        $channel = in_array($body['channel'] ?? '', ['walkin', 'phone', 'ota', 'owner'], true)
            ? (string)$body['channel']
            : 'walkin';

        if ($roomId < 1 || $roomTypeId < 1) {
            Response::badRequest('Habitación y tipo requeridos.');
        }
        if ($guestName === '' || $guestEmail === '') {
            Response::badRequest('Nombre y email del huésped requeridos.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkin) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkout)
            || strtotime($checkout) <= strtotime($checkin)) {
            Response::badRequest('Fechas inválidas (checkout debe ser posterior a checkin).');
        }
        if ($guests < 1 || $guests > 8) {
            Response::badRequest('Número de huéspedes inválido.');
        }

        // Precio: el que manda el recepcionista (puede negociar walk-in), o el
        // del tipo × noches si no lo envía. Siempre ≥ 1 USD.
        $nights = (int)round((strtotime($checkout) - strtotime($checkin)) / 86400);
        if ($priceUsd === null || $priceUsd < 1) {
            $types = $this->panelRepo->getRoomAvailability($checkin, $checkout);
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

        try {
            // 1. Hold local PAID (descuenta disponibilidad web inmediatamente;
            //    confirmOrder lo necesita como fuente de guest_data/room_data).
            $cartId = 'USGAR-' . bin2hex(random_bytes(6));
            $exchangeRate = $this->pms->getExchangeRatePEN();
            $pricePen = PriceCalculator::toGatewayPrice($priceUsd, $exchangeRate);

            $roomName = '';
            $types = $this->panelRepo->getRoomAvailability($checkin, $checkout);
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
                'id_hotel'                => (int)Config::get('DEFAULT_HOTEL_ID', '1'),
                'id_room_type'            => $roomTypeId,
                'guest_data'              => [
                    'name'   => $guestName,
                    'email'  => $guestEmail,
                    'phone'  => $guestPhone !== '' ? $guestPhone : null,
                    'guests' => $guests,
                    'locale' => 'es',
                ],
                'room_data'               => [[
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
                'status'                  => 'paid',
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
                'panel-walkin',
                'Panel (walk-in)'
            );
            if ($orderId === null) {
                throw new Exception('confirmOrder devolvió null para la reserva manual.');
            }

            Response::json([
                'success'  => true,
                'order_id' => $orderId,
                'cart_id'  => $cartId,
                'price_usd' => $priceUsd,
                'message'  => 'Reserva manual creada y confirmada en QloApps.',
            ]);
        } catch (Exception $e) {
            Logger::error('PanelBookingAction Error: ' . $e->getMessage());
            Response::error('No se pudo crear la reserva manual.', 500, 'PANEL_BOOKING_FAILED');
        }
    }
}
