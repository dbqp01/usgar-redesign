<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Deriva el token HMAC de acceso a un hold de reserva.
 * Fuente unica para el uso de BOOKING_TOKEN_SECRET (fallback CRON_SECRET).
 */
class BookingHoldToken {

    /**
     * Deriva el token HMAC-SHA256 de un carrito a partir del email del huesped.
     *
     * @throws HttpException Si no hay BOOKING_TOKEN_SECRET configurado en el servidor.
     */
    public static function derive(string $cartId, string $guestEmail): string {
        $secretKey = Config::get('BOOKING_TOKEN_SECRET', Config::get('CRON_SECRET'));
        if (empty($secretKey)) {
            Logger::error('BookingHoldToken: BOOKING_TOKEN_SECRET no esta configurado en servidor.');
            throw HttpException::internal('Configuracion de seguridad de token no disponible.');
        }
        return hash_hmac('sha256', $cartId . ':' . $guestEmail, $secretKey);
    }
}
