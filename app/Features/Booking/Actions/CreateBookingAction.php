<?php
declare(strict_types=1);

namespace App\Features\Booking\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Config;
use App\Core\Database;
use App\Core\Validator;
use App\Core\HttpException;
use App\Core\BookingStatus;
use App\Core\Container;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Ports\PmsPortInterface;
use App\Features\Shared\RoomTypeRegistry;
use App\Features\Auth\SessionService;
use PDO;
use Exception;

/**
 * Accion ADR: POST /api/booking
 * Crea un bloqueo temporal en QloApps y devuelve los datos para el pago
 * con Custom Checkout (Checkout API) desde el cliente.
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
        if (isset($body['roomSlug']) && empty($body['id_room_type'])) {
            $body['id_room_type'] = RoomTypeRegistry::getIdBySlug($body['roomSlug']) ?? 1;
        }

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
        }

        Validator::requireFields($body, ['id_room_type', 'checkIn', 'checkOut', 'guestName', 'guestEmail']);

        $hotelId    = (int)($body['id_hotel'] ?? Config::get('DEFAULT_HOTEL_ID', '1'));
        $idRoomType = Validator::positiveInt($body['id_room_type'], 'id_room_type');
        $guests     = max(1, (int)($body['guests'] ?? 2));
        $checkIn    = $body['checkIn'];
        $checkOut   = $body['checkOut'];
        $guestName  = trim($body['guestName']);
        $guestEmail = Validator::email($body['guestEmail']);
        $guestPhone = trim($body['guestPhone'] ?? '');

        Validator::dateRange($checkIn, $checkOut);

        try {

            $availableRooms = $this->pms->getAvailableRooms($checkIn, $checkOut, $hotelId);
            $targetRoom = null;

            foreach ($availableRooms as $room) {
                if ((int)$room['id_room_type'] === $idRoomType) {
                    $targetRoom = $room;
                    break;
                }
            }

            if (!$targetRoom) {
                throw HttpException::badRequest('La habitaciÃ³n seleccionada ya no estÃ¡ disponible para estas fechas.');
            }

            $maxGuests = (int)($targetRoom['max_guests'] ?? 2);
            if ($guests > $maxGuests) {
                throw HttpException::badRequest("El nÃºmero de huÃ©spedes ({$guests}) excede la capacidad mÃ¡xima de esta habitaciÃ³n ({$maxGuests} personas).");
            }

            $idProduct = (int)($targetRoom['id_product'] ?? $idRoomType);
            $nights = (int)max(1, round((strtotime($checkOut) - strtotime($checkIn)) / 86400));
            $pricePerNight = (float)$targetRoom['price'];
            $totalPrice = round($pricePerNight * $nights, 2);

            $cartId = $this->pms->createCart($hotelId, $idProduct, $checkIn, $checkOut, $guests, $totalPrice, $guestName, $guestEmail, $guestPhone);
            
            $this->pdo->beginTransaction();

            $holdsCount = $this->bookingRepo->getHoldCountForRoomForUpdate($idRoomType, $checkIn, $checkOut, $hotelId);
            $targetRoom['available_qty'] -= $holdsCount;

            if ($targetRoom['available_qty'] <= 0) {
                $this->pdo->rollBack();
                throw HttpException::badRequest('La habitaciÃ³n seleccionada ya no estÃ¡ disponible para estas fechas.');
            }
            $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            $currentUser = SessionService::getUserFromRequest();

            $holdData = [
                'cart_id'       => $cartId,
                'user_id'       => $currentUser['sub'] ?? null,
                'id_hotel'      => $hotelId,
                'id_room_type'  => $idRoomType,
                'guest_data'    => [
                    'name'   => $guestName,
                    'email'  => $guestEmail,
                    'phone'  => $guestPhone,
                    'guests' => $guests,
                ],
                'room_data'     => [
                    'room_name'       => $targetRoom['room_name'],
                    'price_per_night' => $pricePerNight,
                    'nights'          => $nights,
                ],
                'price_snapshot' => $totalPrice,
                'checkin'        => $checkIn,
                'checkout'       => $checkOut,
                'status'         => BookingStatus::Pending->value,
                'expires_at'     => $expiresAt,
            ];

            if (!$this->bookingRepo->create($holdData)) {
                throw new Exception('Fallo al insertar el bloqueo de reserva en DB.');
            }

            // Exigencia estricta de variables de entorno sin fallbacks inseguros
            $secretKey = Config::get('BOOKING_TOKEN_SECRET', Config::get('CRON_SECRET'));
            if (empty($secretKey)) {
                throw HttpException::internal('Falta configuraciÃ³n obligatoria de BOOKING_TOKEN_SECRET en servidor .env');
            }

            $accessToken = hash_hmac('sha256', $cartId . ':' . $guestEmail, $secretKey);

            $this->pdo->commit();

            $timeLeftSeconds = max(0, strtotime($expiresAt) - time());
            $roomSlug = RoomTypeRegistry::getSlugById($idRoomType);

            $exchangeRate = (float) Config::get('EXCHANGE_RATE_USD_PEN');
            $gatewayPricePEN = round($totalPrice * $exchangeRate, 2);

            Response::json([
                'success'           => true,
                'cart_id'           => $cartId,
                'access_token'      => $accessToken,
                'currency'          => 'USD',
                'price'             => $totalPrice,
                'exchange_rate'     => $exchangeRate,
                'gateway_currency'  => 'PEN',
                'gateway_price'     => $gatewayPricePEN,
                'mp_public_key'     => Config::get('PUBLIC_MERCADO_PAGO_PUBLIC_KEY'),
                'expires_at'        => $expiresAt,
                'time_left_seconds' => $timeLeftSeconds,
                'room_summary'      => [
                    'id_room_type'    => $idRoomType,
                    'slug'            => $roomSlug,
                    'room_name'       => $targetRoom['room_name'],
                    'price_per_night' => $pricePerNight,
                    'nights'          => $nights,
                    'guests'          => $guests,
                ],
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

            $clientMessage = 'Error: ' . $e->getMessage();

            Response::error($clientMessage, 500, 'SERVER_ERROR');
        }
    }
}
