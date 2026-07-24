<?php
declare(strict_types=1);

namespace App\Features\Shared;

use App\Core\Config;

/**
 * Fuente única de verdad para el mapeo de tipos de habitación.
 * Centraliza la relación id_room_type ↔ slug ↔ Channex env key.
 *
 * REGLA ANTI-HARDCODING: Los UUIDs de Channex se leen del .env.
 * Solo los slugs internos y IDs numéricos están aquí (son constantes del dominio).
 */
class RoomTypeRegistry {

    /**
     * Mapeo canónico: id_room_type → slug del dominio.
     * Estos son los 4 tipos de habitación del Hotel San Pedro.
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
        return self::SLUG_MAP[$idRoomType] ?? 'matrimonial';
    }

    /**
     * Obtiene el id_room_type por slug.
     * Retorna null si el slug no existe.
     */
    public static function getIdBySlug(string $slug): ?int {
        $flipped = array_flip(self::SLUG_MAP);
        return $flipped[$slug] ?? null;
    }

    /**
     * Retorna todos los slugs válidos.
     *
     * @return array<int, string> [id => slug]
     */
    public static function all(): array {
        return self::SLUG_MAP;
    }

    /**
     * Resuelve el UUID de Channex Room Type desde .env.
     * Formato de env key: CHANNEX_ROOM_{SLUG_UPPER_WITH_UNDERSCORES}
     */
    public static function getChannexRoomId(int $idRoomType): ?string {
        $slug = self::getSlugById($idRoomType);
        $envKey = 'CHANNEX_ROOM_' . strtoupper(str_replace('-', '_', $slug));
        $val = Config::get($envKey);
        return !empty($val) ? $val : null;
    }

    /**
     * Resuelve el UUID de Channex Rate Plan desde .env.
     * Formato: CHANNEX_RATE_{SLUG_UPPER} o fallback a CHANNEX_RATE_PLAN_ID
     */
    public static function getChannexRatePlanId(int $idRoomType): ?string {
        $slug = self::getSlugById($idRoomType);
        $envKey = 'CHANNEX_RATE_' . strtoupper(str_replace('-', '_', $slug));
        $specific = Config::get($envKey);
        return !empty($specific) ? $specific : Config::get('CHANNEX_RATE_PLAN_ID');
    }
}
