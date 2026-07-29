<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Enum PHP 8.1 backed-string para estados de reserva provisional.
 * Reemplaza strings magicos ('pending', 'paid', etc.) con valores tipados.
 * Inmutable y auto-documentado — previene errores de tipeo en comparaciones.
 */
enum BookingStatus: string {
    case Pending      = 'pending';
    case Paid         = 'paid';
    case Failed       = 'failed';
    case Expired      = 'expired';
    case FraudReview  = 'fraud_review';
    case ManualReview = 'manual_review';

    /**
     * Verifica si el estado permite extension de hold.
     */
    public function isExtendable(): bool {
        return $this === self::Pending;
    }

    /**
     * Verifica si el estado es terminal (no puede cambiar).
     */
    public function isTerminal(): bool {
        return match ($this) {
            self::Paid, self::Expired, self::Failed, self::FraudReview => true,
            default => false,
        };
    }
}
