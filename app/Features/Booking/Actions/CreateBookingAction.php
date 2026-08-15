<?php
declare(strict_types=1);

namespace App\Features\Booking\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Config;
use App\Core\Validator;
use App\Core\HttpException;
use App\Core\BookingStatus;
use App\Core\BookingHoldToken;
use App\Core\PriceCalculator;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Ports\PmsPortInterface;
use App\Features\Shared\RoomTypeRegistry;
use App\Features\Auth\SessionService;
use PDO;
use Exception;

/**
 * Accion ADR: POST /api/booking
 * Crea un bloqueo temporal en QloApps (1 o varias habitaciones) y devuelve los
 * datos para el pago con Custom Checkout (Checkout API) desde el cliente.
 *
 * Contrato (2026-08-12, multi-room): body con `rooms[]` (list de
 * {slug|id_room_type, guests}) o `roomSlug` legacy (1 habitacion). Precios
 * SIEMPRE resueltos server-side (nunca del cliente). Tarifa (rateType) global
 * por reserva; cargo por huesped adicional a precio completo (decision del
 * negocio: -10% no reembolsable solo sobre el base de cada habitacion).
 */
class CreateBookingAction {
    private PDO $pdo;
    private PmsPortInterface $pms;
    private ProvisionalBookingRepository $bookingRepo;

    public function __construct(
        PDO $pdo,
        PmsPortInterface $pms,
        ProvisionalBookingRepository $bookingRepo
    ) {
        $this->pdo = $pdo;
        $this->pms = $pms;
        $this->bookingRepo = $bookingRepo;
    }

