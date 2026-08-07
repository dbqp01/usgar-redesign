<?php
declare(strict_types=1);

namespace App\Core;

use Exception;

/**
 * Utilidad de reintento con backoff exponencial para integraciones externas.
 * Patron comun en listeners de dominio (PMS / Channel Manager).
 */
class Retry {

    /**
     * Ejecuta una operacion con reintentos y backoff exponencial.
     * La operacion recibe el numero de intento (1-based) y debe lanzar
     * una excepcion para que se reintente; si retorna un valor (incluido
     * null o false) se considera exito y no se reintenta.
     *
     * @param callable(int): mixed $operation
     * @return mixed Valor devuelto por la operacion
     * @throws Exception Si la operacion falla en todos los reintentos
     */
    public static function withBackoff(callable $operation, int $maxRetries = 3): mixed {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $operation($attempt);
            } catch (Exception $e) {
                if ($attempt >= $maxRetries) {
                    throw $e;
                }
                sleep(2 ** ($attempt - 1));
            }
        }
    }
}
