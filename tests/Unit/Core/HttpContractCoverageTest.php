<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Container;
use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

/**
 * Cobertura del contrato transversal HTTP.
 *
 * Estas pruebas se centran en lo que observa un cliente: status, envelope,
 * dispatch del handler y orden del pipeline. No prueban detalles privados del
 * Router ni requieren un servidor HTTP real.
 */
final class HttpContractCoverageTest extends TestCase {
    public function testResponseErrorOmitsEmptyDetailsAndUsesItsContract(): void {
        ob_start();
        Response::error('No encontrado', 404, 'NOT_FOUND');
        $payload = json_decode((string)ob_get_clean(), true);

        self::assertSame(404, http_response_code());
        self::assertSame([
            'success' => false,
            'error' => [
                'code' => 'NOT_FOUND',
                'message' => 'No encontrado',
            ],
        ], $payload);
    }

    public function testHttpExceptionFactoriesExposeStatusCodeErrorCodeAndDetails(): void {
        $badRequest = HttpException::badRequest('Datos invalidos', ['field' => 'email']);
        self::assertSame(400, $badRequest->getStatusCode());
        self::assertSame(400, $badRequest->getCode());
        self::assertSame('BAD_REQUEST', $badRequest->getErrorCode());
        self::assertSame(['field' => 'email'], $badRequest->getDetails());

        $unauthorized = HttpException::unauthorized();
        self::assertSame(401, $unauthorized->getStatusCode());
        self::assertSame('UNAUTHORIZED', $unauthorized->getErrorCode());

        $forbidden = HttpException::forbidden();
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame('FORBIDDEN', $forbidden->getErrorCode());

        $notFound = HttpException::notFound();
        self::assertSame(404, $notFound->getStatusCode());
        self::assertSame('NOT_FOUND', $notFound->getErrorCode());

        $tooMany = HttpException::tooManyRequests();
        self::assertSame(429, $tooMany->getStatusCode());
        self::assertSame('TOO_MANY_REQUESTS', $tooMany->getErrorCode());

        $internal = HttpException::internal();
        self::assertSame(500, $internal->getStatusCode());
        self::assertSame('INTERNAL_ERROR', $internal->getErrorCode());
    }

    public function testMiddlewareRunsInRegistrationOrder(): void {
        $events = [];
        $middleware = (new Middleware())
            ->add(static function (Request $request) use (&$events): void {
                $events[] = 'first';
            })
            ->add(static function (Request $request) use (&$events): void {
                $events[] = 'second';
            });

        $middleware->run(new Request('GET', '/api/test'));

        self::assertSame(['first', 'second'], $events);
    }

    public function testMiddlewareExceptionStopsLaterHandlers(): void {
        $events = [];
        $middleware = (new Middleware())
            ->add(static function (Request $request) use (&$events): void {
                $events[] = 'first';
                throw HttpException::forbidden('Bloqueado');
            })
            ->add(static function (Request $request) use (&$events): void {
                $events[] = 'second';
            });

        try {
            $middleware->run(new Request('GET', '/api/test'));
            self::fail('El middleware debio propagar HttpException.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
            self::assertSame('FORBIDDEN', $exception->getErrorCode());
        }

        self::assertSame(['first'], $events);
    }

    public function testRouterAcceptsOptionsWhenMiddlewareAllowsIt(): void {
        $router = new Router();
        $router->setMiddleware((new Middleware())->add(static function (Request $request): void {
        }));

        ob_start();
        $router->dispatch(new Request('OPTIONS', '/api/test'));
        $output = (string)ob_get_clean();

        self::assertSame('', $output);
        self::assertSame(204, http_response_code());
    }

    public function testRouterDispatchesArrayHandler(): void {
        $action = new class {
            public function run(Request $request): void {
                echo 'ARRAY_HANDLER';
            }
        };
        Container::getInstance()->set($action::class, $action);

        $router = new Router();
        $router->get('/api/array/', [$action::class, 'run']);

        ob_start();
        $router->dispatch(new Request('GET', '/api/array'));
        $output = (string)ob_get_clean();

        self::assertSame('ARRAY_HANDLER', $output);
    }

    public function testRouterNormalizesRepeatedPathSeparators(): void {
        $action = new class {
            public function __invoke(Request $request): void {
                echo 'NORMALIZED_HANDLER';
            }
        };
        Container::getInstance()->set($action::class, $action);

        $router = new Router();
        $router->get('/api/normalized', $action::class);

        ob_start();
        $router->dispatch(new Request('GET', '/api//normalized///'));
        $output = (string)ob_get_clean();

        self::assertSame('NORMALIZED_HANDLER', $output);
    }

    public function testRouterDispatchesHandleAndExecuteHandlers(): void {
        $handleAction = new class {
            public function handle(Request $request): void {
                echo 'HANDLE_HANDLER';
            }
        };
        $executeAction = new class {
            public function execute(Request $request): void {
                echo 'EXECUTE_HANDLER';
            }
        };
        Container::getInstance()->set($handleAction::class, $handleAction);
        Container::getInstance()->set($executeAction::class, $executeAction);

        $router = new Router();
        $router->get('/api/handle', $handleAction::class);
        $router->post('/api/execute', $executeAction::class);

        ob_start();
        $router->dispatch(new Request('GET', '/api/handle'));
        $handleOutput = (string)ob_get_clean();

        ob_start();
        $router->dispatch(new Request('POST', '/api/execute'));
        $executeOutput = (string)ob_get_clean();

        self::assertSame('HANDLE_HANDLER', $handleOutput);
        self::assertSame('EXECUTE_HANDLER', $executeOutput);
    }

    public function testRouterConvertsUnexpectedHandlerFailureToSafeEnvelope(): void {
        $action = new class {
            public function __invoke(Request $request): void {
                throw new \RuntimeException('secret database details');
            }
        };
        Container::getInstance()->set($action::class, $action);

        $router = new Router();
        $router->get('/api/fails', $action::class);

        ob_start();
        $router->dispatch(new Request('GET', '/api/fails'));
        $payload = json_decode((string)ob_get_clean(), true);

        self::assertSame(500, http_response_code());
        self::assertSame(false, $payload['success']);
        self::assertSame('UNHANDLED_EXCEPTION', $payload['error']['code']);
        self::assertSame('Internal Server Error', $payload['error']['message']);
        self::assertStringNotContainsString('secret database details', json_encode($payload));
    }

    public function testRouterConvertsMissingHandlerClassToInternalErrorEnvelope(): void {
        $router = new Router();
        $router->get('/api/missing-handler', 'App\Missing\NeverRegisteredAction');

        ob_start();
        $router->dispatch(new Request('GET', '/api/missing-handler'));
        $payload = json_decode((string)ob_get_clean(), true);

        self::assertSame(500, http_response_code());
        self::assertSame(false, $payload['success']);
        self::assertSame('INTERNAL_ERROR', $payload['error']['code']);
    }
}
