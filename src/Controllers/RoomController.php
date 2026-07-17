<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\QloAppService;

/**
 * Controlador de habitaciones.
 * Gestiona las consultas de disponibilidad y precios de habitaciones en tiempo real.
 * Refactorizado: usa Validator para eliminar duplicación de validaciones.
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
        $checkIn = $request->getQuery('checkIn', '');
        $checkOut = $request->getQuery('checkOut', '');
        $hotelId = (int)$request->getQuery('id_hotel', '1');

        // Validación centralizada (lanza HttpException si falla → capturada por Router)
        Validator::requireFields(
            ['checkIn' => $checkIn, 'checkOut' => $checkOut],
            ['checkIn', 'checkOut']
        );
        Validator::dateRange($checkIn, $checkOut);

        // Consultar el inventario disponible
        $rooms = $this->qloApp->getAvailableRooms($checkIn, $checkOut, $hotelId);

        Response::json([
            'success' => true,
            'data'    => $rooms,
        ]);
    }
}
