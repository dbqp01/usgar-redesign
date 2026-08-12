<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Container;
use App\Core\Request;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

/**
 * P2-5 (2026-08-12): el Router responde 405 + header Allow (RFC 9110 §9.6.2)
 * cuando la ruta existe pero con otro metodo HTTP (antes: 404).
 *
 * NOTA: en CLI/phpunit header() no se registra en headers_list() (el banner
 * de phpunit ya envio output); el header Allow se verifica en integracion
 * (php -S + curl, ver docs/STATE.md). Aqui se valida el contrato observable:
 * status 405 + cuerpo JSON METHOD_NOT_ALLOWED.
 */
final class RouterMethodNotAllowedTest extends TestCase {
    public function testMethodMismatchReturns405WithMethodNotAllowedJson(): void {
        $action = new class {
            public function __invoke(Request $request): void {
            }
        };
        $actionClass = $action::class;
        Container::getInstance()->set($actionClass, $action);

        $router = new Router();
        $router->get('/api/stub', $actionClass);

        ob_start();
        $router->dispatch(new Request('POST', '/api/stub'));
        $output = (string)ob_get_clean();

        $this->assertSame(405, http_response_code());
        $this->assertStringContainsString('METHOD_NOT_ALLOWED', $output);
    }

    public function testMatchingMethodDispatchesNormally(): void {
        $action = new class {
            public function __invoke(Request $request): void {
                echo 'DISPARADO';
            }
        };
        $actionClass = $action::class;
        Container::getInstance()->set($actionClass, $action);

        $router = new Router();
        $router->get('/api/stub2', $actionClass);

        ob_start();
        $router->dispatch(new Request('GET', '/api/stub2'));
        $output = (string)ob_get_clean();

        $this->assertSame('DISPARADO', $output);
    }

    public function testUnknownPathStillReturns404(): void {
        $router = new Router();

        ob_start();
        $router->dispatch(new Request('GET', '/api/no-existe'));
        $output = (string)ob_get_clean();

        $this->assertSame(404, http_response_code());
        $this->assertStringContainsString('not found', $output);
    }
}
