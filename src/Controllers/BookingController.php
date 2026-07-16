<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Models\ProvisionalBooking;
use App\Services\QloAppService;
use App\Services\MercadoPagoService;
use PDO;
use Exception;

/**
 * Controlador de Reservas.
 * Maneja la creación de bloqueos temporales, preferencias de pago y estados de reserva.
 */
class BookingController {
    private ?PDO $pdo;
    private QloAppService $qloApp;
    private MercadoPagoService $mp;
    private ProvisionalBooking $bookingModel;

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
        // 1. Validar Rate Limit: Máximo 5 checkout/intentos de reserva por IP cada 10 minutos
        $ip = $request->getIp();
        if (!RateLimiter::check($ip, 5, 600)) {
            Response::tooManyRequests('Demasiados intentos de reserva. Por favor intenta de nuevo en 10 minutos.');
        }

        // 2. Extraer y validar parámetros de entrada
        $hotelId = (int)$request->get('id_hotel', 1);
        $idRoomType = $request->get('id_room_type');
        $checkIn = $request->get('checkIn');
        $checkOut = $request->get('checkOut');
        $guestName = $request->get('guestName');
        $guestEmail = $request->get('guestEmail');
        $guestPhone = $request->get('guestPhone', '');

        if (!$idRoomType || !$checkIn || !$checkOut || !$guestName || !$guestEmail) {
            Response::badRequest('Faltan parámetros requeridos para iniciar la reserva.');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkIn) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkOut)) {
            Response::badRequest('Formato de fecha inválido. Se espera YYYY-MM-DD.');
        }

        if (strtotime($checkIn) >= strtotime($checkOut)) {
            Response::badRequest('La fecha de checkIn debe ser estrictamente anterior a la de checkOut.');
        }

        if (!$this->pdo) {
            Response::error('Servicio de Base de Datos no disponible.', 500);
        }

        try {
            // Iniciar Transacción SQL para asegurar consistencia
            $this->pdo->beginTransaction();

            // 3. Validar disponibilidad real en este instante
            $availableRooms = $this->qloApp->getAvailableRooms($checkIn, $checkOut, $hotelId);
            $targetRoom = null;
            
            foreach ($availableRooms as $room) {
                if ((int)$room['id_room_type'] === (int)$idRoomType) {
                    $targetRoom = $room;
                    break;
                }
            }

            if (!$targetRoom || $targetRoom['available_qty'] <= 0) {
                $this->pdo->rollBack();
                Response::badRequest('La habitación seleccionada ya no está disponible para estas fechas.');
            }

            // 4. Calcular precios
            $nights = (int)round((strtotime($checkOut) - strtotime($checkIn)) / 86400);
            $pricePerNight = (float)$targetRoom['price'];
            $totalPrice = $pricePerNight * $nights;

            // 5. Crear Carrito en QloApps a través de su Web Service
            $cartId = $this->qloApp->createCart($hotelId, (int)$idRoomType, $checkIn, $checkOut, 2);

            // 6. Registrar Hold en Base de Datos local
            $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            $holdData = [
                'cart_id' => $cartId,
                'id_hotel' => $hotelId,
                'id_room_type' => (int)$idRoomType,
                'guest_data' => [
                    'name' => $guestName,
                    'email' => $guestEmail,
                    'phone' => $guestPhone
                ],
                'room_data' => [
                    'room_name' => $targetRoom['room_name'],
                    'price_per_night' => $pricePerNight,
                    'nights' => $nights
                ],
                'price_snapshot' => $totalPrice,
                'checkin' => $checkIn,
                'checkout' => $checkOut,
                'status' => 'pending',
                'expires_at' => $expiresAt
            ];

            if (!$this->bookingModel->create($holdData)) {
                throw new Exception("Fallo al insertar el bloqueo de reserva en DB.");
            }

            // 7. Crear la preferencia de pago en Mercado Pago
            $preference = $this->mp->createPreference(
                $cartId,
                (int)$idRoomType,
                $checkIn,
                $checkOut,
                $totalPrice,
                $guestName,
                $guestEmail
            );

            $preferenceId = $preference['id'] ?? null;
            $initPoint = $preference['init_point'] ?? '';
            $sandboxInitPoint = $preference['sandbox_init_point'] ?? '';

            if (empty($preferenceId)) {
                throw new Exception("No se pudo obtener el Preference ID desde Mercado Pago.");
            }

            // Actualizar el hold en base de datos con el ID de preferencia obtenido
            $this->bookingModel->updatePreferenceId($cartId, $preferenceId);

            // Confirmar transacción SQL
            $this->pdo->commit();

            Response::json([
                'success' => true,
                'cart_id' => $cartId,
                'preference_id' => $preferenceId,
                'init_point' => $initPoint,
                'sandbox_init_point' => $sandboxInitPoint,
                'price' => $totalPrice,
                'expires_at' => $expiresAt
            ]);

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Logger::error("BookingController::create Exception: " . $e->getMessage());
            Response::error('No se pudo procesar el bloqueo de reserva: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Endpoint: POST /api/extend-hold
     * Extiende el bloqueo temporal del carrito por 15 minutos adicionales (utilizado por cuenta regresiva del frontend).
     */
    public function extend(Request $request): void {
        $cartId = $request->get('cart_id');
        
        if (!$cartId) {
            Response::badRequest('Falta el parámetro cart_id.');
        }

        if (!$this->pdo) {
            Response::error('Base de datos no disponible.', 500);
        }

        $hold = $this->bookingModel->getByCartId($cartId);
        
        if (!$hold) {
            Response::notFound('No se encontró ningún bloqueo para el cart_id especificado.');
        }

        if ($hold['status'] !== 'pending') {
            Response::badRequest('El bloqueo ya no está en estado pendiente.');
        }

        $newExpiration = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        
        if ($this->bookingModel->extend($cartId, $newExpiration)) {
            Response::json([
                'success' => true,
                'expires_at' => $newExpiration
            ]);
        } else {
            Response::error('No se pudo extender el bloqueo en la base de datos.', 500);
        }
    }

    /**
     * Endpoint: GET /api/booking-status
     * Retorna el estado actual de la reserva (ej. para pantallas de éxito/espera).
     */
    public function status(Request $request): void {
        $cartId = $request->getQuery('cart_id');
        
        if (!$cartId) {
            Response::badRequest('Falta el parámetro cart_id.');
        }

        if (!$this->pdo) {
            Response::error('Base de datos no disponible.', 500);
        }

        $hold = $this->bookingModel->getByCartId($cartId);

        if (!$hold) {
            Response::notFound('Reserva no encontrada.');
        }

        Response::json([
            'success' => true,
            'cart_id' => $hold['cart_id'],
            'status' => $hold['status'],
            'checkin' => $hold['checkin'],
            'checkout' => $hold['checkout'],
            'guest_name' => $hold['guest_data']['name'] ?? '',
            'room_name' => $hold['room_data']['room_name'] ?? '',
            'price' => (float)$hold['price_snapshot']
        ]);
    }
}
