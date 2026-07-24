<?php
declare(strict_types=1);

namespace App\Features\Rooms\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Config;
use App\Features\Shared\Ports\PmsPortInterface;
use App\Features\Shared\Adapters\QloAppAdapter;
use Exception;

/**
 * Accion ADR: GET /api/rooms
 * Consulta la disponibilidad neta y precios dinamicos desde el PMS QloApps.
 */
class GetRoomsAction {
    private PmsPortInterface $pms;

    public function __construct(?PmsPortInterface $pms = null) {
        $this->pms = $pms ?? new QloAppAdapter();
    }

    public function __invoke(Request $request): void {
        $checkIn  = $request->getQuery('checkIn');
        $checkOut = $request->getQuery('checkOut');
        $hotelId  = (int)($request->getQuery('id_hotel') ?? 1);

        if (!$checkIn || !$checkOut) {
            throw HttpException::badRequest('Faltan los parámetros obligatorios checkIn y checkOut.');
        }

        Validator::dateRange($checkIn, $checkOut);

        try {
            $availableRooms = $this->pms->getAvailableRooms($checkIn, $checkOut, $hotelId);

            Response::json([
                'success' => true,
                'rooms'   => $availableRooms,
            ]);

        } catch (Exception $e) {
            Logger::error('GetRoomsAction Exception: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'offline') || str_contains($e->getMessage(), 'SQLSTATE')) {
                throw HttpException::internal('No se pudo consultar el servicio de habitaciones en este momento.');
            }

            $clientMessage = Config::isProduction()
                ? 'Error al consultar disponibilidad.'
                : 'Error: ' . $e->getMessage();

            Response::error($clientMessage, 500);
        }
    }
}
