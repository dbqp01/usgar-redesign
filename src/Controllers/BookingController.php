<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Config;
use App\Core\Database;
use App\Core\Validator;
use App\Core\HttpException;
use App\Core\BookingStatus;
use App\Models\ProvisionalBooking;
use App\Services\QloAppService;
use App\Services\MercadoPagoService;
use PDO;
use Exception;

/**
 * Controlador de Reservas.
 * Maneja la creación de bloqueos temporales, preferencias de pago y estados de reserva.
 * Refactorizado: Validator, BookingStatus enum, Config, error masking en producción.
 */
class BookingController {
    private ?PDO $pdo;
    private QloAppService $qloApp;
    private MercadoPagoService $mp;
    private ?ProvisionalBooking $bookingModel = null;

    public function __construct(
        ?PDO $pdo = null,
        ?QloAppService $qloApp = null,
        ?MercadoPagoService $mp = null
    ) {
        $db = Database::getInstance();
        $this->pdo = $pdo ?? $db->getConnection();

        $this->qloApp = $qloApp ?? new QloAppService($this->pdo);
        $this->mp = $mp ?? new MercadoPagoService();

        if ($this->pdo) {
            $this->bookingModel = new ProvisionalBooking($this->pdo);
        }
    }

    /**
     * Endpoint: POST /api/booking
     * Crea un bloqueo temporal (Hold) en QloApps y una preferencia de pago en Mercado Pago.
     */
    public function create(Request $request): void {
        // 1. Extraer y validar parámetros de entrada
        $body = $request->getBody() ?? [];

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

        if (!$this->pdo || !$this->bookingModel) {
            throw HttpException::internal('Servicio de Base de Datos no disponible.');
        }

        try {
            // Iniciar Transacción SQL para asegurar consistencia
            $this->pdo->beginTransaction();

            // 2. Validar disponibilidad real en este instante
            $availableRooms = $this->qloApp->getAvailableRooms($checkIn, $checkOut, $hotelId);
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

            // Validar capacidad dinámica de la habitación (Zero-Hardcoding)
            $maxGuests = (int)($targetRoom['max_guests'] ?? 2);
            if ($guests > $maxGuests) {
                $this->pdo->rollBack();
                throw HttpException::badRequest("El número de huéspedes ({$guests}) excede la capacidad máxima de esta habitación ({$maxGuests} personas).");
            }

            // 3. Calcular precios e ID de producto real
            $idProduct = (int)($targetRoom['id_product'] ?? $idRoomType);
            $nights = (int)round((strtotime($checkOut) - strtotime($checkIn)) / 86400);
            $pricePerNight = (float)$targetRoom['price'];
            $totalPrice = $pricePerNight * $nights;

            // 4. Crear Carrito en QloApps con ID de producto y número real de huéspedes
            $cartId = $this->qloApp->createCart($hotelId, $idProduct, $checkIn, $checkOut, $guests);

            // 5. Registrar Hold en Base de Datos local
            $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

            $holdData = [
                'cart_id'       => $cartId,
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

            if (!$this->bookingModel->create($holdData)) {
                throw new Exception('Fallo al insertar el bloqueo de reserva en DB.');
            }

            // 6. Generar token de acceso seguro para la reserva
            $secretKey = Config::get('CRON_SECRET', 'USGAR_SECURE_TOKEN_SECRET');
            $accessToken = hash_hmac('sha256', $cartId . ':' . $guestEmail, $secretKey);

            // 7. Crear la preferencia de pago en Mercado Pago
            $preference = $this->mp->createPreference(
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

            // Actualizar el hold con el ID de preferencia
            $this->bookingModel->updatePreferenceId($cartId, $preferenceId);

            // Confirmar transacción SQL
            $this->pdo->commit();

            Response::json([
                'success'            => true,
                'cart_id'            => $cartId,
                'access_token'       => $accessToken,
                'preference_id'      => $preferenceId,
                'init_point'         => $initPoint,
                'price'              => $totalPrice,
                'expires_at'         => $expiresAt,
            ]);

        } catch (HttpException $e) {
            if ($this->pdo && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e; // Re-lanzar para que el Router la maneje
        } catch (Exception $e) {
            if ($this->pdo && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Logger::error('BookingController::create Exception: ' . $e->getMessage());

            // Si el mensaje indica credenciales no configuradas, notificar explícitamente
            if (str_contains($e->getMessage(), 'not configured') || str_contains($e->getMessage(), 'Token')) {
                throw HttpException::missingCredentials('Faltan credenciales de configuración (Mercado Pago / QloApps) en el backend para procesar la transacción.');
            }

            // No exponer detalles internos al cliente en producción
            $clientMessage = Config::isProduction()
                ? 'No se pudo procesar la reserva. Intente nuevamente.'
                : 'Error: ' . $e->getMessage();

            Response::error($clientMessage, 500, 'SERVER_ERROR');
        }
    }

    /**
     * Endpoint: POST /api/extend-hold
     * Extiende el bloqueo temporal del carrito por 15 minutos adicionales.
     */
    public function extend(Request $request): void {
        $cartId = $request->get('cart_id');

        if (!$cartId) {
            throw HttpException::badRequest('Falta el parámetro cart_id.');
        }

        if (!$this->pdo || !$this->bookingModel) {
            throw HttpException::internal('Base de datos no disponible.');
        }

        $hold = $this->bookingModel->getByCartId($cartId);

        if (!$hold) {
            throw HttpException::notFound('No se encontró ningún bloqueo para el cart_id especificado.');
        }

        $status = BookingStatus::tryFrom($hold['status']);
        if ($status === null || !$status->isExtendable()) {
            throw HttpException::badRequest('El bloqueo ya no está en estado pendiente.');
        }

        $newExpiration = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        if ($this->bookingModel->extend($cartId, $newExpiration)) {
            // Extender la sesión del carrito en la base de datos de QloApps también
            $this->qloApp->extendCartSession($cartId);

            Response::json([
                'success'    => true,
                'expires_at' => $newExpiration,
            ]);
        } else {
            Response::error('No se pudo extender el bloqueo en la base de datos.', 500);
        }
    }

    /**
     * Endpoint: GET /api/booking-status
     * Retorna el estado actual de la reserva.
     * Seguridad: Si no se provee el access_token válido, omite datos personales (PII).
     */
    public function status(Request $request): void {
        $cartId = $request->getQuery('cart_id');
        $providedToken = $request->getQuery('token', '');

        if (!$cartId) {
            throw HttpException::badRequest('Falta el parámetro cart_id.');
        }

        if (!$this->pdo || !$this->bookingModel) {
            throw HttpException::internal('Base de datos no disponible.');
        }

        $hold = $this->bookingModel->getByCartId($cartId);

        if (!$hold) {
            throw HttpException::notFound('Reserva no encontrada.');
        }

        $guestEmail = $hold['guest_data']['email'] ?? '';
        $secretKey = Config::get('CRON_SECRET', 'USGAR_SECURE_TOKEN_SECRET');
        $expectedToken = hash_hmac('sha256', $cartId . ':' . $guestEmail, $secretKey);
        $isAuthenticated = (!empty($providedToken) && hash_equals($expectedToken, $providedToken));

        $payload = [
            'success'         => true,
            'cart_id'         => $hold['cart_id'],
            'status'          => $hold['status'],
            'checkin'         => $hold['checkin'],
            'checkout'        => $hold['checkout'],
            'id_room_type'    => (int)$hold['id_room_type'],
            'room_name'       => $hold['room_data']['room_name'] ?? '',
            'price_per_night' => (float)($hold['room_data']['price_per_night'] ?? 0),
            'nights'          => (int)($hold['room_data']['nights'] ?? 1),
            'price'           => (float)$hold['price_snapshot'],
            'expires_at'      => $hold['expires_at'] ?? null,
        ];

        // Retornar PII sensible únicamente si la solicitud posee token válido
        if ($isAuthenticated) {
            $payload['guest_name']  = $hold['guest_data']['name'] ?? '';
            $payload['guest_email'] = $guestEmail;
            $payload['guest_phone'] = $hold['guest_data']['phone'] ?? '';
        }

        Response::json($payload);
    }
}
