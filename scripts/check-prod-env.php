<?php
declare(strict_types=1);

namespace App\Scripts;

use App\Core\Config;

/**
 * Guard de deploy (todo 33, Wave 6): en APP_ENV=production exige que
 * MERCADO_PAGO_ACCESS_TOKEN pertenezca a la ALLOWLIST configurada por
 * HASH (sha256) — NUNCA por prefijo de token: la doc MP vigente confirma
 * que los tokens de PRUEBA tambien empiezan con APP_USR (Checkout Pro y
 * Orders crean la cuenta de prueba automaticamente con prefijo APP_USR) y
 * que el prefijo puede variar segun la solucion; el entorno lo define la
 * app/panel, no el prefijo.
 *
 * La allowlist la provee el USUARIO en `.env.production` (archivo FUERA de
 * git) con la clave:
 *   MERCADO_PAGO_PROD_TOKEN_SHA256=<sha256 del access token de produccion>
 * (soporta varios hashes separados por coma/espacio para rotacion). El
 * hash se obtiene de Tus integraciones: panel -> app -> Credenciales de
 * produccion -> Access Token. NUNCA se escribe el token en claro en
 * archivos del repo.
 *
 * Uso: php scripts/check-prod-env.php   (exit 0 = OK, 1 = bloquea el deploy)
 */
final class CheckProdEnv {
    private const ALLOWLIST_KEY = 'MERCADO_PAGO_PROD_TOKEN_SHA256';

    public static function run(): int {
        $appEnv = (string)Config::get('APP_ENV', 'development');

        if ($appEnv !== 'production') {
            echo "[check-prod-env] APP_ENV={$appEnv}: guard omitido (solo aplica en produccion).\n";
            return 0;
        }

        $token = (string)Config::get('MERCADO_PAGO_ACCESS_TOKEN', '');
        if ($token === '') {
            fwrite(STDERR, "[check-prod-env] FAIL: MERCADO_PAGO_ACCESS_TOKEN no esta configurado en produccion.\n");
            return 1;
        }

        $allowlist = self::resolveAllowlist();
        if ($allowlist === []) {
            fwrite(STDERR, "[check-prod-env] FAIL: allowlist de produccion no configurada. Anade "
                . self::ALLOWLIST_KEY . "=<sha256 del access token de produccion> a .env.production "
                . "(archivo fuera de git; solo hashes, nunca el token en claro). "
                . "El hash se genera con: php -r \"echo hash('sha256', getenv('MERCADO_PAGO_ACCESS_TOKEN'));\"\n");
            return 1;
        }

        $tokenHash = hash('sha256', $token);
        if (!in_array($tokenHash, $allowlist, true)) {
            fwrite(STDERR, "[check-prod-env] FAIL: el MERCADO_PAGO_ACCESS_TOKEN configurado NO esta en la "
                . "allowlist de produccion (sha256 " . substr($tokenHash, 0, 12) . "...). "
                . "El prefijo no define el entorno (los tokens de prueba tambien usan APP_USR — doc MP); "
                . "verifica el token en Tus integraciones y actualiza .env.production.\n");
            return 1;
        }

        echo "[check-prod-env] OK: access token en la allowlist de produccion.\n";
        return 0;
    }

    /**
     * Allowlist de hashes sha256: primero Config (env/`.env`), luego el
     * archivo `.env.production` (parseo minimo KEY=VALUE, fuera de git).
     *
     * @return list<string>
     */
    private static function resolveAllowlist(): array {
        $raw = trim((string)Config::get(self::ALLOWLIST_KEY, ''));
        if ($raw === '') {
            $raw = trim(self::readFromEnvProduction());
        }
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,]+/', $raw) ?: [];
        $hashes = [];
        foreach ($parts as $part) {
            $part = strtolower(trim($part));
            if ($part !== '' && preg_match('/^[0-9a-f]{64}$/', $part)) {
                $hashes[] = $part;
            }
        }
        return $hashes;
    }

    private static function readFromEnvProduction(): string {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env.production';
        if (!is_file($path)) {
            return '';
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            if (trim($key) === self::ALLOWLIST_KEY) {
                return trim($value);
            }
        }
        return '';
    }
}

// Guard CLI: solo se ejecuta al invocar el script directamente
// (php scripts/check-prod-env.php), nunca al ser incluido por PHPUnit.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../app/Core/Autoloader.php';
    \App\Core\Autoloader::register(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app');
    exit(\App\Scripts\CheckProdEnv::run());
}
