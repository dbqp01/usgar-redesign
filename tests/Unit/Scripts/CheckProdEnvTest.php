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
 * Tests del guard de deploy (todo 33, Wave 6): en APP_ENV=production el
 * MERCADO_PAGO_ACCESS_TOKEN debe pertenecer a la ALLOWLIST POR HASH (sha256)
 * — NUNCA validacion por prefijo: la doc MP vigente confirma que los tokens
 * de PRUEBA tambien empiezan con APP_USR (Checkout Pro/Orders) y que el
 * prefijo puede variar por solucion; el entorno lo define la app/panel.
 *
 * La allowlist real la provee el USUARIO en .env.production (fuera de git);
 * los tests usan un HASH SINTETICO (nunca un token real).
 */
final class CheckProdEnvTest extends TestCase {
    private const SYNTHETIC_PROD_TOKEN = 'APP_USR-synthetic-prod-token-000000';

    protected function setUp(): void {
        Config::set('APP_ENV', 'testing');
        Config::set('MERCADO_PAGO_ACCESS_TOKEN', 'TEST-8500000000000');
        Config::set('MERCADO_PAGO_PROD_TOKEN_SHA256', '');
    }

    protected function tearDown(): void {
        Config::set('APP_ENV', 'testing');
        Config::set('MERCADO_PAGO_ACCESS_TOKEN', '');
        Config::set('MERCADO_PAGO_PROD_TOKEN_SHA256', '');
    }

    public function testProductionRejectsTokenNotInAllowlist(): void {
        // QA-: APP_ENV=production con token de prueba -> el guard FALLA
        // (exit 1) aunque el prefijo sea TEST- o APP_USR-.
        Config::set('APP_ENV', 'production');
        Config::set('MERCADO_PAGO_ACCESS_TOKEN', 'TEST-8500000000000');
        Config::set('MERCADO_PAGO_PROD_TOKEN_SHA256', hash('sha256', self::SYNTHETIC_PROD_TOKEN));

        $this->assertSame(1, CheckProdEnv::run(), 'Token fuera de la allowlist debe bloquear el deploy.');
    }

    public function testProductionAcceptsTokenWhoseHashIsInAllowlist(): void {
        // QA+: hash sintetico en la allowlist -> el guard pasa (exit 0).
        Config::set('APP_ENV', 'production');
        Config::set('MERCADO_PAGO_ACCESS_TOKEN', self::SYNTHETIC_PROD_TOKEN);
        Config::set('MERCADO_PAGO_PROD_TOKEN_SHA256', hash('sha256', self::SYNTHETIC_PROD_TOKEN));

        $this->assertSame(0, CheckProdEnv::run(), 'Token con hash en la allowlist debe pasar el guard.');
    }

    public function testProductionFailsClosedWithoutAllowlistConfigured(): void {
        // Sin allowlist configurada -> fail-closed (exit 1), nunca permitir
        // el deploy "por si acaso".
        Config::set('APP_ENV', 'production');
        Config::set('MERCADO_PAGO_ACCESS_TOKEN', self::SYNTHETIC_PROD_TOKEN);
        Config::set('MERCADO_PAGO_PROD_TOKEN_SHA256', '');

        $this->assertSame(1, CheckProdEnv::run(), 'Sin allowlist, produccion debe bloquearse (fail-closed).');
    }

    public function testProductionFailsClosedWithoutTokenConfigured(): void {
        Config::set('APP_ENV', 'production');
        Config::set('MERCADO_PAGO_ACCESS_TOKEN', '');
        Config::set('MERCADO_PAGO_PROD_TOKEN_SHA256', hash('sha256', self::SYNTHETIC_PROD_TOKEN));

        $this->assertSame(1, CheckProdEnv::run(), 'Sin token, produccion debe bloquearse.');
    }

    public function testNonProductionSkipsGuard(): void {
        // Fuera de produccion el guard es no-op (exit 0).
        Config::set('APP_ENV', 'testing');
        Config::set('MERCADO_PAGO_ACCESS_TOKEN', 'TEST-8500000000000');
        Config::set('MERCADO_PAGO_PROD_TOKEN_SHA256', '');

        $this->assertSame(0, CheckProdEnv::run(), 'APP_ENV != production no debe bloquear.');
    }
}
