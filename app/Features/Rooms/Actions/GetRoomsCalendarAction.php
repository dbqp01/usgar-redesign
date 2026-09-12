<?php
declare(strict_types=1);

namespace App\Features\Rooms\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\Logger;
use App\Core\Config;
use App\Core\HttpException;
use App\Features\Shared\Ports\PmsPortInterface;
use App\Features\Shared\PmsUnavailableException;
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
        // Do not cast arbitrary query input: `(int)'abc'` and `(int)-1`
        // previously reached the PMS and produced an empty successful result.
        $rawHotelId = $request->getQuery('id_hotel');
        $hotelId = Validator::positiveInt(
            $rawHotelId === null ? Config::get('DEFAULT_HOTEL_ID', '1') : $rawHotelId,
            'id_hotel'
        );

        // Default: hoy + 60 días si no se especifica
        if (!$from || !$to) {
            $from = date('Y-m-d');
            $to   = date('Y-m-d', strtotime('+60 days'));
        }

        try {
            Validator::dateRange($from, $to);
            $rangeDays = (int)round((strtotime($to) - strtotime($from)) / 86400);
            if ($rangeDays > 120) {
                throw HttpException::badRequest('El rango del calendario no puede superar 120 días.');
            }

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

        } catch (HttpException $e) {
            throw $e;
        } catch (PmsUnavailableException $e) {
            Logger::error('GetRoomsCalendarAction PMS unavailable: ' . $e->getMessage());
            throw new HttpException('No se pudo consultar el servicio de habitaciones en este momento.', 503, 'SERVICE_UNAVAILABLE');
        } catch (Exception $e) {
            Logger::error('GetRoomsCalendarAction Exception: ' . $e->getMessage());

            Response::error('Error al consultar disponibilidad por día.', 500);
        }
    }
}
