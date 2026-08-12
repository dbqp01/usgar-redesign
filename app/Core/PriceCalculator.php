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
}
