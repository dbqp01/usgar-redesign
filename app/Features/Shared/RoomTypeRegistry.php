<?php
declare(strict_types=1);

namespace App\Features\Shared;

/**
 * Fuente unica de verdad para el mapeo de tipos de habitacion.
 * Centraliza la relacion id_room_type ↔ slug.
 */
class RoomTypeRegistry {

    /**
     * Mapeo canonico: id_room_type → slug del dominio.
     * Estos son los 4 tipos de habitacion del Hotel San Pedro.
     */
    private const SLUG_MAP = [
        1 => 'matrimonial',
        2 => 'doble-superior',
        3 => 'triple-standar',
        4 => 'familiar-superior',
    ];

    /**
     * Obtiene el slug por id_room_type.
     */
    public static function getSlugById(int $idRoomType): string {
        if (!isset(self::SLUG_MAP[$idRoomType])) {
            throw new \InvalidArgumentException("Unknown RoomType ID: {$idRoomType}");
        }
        return self::SLUG_MAP[$idRoomType];
    }

    /**
     * Obtiene el id_room_type por slug.
     * Retorna null si el slug no existe.
     */
    public static function getIdBySlug(string $slug): ?int {
        $flipped = array_flip(self::SLUG_MAP);
        return $flipped[$slug] ?? null;
    }
}
