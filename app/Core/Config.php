<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Gestion centralizada de configuracion y variables de entorno.
 * Principio de Responsabilidad Unica: solo lectura de .env y acceso a configuracion.
 * Extraido de Database para cumplir SRP (Database solo debe manejar PDO).
 */
class Config {
    private static ?Config $instance = null;
    private array $cache = [];
    private bool $envFileFound = false;

    private function __construct() {
        $this->loadEnv();
    }

    /**
     * Inicializa la configuracion al arrancar la aplicacion.
     */
    public static function boot(): self {
        if (self::$instance === null) {
            self::$instance = new self();
            date_default_timezone_set(self::get('TIMEZONE', 'America/Lima'));
        }
        return self::$instance;
    }

    private const DEFAULTS = [
        'SITE_URL'             => 'https://usgarhoteles.com',
        'TIMEZONE'             => 'America/Lima',
        'DB_HOST'              => '127.0.0.1',
        'DB_PORT'              => '3306',
        'DB_NAME'              => 'usgar_hotels',
        'ALLOWED_ORIGINS'      => '*',
        'DEFAULT_HOTEL_ID'     => '1',
        'EXCHANGE_RATE_USD_PEN'=> '3.80',
        'DEFAULT_GUEST_EMAIL'  => 'reserva@usgarhoteles.com',
        'DEFAULT_REPLY_EMAIL'  => 'no-reply@usgarhoteles.com',
        'RATE_LIMIT_MAX_REQUESTS'   => '300',
        'RATE_LIMIT_WINDOW_SECONDS' => '600',
    ];

    /**
     * Obtiene una variable de configuracion con fallback opcional.
     */
    public static function get(string $key, ?string $default = null): ?string {
        $instance = self::boot();
        $fallback = $default ?? (self::DEFAULTS[$key] ?? null);
        $envVal = getenv($key);
        return $instance->cache[$key] ?? ($envVal !== false && $envVal !== '' ? $envVal : $fallback);
    }

    /**
     * Define o sobreescribe una variable de configuracion en runtime/testing.
     */
    public static function set(string $key, string $value): void {
        $instance = self::boot();
        $instance->cache[$key] = $value;
        putenv("{$key}={$value}");
    }

    /**
     * Retorna los origenes CORS permitidos como array.
     * En desarrollo retorna ['*']. En produccion solo dominios configurados.
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
        $possiblePaths = array_unique(array_filter([
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env',
            ($_SERVER['DOCUMENT_ROOT'] ?? '') . DIRECTORY_SEPARATOR . '.env',
            dirname($_SERVER['DOCUMENT_ROOT'] ?? '') . DIRECTORY_SEPARATOR . '.env',
        ]));

        $path = null;
        foreach ($possiblePaths as $p) {
            if (file_exists($p)) {
                $path = $p;
                break;
            }
        }

        if (!$path) {
            // No .env file found - log warning in case this is a production server
            $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
            if (str_contains($docRoot, 'public_html')) {
                error_log('[Config] WARNING: No .env file found on what appears to be a production server. Checked: ' . implode(', ', $possiblePaths));
            }
            return;
        }
        $this->envFileFound = true;

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if (str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, ' #')) {
                $partsComment = explode(' #', $line, 2);
                $line = trim($partsComment[0]);
            }
            if ($line === '') {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            // Remover comillas unicamente si envuelven el valor completo
            if (strlen($value) >= 2 && (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            )) {
                $value = substr($value, 1, -1);
            }

            $this->cache[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}
