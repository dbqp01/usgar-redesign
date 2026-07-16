<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\QloAppService;

/**
 * Controlador de habitaciones.
 * Gestiona las consultas de disponibilidad y precios de habitaciones en tiempo real.
 */
class RoomController {
    private QloAppService $qloApp;

    public function __construct(?QloAppService $qloApp = null) {
        $this->qloApp = $qloApp ?? new QloAppService();
    }

    /**
     * Endpoint: GET /api/rooms
     * Retorna la lista de habitaciones disponibles con sus precios base e inventario libre.
     */
    public function index(Request $request): void {
        $checkIn = $request->getQuery('checkIn');
        $checkOut = $request->getQuery('checkOut');
        $hotelId = (int)$request->getQuery('id_hotel', '1');

        if (!$checkIn || !$checkOut) {
            Response::badRequest('Faltan los parámetros requeridos: checkIn y checkOut.');
        }

        // Validar formato de fechas YYYY-MM-DD
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkIn) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkOut)) {
            Response::badRequest('Formato de fecha inválido. Se espera YYYY-MM-DD.');
        }

        // Validar coherencia de fechas
        if (strtotime($checkIn) >= strtotime($checkOut)) {
            Response::badRequest('La fecha de checkIn debe ser estrictamente anterior a la de checkOut.');
        }

        // Consultar el inventario disponible
        $rooms = $this->qloApp->getAvailableRooms($checkIn, $checkOut, $hotelId);

        Response::json([
            'success' => true,
            'data' => $rooms
        ]);
    }
}
