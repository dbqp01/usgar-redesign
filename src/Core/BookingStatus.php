<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Enum PHP 8.1 backed-string para estados de reserva provisional.
 * Reemplaza strings mágicos ('pending', 'paid', etc.) con valores tipados.
 * Inmutable y auto-documentado — previene errores de tipeo en comparaciones.
 */
enum BookingStatus: string {
    case Pending = 'pending';
    case Paid    = 'paid';
    case Failed  = 'failed';
    case Expired = 'expired';

    /**
     * Verifica si el estado permite extensión de hold.
     */
    public function isExtendable(): bool {
        return $this === self::Pending;
    }

    /**
     * Verifica si el estado es terminal (no puede cambiar).
     */
    public function isTerminal(): bool {
        return match ($this) {
            self::Paid, self::Expired, self::Failed => true,
            default => false,
        };
    }
}
