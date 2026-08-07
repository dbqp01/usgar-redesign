<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Prefijos de identificadores de carrito / reserva del dominio.
 * Fuente unica para evitar strings magicos en adaptadores y webhooks.
 */
final class CartIdPrefix {
    public const QLOAPPS_LOCAL = 'USGAR-';
    public const OTA           = 'OTA-';
}
