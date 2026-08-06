<?php
declare(strict_types=1);

namespace App\Features\Rooms\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\Logger;
use App\Core\Config;
use App\Features\Shared\Ports\PmsPortInterface;
use App\Features\Shared\RoomTypeRegistry;
use Exception;

/**
 * Accion ADR: GET /api/rooms/calendar
 * Disponibilidad por día y por habitación para pintar el calendario de
 * reservas (días agotados bloqueados, tooltips por habitación).
 *
 * Query: from=YYYY-MM-DD&to=YYYY-MM-DD&id_hotel=1 (máx. 120 días)
 * Respuesta: { success: true, days: { "YYYY-MM-DD": { slug: qty, ... } } }
 */
class GetRoomsCalendarAction {
    private PmsPortInterface $pms;

    public function __construct(PmsPortInterface $pms) {
        $this->pms = $pms;
    }

    public function __invoke(Request $request): void {
        $from = $request->getQuery('from');
        $to   = $request->getQuery('to');
        $hotelId = (int)($request->getQuery('id_hotel') ?? Config::get('DEFAULT_HOTEL_ID', '1'));

        // Default: hoy + 60 días si no se especifica
        if (!$from || !$to) {
            $from = date('Y-m-d');
            $to   = date('Y-m-d', strtotime('+60 days'));
        }

        try {
            Validator::dateRange($from, $to);

            $rawDays = $this->pms->getAvailabilityCalendar($from, $to, $hotelId);

            // Traducir id_room_type -> slug y redondear cantidades
            $days = [];
            foreach ($rawDays as $date => $availByRoomType) {
                $day = [];
                foreach ($availByRoomType as $idRoomType => $qty) {
                    $slug = RoomTypeRegistry::getSlugById((int)$idRoomType);
                    if ($slug) {
                        $day[$slug] = max(0, (int)$qty);
                    }
                }
                $days[$date] = $day;
            }

            Response::json([
                'success' => true,
                'from'    => $from,
                'to'      => $to,
                'days'    => $days,
            ]);

        } catch (Exception $e) {
            Logger::error('GetRoomsCalendarAction Exception: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'offline') || str_contains($e->getMessage(), 'SQLSTATE')) {
                throw \App\Core\HttpException::internal('No se pudo consultar el servicio de habitaciones en este momento.');
            }

            Response::error('Error al consultar disponibilidad por día.', 500);
        }
    }
}
