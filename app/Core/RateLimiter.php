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

    private static function init(int $windowSeconds): void {
        if (self::$limitsDir === '') {
            // Guardar datos fuera del directorio publico por seguridad
            self::$limitsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'limits';
            if (!is_dir(self::$limitsDir)) {
                @mkdir(self::$limitsDir, 0755, true);
            }
        }
        // P3-3 (2026-08-12, OWASP API4:2023 resource consumption): los archivos
        // limit_*.json son inmortales si nadie los purga. Umbral = 2 ventanas:
        // cubre la ventana mas larga del repo (auth 900s < 2x600s) sin borrar
        // contadores vivos. Costo O(n) de un glob por peticion: aceptable
        // (n = IPs distintas en la ventana; ponytail: mover a purga probabilistica
        // 1/N si el directorio crece).
        self::purgeOldFiles(self::$limitsDir, 2 * $windowSeconds);
    }

    /**
     * P3-3: elimina archivos de limites cuyo mtime supera maxAgeSeconds.
     * Publico y con dir como parametro para testeo hermetico (sin tocar data/).
     *
     * @return int Archivos eliminados
     */
    public static function purgeOldFiles(string $dir, int $maxAgeSeconds): int {
        $cutoff = time() - $maxAgeSeconds;
        $removed = 0;
        foreach (glob($dir . DIRECTORY_SEPARATOR . 'limit_*.json') ?: [] as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Valida si la IP del cliente no ha excedido la tasa maxima en la ventana de tiempo.
     * Los limites por defecto se leen de Config (RATE_LIMIT_MAX_REQUESTS / RATE_LIMIT_WINDOW_SECONDS).
     *
     * @param string $ip               Direccion IP del cliente
     * @param int    $maxRequests       Cantidad maxima de peticiones
     * @param int    $timeWindowSeconds Ventana de tiempo en segundos
     * @return bool True si la peticion esta permitida, False si esta limitada
     */
    public static function check(string $ip, ?int $maxRequests = null, ?int $timeWindowSeconds = null): bool {
        $maxRequests = $maxRequests ?? (int)(Config::get('RATE_LIMIT_MAX_REQUESTS', '5') ?? '5');
        $timeWindowSeconds = $timeWindowSeconds ?? (int)(Config::get('RATE_LIMIT_WINDOW_SECONDS', '600') ?? '600');
        self::init($timeWindowSeconds);

        // SHA-256 para resistencia a colisiones (md5 es insuficiente)
        $ipHash = hash('sha256', $ip);
        $limitFile = self::$limitsDir . DIRECTORY_SEPARATOR . "limit_{$ipHash}.json";
        $now = time();

        $fp = @fopen($limitFile, 'c+');
        if (!$fp) {
            // Si el archivo no se puede abrir, permitir la peticion como fallback
            return true;
        }

        // Bloqueo exclusivo que cubre lectura + filtrado + escritura
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return true;
        }

        $requests = [];
        $fileSize = filesize($limitFile);
        if ($fileSize > 0) {
            $content = fread($fp, $fileSize);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $requests = $data;
                }
            }
        }

        // Filtrar peticiones fuera de la ventana de tiempo
        $cutoff = $now - $timeWindowSeconds;
        $requests = array_values(array_filter($requests, fn(int $timestamp) => $timestamp > $cutoff));

        if (count($requests) >= $maxRequests) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }

        // Agregar peticion actual
        $requests[] = $now;

        // Truncar y escribir estado actualizado de forma atomica
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($requests) ?: '[]');
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return true;
    }
}
