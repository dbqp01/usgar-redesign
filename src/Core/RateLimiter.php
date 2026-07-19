<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Limitador de Tasa de Peticiones basado en almacenamiento en archivos.
 * Fix: SHA-256 en vez de MD5 para resistencia a colisiones.
 * Fix: LOCK_EX en file_put_contents para atomicidad en escritura.
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
     * @param string $ip               Dirección IP del cliente
     * @param int    $maxRequests       Cantidad máxima de peticiones
     * @param int    $timeWindowSeconds Ventana de tiempo en segundos
     * @return bool True si la petición está permitida, False si está limitada
     */
    public static function check(string $ip, int $maxRequests = 5, int $timeWindowSeconds = 600): bool {
        self::init();

        // SHA-256 para resistencia a colisiones (md5 es insuficiente)
        $ipHash = hash('sha256', $ip);
        $limitFile = self::$limitsDir . DIRECTORY_SEPARATOR . "limit_{$ipHash}.json";
        $now = time();

        $requests = [];
        if (file_exists($limitFile)) {
            $content = file_get_contents($limitFile);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $requests = $data;
                }
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

        // Guardar con LOCK_EX para atomicidad (previene race conditions)
        @file_put_contents($limitFile, json_encode(array_values($requests)), LOCK_EX);
        return true;
    }

    /**
     * Limpia los archivos de rate limit expirados para evitar saturar el disco.
     */
    public static function cleanup(int $windowSeconds = 600): int {
        self::init();
        if (!is_dir(self::$limitsDir)) {
            return 0;
        }

        $files = glob(self::$limitsDir . DIRECTORY_SEPARATOR . 'limit_*.json');
        if (!is_array($files)) {
            return 0;
        }

        $now = time();
        $cutoff = $now - $windowSeconds;
        $deletedCount = 0;

        foreach ($files as $file) {
            $mtime = filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                if (@unlink($file)) {
                    $deletedCount++;
                }
            }
        }

        return $deletedCount;
    }
}
