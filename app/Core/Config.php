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
    private static ?string $loadedEnvPath = null;
    /** @var array<string, string> */
    private array $cache = [];

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
     * Ruta del .env efectivamente cargado (null si ninguno).
     * Diagnostico de deploy: /api/health lo expone.
     */
    public static function loadedEnvPath(): ?string {
        return self::$loadedEnvPath;
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
     *
     * UNA sola ruta canónica (best practice: el .env nunca dentro del web root
     * — un servidor mal configurado podría servirlo como texto plano; y fuera
     * del árbol de deploy para que Git no lo borre ni lo regenere):
     *  - Producción (Hostinger): dirname del DOCUMENT_ROOT, subiendo hasta
     *    salir de public_html (cubre docroots en subcarpetas como dist/public).
     *  - Desarrollo/CLI (sin DOCUMENT_ROOT o sin public_html): raíz del proyecto.
     */
    private function loadEnv(): void {
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';

        // Candidatos en orden de prioridad:
        // 1. Arriba de public_html (best practice: fuera del web root)
        // 2. Hostinger .builds/config/.env (donde hPanel guarda las env vars)
        // 3. Raiz del proyecto (dev/CLI)
        $candidates = [];

        if (str_contains($docRoot, 'public_html')) {
            $above = dirname($docRoot);
            while (str_contains($above, 'public_html') && dirname($above) !== $above) {
                $above = dirname($above);
            }
            $candidates[] = $above . DIRECTORY_SEPARATOR . '.env';
            // Hostinger: .builds/config/.env vive al mismo nivel que public_html
            $candidates[] = $above . DIRECTORY_SEPARATOR . '.builds'
                . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . '.env';
        }

        // Dev/CLI fallback: raiz del proyecto
        $candidates[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';

        $envPath = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $envPath = $candidate;
                break;
            }
        }

        if ($envPath === null) {
            error_log('[Config] WARNING: No .env file found. Checked: '
                . implode(', ', $candidates));
            return;
        }

        self::$loadedEnvPath = $envPath;

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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
