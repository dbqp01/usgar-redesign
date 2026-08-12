<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Enrutador HTTP con soporte de middleware pipeline y Clases-Accion (ADR) o controladores.
 * Soporta registacion mediante arrays [Clase, Metodo] o Nombres de Clase Invocable.
 */
class Router {
    /** @var array<string, array<string, array{0: class-string, 1: string}|string>> */
    private array $routes = [];
    private ?Middleware $middleware = null;

    /**
     * Asigna el pipeline de middleware a ejecutar antes del dispatch.
     */
    public function setMiddleware(Middleware $middleware): void {
        $this->middleware = $middleware;
    }

    /**
     * Registra una ruta para el metodo GET.
     *
     * @param string $path Ruta HTTP
     * @param array{0: class-string, 1: string}|string $handler Array [Clase, Metodo] o Nombre de Clase Invocable
     */
    public function get(string $path, array|string $handler): void {
        $this->routes['GET'][$this->normalizePath($path)] = $handler;
    }

    /**
     * Registra una ruta para el metodo POST.
     *
     * @param string $path Ruta HTTP
     * @param array{0: class-string, 1: string}|string $handler Array [Clase, Metodo] o Nombre de Clase Invocable
     */
    public function post(string $path, array|string $handler): void {
        $this->routes['POST'][$this->normalizePath($path)] = $handler;
    }

    /**
     * Procesa la peticion actual: middleware → resolve route → dispatch action/controller.
     */
    public function dispatch(Request $request): void {
        $method = $request->getMethod();
        $path = $this->normalizePath($request->getPath());

        if ($method === 'OPTIONS') {
            try {
                $this->middleware?->run($request);
            } catch (HttpException $e) {
                Response::error($e->getMessage(), $e->getStatusCode());
            }
            http_response_code(204);
            header('Content-Length: 0');
            exit(0);
        }

        try {
            $this->middleware?->run($request);

            $handler = $this->resolveRoute($method, $path);

            if ($handler !== null) {
                if (is_array($handler)) {
                    $controllerClass = $handler[0];
                    $action = $handler[1];

                    if (!class_exists($controllerClass)) {
                        throw HttpException::internal("Controller class {$controllerClass} not found.");
                    }

                    $controller = Container::getInstance()->get($controllerClass);

                    if (!method_exists($controller, $action)) {
                        throw HttpException::internal("Action {$action} not found in {$controllerClass}.");
                    }

                    $controller->$action($request);
                    return;
                }

                // $handler es string aquí: PHPStan lo prueba (no array y no null).
                if (!class_exists($handler)) {
                    throw HttpException::internal("Action class {$handler} not found.");
                }

                $actionInstance = Container::getInstance()->get($handler);

                if (is_callable($actionInstance)) {
                    $actionInstance($request);
                    return;
                }

                if (method_exists($actionInstance, 'handle')) {
                    $actionInstance->handle($request);
                    return;
                }

                if (method_exists($actionInstance, 'execute')) {
                    $actionInstance->execute($request);
                    return;
                }

                throw HttpException::internal("Action class {$handler} is not invocable and has no handle/execute method.");
            }

            Response::notFound("Endpoint {$method} {$path} not found on this server.");

        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->getStatusCode(), $e->getErrorCode(), $e->getDetails());
        } catch (\Throwable $e) {
            Logger::error('Unhandled exception in Router: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            Response::error('Internal Server Error', 500, 'UNHANDLED_EXCEPTION');
        }
    }

    /**
     * Resuelve una ruta registrada. Retorna el handler o null.
     *
     * @return array{0: class-string, 1: string}|string|null
     */
    private function resolveRoute(string $method, string $path): array|string|null {
        return $this->routes[$method][$path] ?? null;
    }

    /**
     * Normaliza la ruta eliminando barras inclinadas duplicadas y finales.
     */
    private function normalizePath(string $path): string {
        return '/' . trim($path, '/');
    }
}
