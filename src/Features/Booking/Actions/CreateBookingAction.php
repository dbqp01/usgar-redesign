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
use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use App\Features\Shared\Adapters\QloAppAdapter;
use App\Features\Shared\Adapters\MercadoPagoAdapter;
use App\Services\SessionService;
use PDO;
use Exception;

/**
 * Acción ADR: POST /api/booking
 * Crea un bloqueo temporal en QloApps y genera la preferencia de pago en Mercado Pago.
 */
class CreateBookingAction {
    private PDO $pdo;
    private PmsPortInterface $pms;
    private PaymentGatewayPortInterface $paymentGateway;
    private ProvisionalBookingRepository $bookingRepo;

    public function __construct(
        ?PDO $pdo = null,
        ?PmsPortInterface $pms = null,
        ?PaymentGatewayPortInterface $paymentGateway = null,
        ?ProvisionalBookingRepository $bookingRepo = null
    ) {
        $container = Container::getInstance();
        $db = Database::getInstance();
        $this->pdo = $pdo ?? ($container->has(PDO::class) ? $container->get(PDO::class) : $db->getConnection());
        $this->pms = $pms ?? new QloAppAdapter($this->pdo);
        $this->paymentGateway = $paymentGateway ?? new MercadoPagoAdapter();
        $this->bookingRepo = $bookingRepo ?? new ProvisionalBookingRepository($this->pdo);
    }

    public function __invoke(Request $request): void {
        $body = $request->getBody() ?? [];

        // --- Normalización Adaptativa de Payload (Zero-Breakage) ---
        if (isset($body['roomSlug']) && empty($body['id_room_type'])) {
            $slugMap = [
                'suite-imperial' => 1,
                'suite-ejecutiva' => 2,
                'habitacion-doble' => 3,
                'habitacion-estandar' => 4,
            ];
            $body['id_room_type'] = $slugMap[$body['roomSlug']] ?? 1;
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

        $hotelId    = (int)($body['id_hotel'] ?? 1);
        $idRoomType = Validator::positiveInt($body['id_room_type'], 'id_room_type');
        $guests     = max(1, (int)($body['guests'] ?? 2));
        $checkIn    = $body['checkIn'];
        $checkOut   = $body['checkOut'];
        $guestName  = htmlspecialchars(trim($body['guestName']), ENT_QUOTES, 'UTF-8');
        $guestEmail = Validator::email($body['guestEmail']);
        $guestPhone = htmlspecialchars(trim($body['guestPhone'] ?? ''), ENT_QUOTES, 'UTF-8');

        Validator::dateRange($checkIn, $checkOut);

        try {
            $this->pdo->beginTransaction();

            $availableRooms = $this->pms->getAvailableRooms($checkIn, $checkOut, $hotelId);
            $targetRoom = null;

            foreach ($availableRooms as $room) {
                if ((int)$room['id_room_type'] === $idRoomType) {
                    $targetRoom = $room;
                    break;
                }
            }

            if (!$targetRoom || $targetRoom['available_qty'] <= 0) {
                $this->pdo->rollBack();
                throw HttpException::badRequest('La habitación seleccionada ya no está disponible para estas fechas.');
            }

            $maxGuests = (int)($targetRoom['max_guests'] ?? 2);
            if ($guests > $maxGuests) {
                $this->pdo->rollBack();
                throw HttpException::badRequest("El número de huéspedes ({$guests}) excede la capacidad máxima de esta habitación ({$maxGuests} personas).");
            }

            $idProduct = (int)($targetRoom['id_product'] ?? $idRoomType);
            $nights = (int)max(1, round((strtotime($checkOut) - strtotime($checkIn)) / 86400));
            $pricePerNight = (float)$targetRoom['price'];
            $totalPrice = round($pricePerNight * $nights, 2);

            $cartId = $this->pms->createCart($hotelId, $idProduct, $checkIn, $checkOut, $guests);
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
                throw HttpException::internal('Falta configuración obligatoria de BOOKING_TOKEN_SECRET en servidor .env');
            }

            $accessToken = hash_hmac('sha256', $cartId . ':' . $guestEmail, $secretKey);

            $preference = $this->paymentGateway->createPreference(
                $cartId,
                $idRoomType,
                $checkIn,
                $checkOut,
                $totalPrice,
                $guestName,
                $guestEmail
            );

            $preferenceId = $preference['id'] ?? null;
            $initPoint = $preference['init_point'] ?? '';

            if (empty($preferenceId)) {
                throw new Exception('No se pudo obtener el Preference ID desde Mercado Pago.');
            }

            $this->bookingRepo->updatePreferenceId($cartId, $preferenceId);
            $this->pdo->commit();

            Response::json([
                'success'       => true,
                'cart_id'       => $cartId,
                'access_token'  => $accessToken,
                'preference_id' => $preferenceId,
                'init_point'    => $initPoint,
                'price'         => $totalPrice,
                'expires_at'    => $expiresAt,
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
                throw HttpException::missingCredentials('Faltan credenciales de configuración (Mercado Pago / QloApps) en el backend para procesar la transacción.');
            }

            $clientMessage = Config::isProduction()
                ? 'No se pudo procesar la reserva. Intente nuevamente.'
                : 'Error: ' . $e->getMessage();

            Response::error($clientMessage, 500, 'SERVER_ERROR');
        }
    }
}
