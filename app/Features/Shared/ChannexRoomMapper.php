<?php
declare(strict_types=1);

namespace App\Features\Shared;

use App\Core\Logger;
use App\Core\Config;

/**
 * Servicio de Mapeo de Habitaciones Channex (DIP / SRP).
 * Convierte identificadores de habitacion o tarifas de Channex/OTA en id_room_type local.
 */
class ChannexRoomMapper {
    /**
     * Mapeo por defecto de tipos de habitacion local (ID -> Slug/Codigo OTA)
     * 1: Matrimonial Superior
     * 2: Doble Superior
     * 3: Triple Estandar
     * 4: Familiar Superior
     */
    private array $mapping;

    public function __construct(?array $mapping = null) {
        if ($mapping !== null) {
            $this->mapping = $mapping;
        } else {
            // Intentar cargar mapa desde variable de entorno o archivo de configuracion
            $envMapJson = Config::get('CHANNEX_ROOM_MAP');
            if (!empty($envMapJson)) {
                $decoded = json_decode($envMapJson, true);
                $this->mapping = is_array($decoded) ? $decoded : $this->getDefaultMapping();
            } else {
                $this->mapping = $this->getDefaultMapping();
            }
        }
    }

    /**
     * Resuelve el id_room_type local a partir del payload de reserva de Channex.
     *
     * @param array $bookingData Payload de la reserva o linea de habitacion
     * @return int id_room_type asignado
     */
    public function resolveRoomTypeId(array $bookingData): int {
        // Intentar extraer identificador de tipo de habitacion del payload Channex
        $channexRoomTypeId = $bookingData['room_type_id'] 
            ?? ($bookingData['room_type_code'] 
            ?? ($bookingData['rate_plan_id'] 
            ?? ($bookingData['ota_room_type_id'] ?? null)));

        if ($channexRoomTypeId !== null && isset($this->mapping[$channexRoomTypeId])) {
            return (int)$this->mapping[$channexRoomTypeId];
        }

        // Busqueda por coincidencia de nombre (fallback inteligente)
        $roomTitle = strtolower($bookingData['room_name'] ?? ($bookingData['title'] ?? ''));
        if (!empty($roomTitle)) {
            if (str_contains($roomTitle, 'familiar')) {
                return 4;
            }
            if (str_contains($roomTitle, 'triple')) {
                return 3;
            }
            if (str_contains($roomTitle, 'doble') || str_contains($roomTitle, 'double')) {
                return 2;
            }
            if (str_contains($roomTitle, 'matrimonial') || str_contains($roomTitle, 'king') || str_contains($roomTitle, 'queen') || str_contains($roomTitle, 'single')) {
                return 1;
            }
        }

        Logger::warning("ChannexRoomMapper: No se encontró mapeo explícito para tipo de habitación de Channex [{$channexRoomTypeId}]. Usando tipo predeterminado (1).", [
            'channex_room_type_id' => $channexRoomTypeId,
            'room_title'           => $roomTitle,
        ]);

        return 1;
    }

    /**
     * Mapeo por defecto (fallback) en caso de no estar definido en .env.
     */
    private function getDefaultMapping(): array {
        return [
            Config::get('CHANNEX_ROOM_MATRIMONIAL')       => 1,
            Config::get('CHANNEX_ROOM_DOBLE_SUPERIOR')    => 2,
            Config::get('CHANNEX_ROOM_TRIPLE_STANDAR')    => 3,
            Config::get('CHANNEX_ROOM_FAMILIAR_SUPERIOR') => 4,
        ];
    }
}
