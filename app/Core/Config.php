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
    private static ?string $deploymentBase = null;
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
        'EXTRA_GUEST_CHARGE_USD' => '30',
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
     * Raiz del despliegue: el directorio un nivel arriba de public_html
     * (la raiz del dominio en Hostinger). null en dev/CLI sin public_html.
     * Vive fuera del arbol que el deploy reemplaza: ahi pueden escribir los
     * logs sin que un push los borre.
     */
    public static function deploymentBase(): ?string {
        self::boot();
        return self::$deploymentBase;
    }

    /**
     * Timestamp absoluto de expiracion del hold de reserva.
     * El valor de BOOKING_HOLD_TTL es relativo (ej. '+15 minutes') y pasa por
     * strtotime: si el .env lo trae mal escrito, strtotime devuelve false y
     * date() producia 1970-01-01 (hold expiraba al instante). Fallback
     * conservador a +15 min con log. $ttl es inyectable para testeo hermetico
     * (mismo patron que RateLimiter::purgeOldFiles).
     */
    public static function holdExpirationTimestamp(?string $ttl = null): int {
        $ttl ??= self::get('BOOKING_HOLD_TTL', '+15 minutes') ?? '+15 minutes';
        $ts = strtotime($ttl);
        if ($ts === false) {
            error_log("[Config] BOOKING_HOLD_TTL invalido ('{$ttl}'), usando +15 minutes.");
            return time() + 900;
        }
        return $ts;
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
     *
     * @return list<string>
     */
    public static function getAllowedOrigins(): array {
        $configured = getenv('ALLOWED_ORIGINS');
        if ($configured === false || trim($configured) === '') {
            // Keep local development usable without an env file, but fail
            // closed for deployed hosts when the authoritative allowlist is missing.
            $siteUrl = self::get('SITE_URL', '') ?? '';
            $host = strtolower((string)(parse_url($siteUrl, PHP_URL_HOST) ?: ''));
            return in_array($host, ['localhost', '127.0.0.1', '::1'], true) ? ['*'] : [];
        }
        $origins = trim($configured);
        if ($origins === '*') {
            return ['*'];
        }
        return array_values(array_filter(array_map('trim', explode(',', $origins)), static fn(string $origin): bool => $origin !== ''));
    }

    /**
     * Retorna las IPs de proxies confiables para X-Forwarded-For.
     *
     * @return list<string>
     */
    public static function getTrustedProxies(): array {
        $proxies = self::get('TRUSTED_PROXIES', '');
        if (empty($proxies)) {
            return [];
        }
        return array_map('trim', explode(',', $proxies));
    }

    /**
     * Rutas candidatas del .env, en orden de prioridad.
     *
     * En produccion (Hostinger) la UNICA fuente es hPanel -> Despliegues ->
     * Ajustes -> Variables de entorno, que escribe `hbuilds/config/.env` en la
     * raiz del dominio. Sin fallbacks a proposito: un `.env` suelto en la raiz
     * o en `public_html` no puede tapar nunca lo que se guarda en el panel, asi
     * rotar una clave desde hPanel surte efecto en el siguiente request.
     *
     * @return list<string>
     */
    public static function envCandidates(?string $deploymentBase): array {
        if ($deploymentBase !== null) {
            return [$deploymentBase . DIRECTORY_SEPARATOR . 'hbuilds'
                . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . '.env'];
        }

        $candidates = [];

        // Override explicito para tests: ENV_FILE=/ruta/.env.test.
        $override = getenv('ENV_FILE');
        if (is_string($override) && $override !== '') {
            $candidates[] = $override;
        }

        // Dev/CLI fallback: raiz del proyecto (en local suele ser el unico .env).
        $candidates[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';

        return $candidates;
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

        $deploymentBase = null;
        if (str_contains($docRoot, 'public_html')) {
            $above = dirname($docRoot);
            while (str_contains($above, 'public_html') && dirname($above) !== $above) {
                $above = dirname($above);
            }
            $deploymentBase = $above;
        } elseif (PHP_SAPI === 'cli') {
            // Cron no recibe DOCUMENT_ROOT. Detectar public_html en el árbol
            // real del código para mantener la misma precedencia que HTTP.
            $probe = __DIR__;
            while ($probe !== dirname($probe)) {
                if (strcasecmp(basename($probe), 'public_html') === 0) {
                    $deploymentBase = dirname($probe);
                    break;
                }
                $probe = dirname($probe);
            }
        }

        if ($deploymentBase !== null) {
            // Hostinger: la configuración canónica vive fuera de public_html;
            // nunca permitir que un .env residual del deploy gane prioridad.
            self::$deploymentBase = $deploymentBase;
        }

        $candidates = self::envCandidates($deploymentBase);

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

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $parsed = self::parseLine($line);
            if ($parsed === null) {
                continue;
            }
            $this->cache[$parsed['key']] = $parsed['value'];
            putenv("{$parsed['key']}={$parsed['value']}");
        }
    }

    /**
     * Parsea una linea del .env a [key, value].
     * Comentarios inline (" #") SOLO se recortan cuando el valor no esta
     * entre comillas (si no, contrasenas con " #" se truncan; ej.
     * DB_PASS="ab #cd" se cortaba a "ab").
     *
     * @return array{key: string, value: string}|null
     */
    public static function parseLine(string $line): ?array {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $key = trim($parts[0]);
        if ($key === '') {
            return null;
        }

        $value = trim($parts[1]);

        // Comentario inline solo cuando el valor no esta envuelto en comillas
        if (!str_starts_with($value, '"') && !str_starts_with($value, "'") && str_contains($value, ' #')) {
            $value = trim(explode(' #', $value, 2)[0]);
        }

        // Remover comillas unicamente si envuelven el valor completo
        if (strlen($value) >= 2 && (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        )) {
            $value = substr($value, 1, -1);
        }

        return ['key' => $key, 'value' => $value];
    }
}
