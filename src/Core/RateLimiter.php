<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Limitador de Tasa de Peticiones basado en almacenamiento en archivos.
 * Registra marcas de tiempo en archivos JSON para limitar el abuso.
 * Adecuado para servidores compartidos sin Redis ni Memcached.
 */
class RateLimiter {
    private static string $limitsDir = '';

    private static function init(): void {
        if (self::$limitsDir === '') {
            // Guardar datos fuera del directorio público por seguridad
            self::$limitsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'limits';
            if (!is_dir(self::$limitsDir)) {
                @mkdir(self::$limitsDir, 0755, true);
            }
        }
    }

    /**
     * Valida si la IP del cliente no ha excedido la tasa máxima en la ventana de tiempo.
     *
     * @param string $ip Dirección IP del cliente
     * @param int $maxRequests Cantidad máxima de peticiones
     * @param int $timeWindowSeconds Ventana de tiempo en segundos
     * @return bool True si la petición está permitida, False si está limitada
     */
    public static function check(string $ip, int $maxRequests = 5, int $timeWindowSeconds = 600): bool {
        self::init();

        $ipHash = md5($ip);
        $limitFile = self::$limitsDir . DIRECTORY_SEPARATOR . "limit_{$ipHash}.json";
        $now = time();

        $requests = [];
        if (file_exists($limitFile)) {
            $content = file_get_contents($limitFile);
            $data = json_decode($content, true);
            if (is_array($data)) {
                $requests = $data;
            }
        }

        // Filtrar peticiones fuera de la ventana de tiempo
        $cutoff = $now - $timeWindowSeconds;
        $requests = array_filter($requests, fn(int $timestamp) => $timestamp > $cutoff);

        if (count($requests) >= $maxRequests) {
            return false;
        }

        // Agregar petición actual
        $requests[] = $now;
        
        // Guardar cambios en el archivo
        @file_put_contents($limitFile, json_encode(array_values($requests)));
        return true;
    }
}
