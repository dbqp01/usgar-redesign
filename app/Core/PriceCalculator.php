<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Calculos de precio compartidos entre acciones y webhooks.
 */
class PriceCalculator {

    /**
     * Convierte un precio base en USD al precio de la pasarela (PEN) redondeado a 2 decimales.
     * Config: EXCHANGE_RATE_USD_PEN.
     */
    public static function toGatewayPrice(float $priceUsd): float {
        $exchangeRate = (float) Config::get('EXCHANGE_RATE_USD_PEN');
        return round($priceUsd * $exchangeRate, 2);
    }
}
