<?php
declare(strict_types=1);

namespace App\Core;

/**
 * P3-1 (2026-08-12, RFC 9111 via http.dev/cache-control): cache de display en
 * archivo para respuestas GET que toleran frescura de 30-60s (GET /api/rooms).
 *
 * - Archivos auto-expirados por mtime en la lectura: sin purga necesaria.
 * - Mismo patron que RateLimiter: dir fuera de public/, sha256, LOCK_EX.
 * - Escritura atomica (tmp + rename): nunca se lee un JSON a medias.
 *
 * NUNCA usar para escrituras transaccionales (CreateBookingAction re-verifica
 * con FOR UPDATE): esto es solo vitrina.
 */
class FileCache {
    private static string $cacheDir = '';

    /**
     * @param string      $key        Clave de cache (se hashea sha256)
     * @param int         $ttlSeconds Vida util en segundos (0 = nunca expira)
     * @param string|null $dir        Override de directorio (solo tests)
     * @return array<string, mixed>|null null si miss o expirado
     */
    public static function get(string $key, int $ttlSeconds, ?string $dir = null): ?array {
        self::init($dir);
        $file = self::cacheFile($key);
        if (!is_file($file)) {
            return null;
        }
        if ($ttlSeconds > 0 && time() - (int)@filemtime($file) > $ttlSeconds) {
            return null;
        }
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function set(string $key, array $data, ?string $dir = null): bool {
        self::init($dir);
        $file = self::cacheFile($key);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        return @rename($tmp, $file);
    }

    private static function cacheFile(string $key): string {
        return self::$cacheDir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }

    private static function init(?string $dir): void {
        if ($dir !== null) {
            self::$cacheDir = $dir;
            return;
        }
        if (self::$cacheDir === '') {
            self::$cacheDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cache';
            if (!is_dir(self::$cacheDir)) {
                @mkdir(self::$cacheDir, 0755, true);
            }
        }
    }
}
