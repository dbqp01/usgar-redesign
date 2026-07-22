<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Enrutador HTTP con soporte de middleware pipeline y Clases-Acción (ADR) o controladores.
 * Soporta registación mediante arrays [Clase, Método] o Nombres de Clase Invocable.
 */
class Router {
    private array $routes = [];
    private ?Middleware $middleware = null;

    /**
     * Asigna el pipeline de middleware a ejecutar antes del dispatch.
     */
    public function setMiddleware(Middleware $middleware): void {
        $this->middleware = $middleware;
    }

    /**
     * Registra una ruta para el método GET.
     *
     * @param string $path Ruta HTTP
     * @param array|string $handler Array [Clase, Método] o Nombre de Clase Invocable
     */
    public function get(string $path, array|string $handler): void {
        $this->routes['GET'][$this->normalizePath($path)] = $handler;
    }

    /**
     * Registra una ruta para el método POST.
     *
     * @param string $path Ruta HTTP
     * @param array|string $handler Array [Clase, Método] o Nombre de Clase Invocable
     */
    public function post(string $path, array|string $handler): void {
        $this->routes['POST'][$this->normalizePath($path)] = $handler;
    }

    /**
     * Procesa la petición actual: middleware → resolve route → dispatch action/controller.
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

                    $controller = new $controllerClass();

                    if (!method_exists($controller, $action)) {
                        throw HttpException::internal("Action {$action} not found in {$controllerClass}.");
                    }

                    $controller->$action($request);
                    return;
                }

                if (is_string($handler)) {
                    if (!class_exists($handler)) {
                        throw HttpException::internal("Action class {$handler} not found.");
                    }

                    $actionInstance = new $handler();

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
            }

            Response::notFound("Endpoint {$method} {$path} not found on this server.");

        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->getStatusCode(), $e->getErrorCode(), $e->getDetails());
        }
    }

    /**
     * Resuelve una ruta registrada. Retorna el handler o null.
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
