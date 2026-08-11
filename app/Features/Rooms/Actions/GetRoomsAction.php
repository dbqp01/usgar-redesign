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
use App\Features\Shared\RoomTypeRegistry;
use Exception;

/**
 * Accion ADR: GET /api/rooms
 * Consulta la disponibilidad neta y precios dinamicos desde el PMS QloApps.
 */
class GetRoomsAction {
    private PmsPortInterface $pms;

    public function __construct(PmsPortInterface $pms) {
        $this->pms = $pms;
    }

    public function __invoke(Request $request): void {
        $checkIn  = $request->getQuery('checkIn');
        $checkOut = $request->getQuery('checkOut');
        $hotelId  = (int)($request->getQuery('id_hotel') ?? Config::get('DEFAULT_HOTEL_ID', '1'));

        if (!$checkIn || !$checkOut) {
            $checkIn = date('Y-m-d');
            $checkOut = date('Y-m-d', strtotime('+1 day'));
        }

        Validator::dateRange($checkIn, $checkOut);

        try {
            $availableRooms = $this->pms->getAvailableRooms($checkIn, $checkOut, $hotelId);
            $nights = (int)max(1, round((strtotime($checkOut) - strtotime($checkIn)) / 86400));

            $enrichedRooms = array_map(function(array $room) use ($nights) {
                $idRoomType = (int)($room['id_room_type'] ?? 1);
                $slug = RoomTypeRegistry::getSlugById($idRoomType);
                $price = (float)($room['price'] ?? 0.0);
                $totalStayPrice = round($price * $nights, 2);

                $room['slug']             = $slug;
                $currency = Config::get('HOTEL_BASE_CURRENCY', 'USD');
                $room['currency']         = $currency;
                $room['rate_plans']       = [
                    'standard'      => $price,
                    'non_refundable' => (float)($room['non_refundable_price'] ?? $price),
                ];
                
                if (class_exists('NumberFormatter')) {
                    $fmt = new \NumberFormatter('es_PE', \NumberFormatter::CURRENCY);
                    $room['price_formatted']  = $fmt->formatCurrency($price, $currency);
                } else {
                    $symbol = match($currency) { 'USD' => '$', 'PEN' => 'S/.', 'EUR' => '€', default => $currency . ' ' };
                    $room['price_formatted']  = $symbol . number_format($price, 2, '.', '');
                }
                
                $room['nights']           = $nights;
                $room['total_stay_price'] = $totalStayPrice;

                return $room;
            }, $availableRooms);

            Response::json([
                'success' => true,
                'rooms'   => $enrichedRooms,
            ]);

        } catch (Exception $e) {
            Logger::error('GetRoomsAction Exception: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'offline') || str_contains($e->getMessage(), 'SQLSTATE')) {
                throw HttpException::internal('No se pudo consultar el servicio de habitaciones en este momento.');
            }

            Response::error('Error al consultar disponibilidad de habitaciones.', 500);
        }
    }
}