    public function __invoke(Request $request): void {
        $body = $request->getBody() ?? [];

        // --- Normalizacion Adaptativa de Payload (Zero-Breakage) ---
        if (isset($body['guestDetails']) && is_array($body['guestDetails'])) {
            $gd = $body['guestDetails'];
            if (empty($body['guestName'])) {
                $body['guestName'] = trim(($gd['firstName'] ?? '') . ' ' . ($gd['lastName'] ?? ''));
            }
            if (empty($body['guestEmail'])) {
                $body['guestEmail'] = $gd['email'] ?? '';
            }
            if (empty($body['guestPhone'])) {
                $body['guestPhone'] = $gd['phone'] ?? '';
            }
            // FIX 2026-08-14 (perdida de datos): el pickup del aeropuerto
            // (airport-transfer + hora de vuelo) se mandaba en
            // guestDetails.specialRequests pero se DESCARTAba aqui — el hold
            // persistia solo name/email/phone y el traslado se perdia (ni la
            // BD ni el PMS se enteraban). Se preserva para guest_data.
            if (empty($body['specialRequests'])) {
                $body['specialRequests'] = trim((string)($gd['specialRequests'] ?? ''));
            }
        }

        // Legacy (1 habitacion): roomSlug o id_room_type -> rooms[] (mismo camino).
        if (empty($body['rooms']) || !is_array($body['rooms'])) {
            if (isset($body['roomSlug'])) {
                $idFromSlug = RoomTypeRegistry::getIdBySlug($body['roomSlug']);
                if ($idFromSlug === null) {
                    throw HttpException::badRequest("Tipo de habitación desconocido: {$body['roomSlug']}.");
                }
                $body['id_room_type'] = $idFromSlug;
            }
            if (isset($body['id_room_type'])) {
                $body['rooms'] = [[
                    'id_room_type' => $body['id_room_type'],
                    'guests'       => (int)($body['guests'] ?? 2),
                ]];
            }
        }

        Validator::requireFields($body, ['checkIn', 'checkOut', 'guestName', 'guestEmail']);
        if (!isset($body['rooms']) || !is_array($body['rooms']) || count($body['rooms']) === 0 || count($body['rooms']) > 3) {
            throw HttpException::badRequest('Debe reservarse entre 1 y 3 habitaciones.');
        }

        $requestedRooms = $body['rooms'];

        $hotelId    = (int)($body['id_hotel'] ?? Config::get('DEFAULT_HOTEL_ID', '1'));
        $checkIn    = $body['checkIn'];
        $checkOut   = $body['checkOut'];
        $guestName  = trim($body['guestName']);
        $guestEmail = Validator::email($body['guestEmail']);
        $guestPhone = trim($body['guestPhone'] ?? '');
        // Tarifa elegida por el cliente. Whitelist cerrada; valor desconocido
        // cae a 'standard' (precio completo) — fail-safe: nunca regalar descuento.
        $requestedRate = $body['rateType'] ?? 'standard';
        $rateType   = in_array($requestedRate, ['standard', 'non_refundable'], true)
            ? $requestedRate
            : 'standard';

        Validator::dateRange($checkIn, $checkOut);

        try {

            $availableRooms = $this->pms->getAvailableRooms($checkIn, $checkOut, $hotelId);
            $nights = (int)max(1, round((strtotime($checkOut) - strtotime($checkIn)) / 86400));

            // --- Resolver cada habitacion: validacion + precio server-side ---
            $resolved = [];   // detalle por room para el hold
            $cartRooms = [];  // {id_product, guests, price} para el webservice
            $totalPriceCents = 0;  // acumulacion en centimos enteros (P1-6)

            foreach ($requestedRooms as $i => $req) {
                if (!is_array($req)) {
                    throw HttpException::badRequest('Formato invalido en rooms[' . $i . '].');
                }

                $idRoomType = isset($req['id_room_type'])
                    ? Validator::positiveInt($req['id_room_type'], "rooms[$i].id_room_type")
                    : RoomTypeRegistry::getIdBySlug((string)($req['slug'] ?? ''));
                if ($idRoomType === null) {
                    throw HttpException::badRequest("Tipo de habitación desconocido en rooms[$i].");
                }

                $guests = Validator::positiveInt($req['guests'] ?? 1, "rooms[$i].guests");

                $targetRoom = null;
                foreach ($availableRooms as $room) {
                    if ((int)$room['id_room_type'] === $idRoomType) {
                        $targetRoom = $room;
                        break;
                    }
                }
                if (!$targetRoom) {
                    throw HttpException::badRequest('Una de las habitaciones seleccionadas ya no está disponible para estas fechas.');
                }

                $maxGuests = (int)($targetRoom['max_guests'] ?? 2);
                if ($guests > $maxGuests) {
                    throw HttpException::badRequest("El número de huéspedes ({$guests}) excede la capacidad máxima de esta habitación ({$maxGuests} personas).");
                }

                $idProduct = (int)($targetRoom['id_product'] ?? $idRoomType);
                $pricePerNight = (float)$targetRoom['price'];
                // Tarifa elegida: el adapter resuelve la regla de QloApps
                // (non_refundable_price); sin regla => == standard (fail-safe).
                $pricePerNight = $rateType === 'non_refundable'
                    ? (float)($targetRoom['non_refundable_price'] ?? $pricePerNight)
                    : $pricePerNight;

                // Cargo por huesped adicional (regla del negocio, verificada en BD
                // real 2026-08-12): +1 persona sobre la ocupancia base (max-1),
                // a PRECIO COMPLETO (el -10% no reembolsable solo aplica al base).
                $baseOccupancy = max(1, $maxGuests - 1);
                $extraGuests = max(0, $guests - $baseOccupancy);
                $extraChargePerNight = (float)Config::get('EXTRA_GUEST_CHARGE_USD', '30');
                $extraChargeTotal = PriceCalculator::extraGuestCharge($guests, $maxGuests, $nights, $extraChargePerNight);

                $roomTotalCents = PriceCalculator::roomTotalCents($pricePerNight, $nights, $extraChargeTotal);
                // Float solo en la frontera (JSON/display); la acumulacion es en centimos (P1-6).
                $roomTotal = $roomTotalCents / 100;
                $totalPriceCents += $roomTotalCents;

                $resolved[] = [
                    'id_room_type'       => $idRoomType,
                    'id_product'         => $idProduct,
                    'room_name'          => $targetRoom['room_name'],
                    'guests'             => $guests,
                    'max_guests'         => $maxGuests,
                    'base_occupancy'     => $baseOccupancy,
                    'extra_guests'       => $extraGuests,
                    'extra_charge'       => $extraChargePerNight,
                    'price_per_night'    => $pricePerNight,
                    'nights'             => $nights,
                    'rate_type'          => $rateType,
                    'room_total'         => $roomTotal,
                    'available_qty'      => (int)($targetRoom['available_qty'] ?? 0),
                ];
                $cartRooms[] = [
                    'id_product' => $idProduct,
                    'guests'     => $guests,
                    'price'      => $roomTotal,
                ];
            }

            $totalPrice = round($totalPriceCents / 100, 2);
            $cartId = $this->pms->createCartMulti($hotelId, $cartRooms, $checkIn, $checkOut, $guestName, $guestEmail, $guestPhone, $totalPrice);

            $this->pdo->beginTransaction();

            // Serializacion de holds por HABITACION (todo 10 / W2): lock por room
            // type dentro de la misma transaccion que el re-check y el INSERT.
            // Orden ascendente de room types para evitar deadlocks entre requests
            // concurrentes con combos cruzados (A+B vs B+A).
            $roomTypeIds = array_unique(array_map(fn(array $r): int => $r['id_room_type'], $resolved));
            sort($roomTypeIds);
            foreach ($roomTypeIds as $idRoomType) {
                if (!$this->bookingRepo->lockRoom($hotelId . ':' . $idRoomType)) {
                    $this->pdo->rollBack();
                    throw new Exception('No se pudo adquirir el lock de serializacion para la habitacion.');
                }
            }

            // Re-check por room type exigiendo la CANTIDAD pedida (2 rooms del
            // mismo tipo => 2 unidades). El available_qty del adapter YA descontó
            // holds existentes; aqui solo se resta lo creado despues de la
            // lectura inicial (ventana lectura->lock, ~ms).
            $readAt = date('Y-m-d H:i:s');
            $neededByType = array_count_values($roomTypeIds);
            foreach ($resolved as &$r) {
                $needed = $neededByType[$r['id_room_type']];
                $newHoldsCount = $this->bookingRepo->getHoldCountForRoomForUpdate($r['id_room_type'], $checkIn, $checkOut, $hotelId, $readAt);
                $r['available_qty'] -= $newHoldsCount;
                if ($r['available_qty'] < $needed) {
                    $this->pdo->rollBack();
                    throw HttpException::badRequest('Una de las habitaciones seleccionadas ya no está disponible para estas fechas.');
                }
            }
            unset($r);

            $expiresAt = date('Y-m-d H:i:s', Config::holdExpirationTimestamp());
            $currentUser = SessionService::getUserFromRequest();

            // Congelar tasa + PEN al cotizar (UNA sola lectura). La tasa sale del
            // PMS (qlo_currency), no de Config (auditoria 2026-08-11).
            $exchangeRate = $this->pms->getExchangeRatePEN();
            $priceSnapshotPen = PriceCalculator::toGatewayPrice($totalPrice, $exchangeRate);

            // room_data pasa a LISTA de habitaciones (multi-room); los consumidores
            // legacy leen el primer room (nights identico para todas). La tarifa
            // es global por reserva ($rateType) — misma para todas las habitaciones.
            $roomDataList = array_map(static function (array $r) use ($rateType): array {
                return [
                    'room_name'       => $r['room_name'],
                    'price_per_night' => $r['price_per_night'],
                    'nights'          => $r['nights'],
                    'rate_type'       => $rateType,
                    'guests'          => $r['guests'],
                    'base_occupancy'  => $r['base_occupancy'],
                    'extra_guests'    => $r['extra_guests'],
                    'extra_charge'    => $r['extra_charge'],
                    'room_total'      => $r['room_total'],
                ];
            }, $resolved);

            $holdData = [
                'cart_id'                 => $cartId,
                'user_id'                 => $currentUser['sub'] ?? null,
                'id_hotel'                => $hotelId,
                'id_room_type'            => $resolved[0]['id_room_type'],
                'guest_data'              => [
                    'name'   => $guestName,
                    'email'  => $guestEmail,
                    'phone'  => $guestPhone,
                    'guests' => array_sum(array_column($resolved, 'guests')),
                    // Idioma del huésped para el email de confirmación (voucher).
                    'locale' => trim((string)($body['locale'] ?? 'en')) ?: 'en',
                    // FIX 2026-08-14: pickup del aeropuerto (hora de vuelo) —
                    // se descartaba antes; ahora persiste y viaja en el evento
                    // booking.paid hasta el PMS (special_requests).
                    'special_requests' => trim((string)($body['specialRequests'] ?? '')),
                ],
                'room_data'               => $roomDataList,
                'price_snapshot'          => $totalPrice,
                'price_snapshot_pen'      => $priceSnapshotPen,
                'exchange_rate_snapshot'  => $exchangeRate,
                'checkin'                 => $checkIn,
                'checkout'                => $checkOut,
                'status'                  => BookingStatus::Pending->value,
                'expires_at'              => $expiresAt,
            ];

            if (!$this->bookingRepo->create($holdData)) {
                throw new Exception('Fallo al insertar el bloqueo de reserva en DB.');
            }

            // Exigencia estricta de variables de entorno sin fallbacks inseguros
            $accessToken = BookingHoldToken::derive($cartId, $guestEmail);

            $this->pdo->commit();

            $timeLeftSeconds = max(0, strtotime($expiresAt) - time());

            // Reutiliza la tasa/PEN congelados (una sola lectura, todo 25).
            $gatewayPricePEN = $priceSnapshotPen;

            $roomSummaries = array_map(static function (array $r): array {
                return [
                    'id_room_type'    => $r['id_room_type'],
                    'slug'            => RoomTypeRegistry::getSlugById($r['id_room_type']),
                    'room_name'       => $r['room_name'],
                    'price_per_night' => $r['price_per_night'],
                    'nights'          => $r['nights'],
                    'guests'          => $r['guests'],
                    'room_total'      => $r['room_total'],
                ];
            }, $resolved);

            Response::json([
                'success'           => true,
                'cart_id'           => $cartId,
                'access_token'      => $accessToken,
                'currency'          => Config::get('HOTEL_BASE_CURRENCY', 'USD'),
                'price'             => $totalPrice,
                'rate_type'         => $rateType,
                'exchange_rate'     => $exchangeRate,
                'gateway_currency'  => Config::get('MERCADO_PAGO_CURRENCY', 'PEN'),
                'gateway_price'     => $gatewayPricePEN,
                'mp_public_key'     => Config::get('PUBLIC_MERCADO_PAGO_PUBLIC_KEY'),
                'expires_at'        => $expiresAt,
                'time_left_seconds' => $timeLeftSeconds,
                // Back-compat: campos legacy = primer room (success flow y
                // payment-status polling esperan shape de 1 habitacion).
                'id_room_type'      => $resolved[0]['id_room_type'],
                'slug'              => RoomTypeRegistry::getSlugById($resolved[0]['id_room_type']),
                'room_name'         => $resolved[0]['room_name'],
                'room_summary'      => $roomSummaries,
            ]);

        } catch (HttpException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Logger::error('CreateBookingAction Exception: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'not configured') || str_contains($e->getMessage(), 'Token')) {
                throw HttpException::missingCredentials('Faltan credenciales de configuracion (Mercado Pago / QloApps) en el backend para procesar la transaccion.');
            }

            Response::error('Error interno al procesar la reserva.', 500, 'SERVER_ERROR');
        }
    }
}
