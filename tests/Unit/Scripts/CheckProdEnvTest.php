<?php
declare(strict_types=1);

namespace App\Test\Unit\Scripts;

// El guard vive en scripts/check-prod-env.php (self-contained); el test lo
// incluye con require_once — el guard CLI no se ejecuta porque $argv[0] es
// el binario de PHPUnit, no el script.
require_once __DIR__ . '/../../../scripts/check-prod-env.php';

use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Scripts\CheckProdEnv;

/**
 * Tests del guard de deploy (todo 33, Wave 6): el MERCADO_PAGO_ACCESS_TOKEN
 * debe pertenecer a la ALLOWLIST POR HASH (sha256) — NUNCA validacion por
 * prefijo: la doc MP vigente confirma que los tokens de PRUEBA tambien
 * empiezan con APP_USR (Checkout Pro/Orders) y que el prefijo puede variar
 * por solucion; el entorno lo define la app/panel.
 *
 * 2026-08-15: el guard aplica SIEMPRE (fail-closed) — APP_ENV se elimino de
 * raiz, ya no hay rama "fuera de produccion que se omite". Sin allowlist o
 * sin token, el guard bloquea (exit 1), que es el comportamiento correcto
 * para un script manual de pre-deploy.
 *
 * La allowlist real la provee el USUARIO en .env.production (fuera de git);
 * los tests usan un HASH SINTETICO (nunca un token real).
 */
final class CheckProdEnvTest extends TestCase {
    private const SYNTHETIC_PROD_TOKEN = 'APP_USR-synthetic-prod-token-000000';

    protected function setUp(): void {
        Config::set('MERCADO_PAGO_ACCESS_TOKEN', 'TEST-8500000000000');
        Config::set('MERCADO_PAGO_PROD_TOKEN_SHA256', '');
    }

    protected function tearDown(): void {
        Config::set('MERCADO_PAGO_ACCESS_TOKEN', '');
        Config::set('MERCADO_PAGO_PROD_TOKEN_SHA256', '');
    }

    public function testRejectsTokenNotInAllowlist(): void {
        // Token de prueba -> el guard FALLA (exit 1) aunque el prefijo sea
        // TEST- o APP_USR-.
        Config::set('MERCADO_PAGO_ACCESS_TOKEN', 'TEST-8500000000000');
        Config::set('MERCADO_PAGO_PROD_TOKEN_SHA256', hash('sha256', self::SYNTHETIC_PROD_TOKEN));

        $this->assertSame(1, CheckProdEnv::run(), 'Token fuera de la allowlist debe bloquear el deploy.');
    }

    public function testAcceptsTokenWhoseHashIsInAllowlist(): void {
        // QA+: hash sintetico en la allowlist -> el guard pasa (exit 0).
        Config::set('MERCADO_PAGO_ACCESS_TOKEN', self::SYNTHETIC_PROD_TOKEN);
        Config::set('MERCADO_PAGO_PROD_TOKEN_SHA256', hash('sha256', self::SYNTHETIC_PROD_TOKEN));

        $this->assertSame(0, CheckProdEnv::run(), 'Token con hash en la allowlist debe pasar el guard.');
    }

    public function testFailsClosedWithoutAllowlistConfigured(): void {
        // Sin allowlist configurada -> fail-closed (exit 1), nunca permitir
        // el deploy "por si acaso".
        Config::set('MERCADO_PAGO_ACCESS_TOKEN', self::SYNTHETIC_PROD_TOKEN);
        Config::set('MERCADO_PAGO_PROD_TOKEN_SHA256', '');

        $this->assertSame(1, CheckProdEnv::run(), 'Sin allowlist, el guard debe bloquearse (fail-closed).');
    }

    public function testFailsClosedWithoutTokenConfigured(): void {
        Config::set('MERCADO_PAGO_ACCESS_TOKEN', '');
        Config::set('MERCADO_PAGO_PROD_TOKEN_SHA256', hash('sha256', self::SYNTHETIC_PROD_TOKEN));

        $this->assertSame(1, CheckProdEnv::run(), 'Sin token, el guard debe bloquearse.');
    }
}
