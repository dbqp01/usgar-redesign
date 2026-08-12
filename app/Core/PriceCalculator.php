<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Calculos de precio compartidos entre acciones y webhooks.
 */
class PriceCalculator {

    /**
     * Convierte un precio base en USD al precio de la pasarela (PEN) redondeado a 2 decimales.
     * $rate opcional: la tasa CONGELADA/CMSeada (qlo_currency); sin ella usa
     * Config::EXCHANGE_RATE_USD_PEN (fallback legacy/display).
     */
    public static function toGatewayPrice(float $priceUsd, ?float $rate = null): float {
        $exchangeRate = $rate ?? (float) Config::get('EXCHANGE_RATE_USD_PEN');
        return round($priceUsd * $exchangeRate, 2);
    }

    /**
     * Cargo por huesped adicional (regla del negocio, 2026-08-12): toda
     * habitacion admite +1 persona sobre su ocupancia base (base = maxGuests - 1)
     * pagando un cargo por noche a PRECIO COMPLETO (no se descuenta con la
     * tarifa no reembolsable — decision del negocio: -10% solo sobre el base).
     * Devuelve el cargo total: extraGuests * cargoPorNoche * nights.
     */
    public static function extraGuestCharge(int $guests, int $maxGuests, int $nights, float $extraChargePerNight): float {
        $baseOccupancy = max(1, $maxGuests - 1);
        $extraGuests = max(0, $guests - $baseOccupancy);
        return round($extraGuests * $extraChargePerNight * $nights, 2);
    }
}
