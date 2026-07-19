<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Gestión centralizada de configuración y variables de entorno.
 * Principio de Responsabilidad Única: solo lectura de .env y acceso a configuración.
 * Extraído de Database para cumplir SRP (Database solo debe manejar PDO).
 */
class Config {
    private static ?Config $instance = null;
    private array $cache = [];

    private function __construct() {
        $this->loadEnv();
    }

    /**
     * Inicializa la configuración al arrancar la aplicación.
     */
    public static function boot(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Obtiene una variable de configuración con fallback opcional.
     */
    public static function get(string $key, ?string $default = null): ?string {
        $instance = self::boot();
        return $instance->cache[$key] ?? (getenv($key) ?: $default);
    }

    /**
     * Verifica si el entorno actual es producción.
     */
    public static function isProduction(): bool {
        return self::get('ENVIRONMENT', 'development') === 'production';
    }

    /**
     * Retorna los orígenes CORS permitidos como array.
     * En desarrollo retorna ['*']. En producción solo dominios configurados.
     */
    public static function getAllowedOrigins(): array {
        $origins = self::get('ALLOWED_ORIGINS', '*');
        if ($origins === '*') {
            return ['*'];
        }
        return array_map('trim', explode(',', $origins));
    }

    /**
     * Retorna las IPs de proxies confiables para X-Forwarded-For.
     */
    public static function getTrustedProxies(): array {
        $proxies = self::get('TRUSTED_PROXIES', '');
        if (empty($proxies)) {
            return [];
        }
        return array_map('trim', explode(',', $proxies));
    }

    /**
     * Parsea el archivo .env de forma segura.
     * Compatible con Hostinger (hosting compartido, sin Composer).
     */
    private function loadEnv(): void {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';

        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Ignorar comentarios y líneas vacías
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            // Remover comillas únicamente si envuelven el valor completo
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            $this->cache[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}
