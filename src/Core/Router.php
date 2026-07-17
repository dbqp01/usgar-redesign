<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Enrutador HTTP con soporte de middleware pipeline y parámetros dinámicos.
 * Integra el Middleware pipeline para CORS, rate limiting, y security headers.
 * Captura HttpException para respuestas de error uniformes.
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
     */
    public function get(string $path, array $handler): void {
        $this->routes['GET'][$this->normalizePath($path)] = $handler;
    }

    /**
     * Registra una ruta para el método POST.
     */
    public function post(string $path, array $handler): void {
        $this->routes['POST'][$this->normalizePath($path)] = $handler;
    }

    /**
     * Procesa la petición actual: middleware → resolve route → dispatch controller.
     * Captura HttpException para respuestas uniformes sin exit() en controllers.
     */
    public function dispatch(Request $request): void {
        $method = $request->getMethod();
        $path = $this->normalizePath($request->getPath());

        // Manejar Preflight de CORS antes de cualquier otra lógica
        if ($method === 'OPTIONS') {
            try {
                $this->middleware?->run($request);
            } catch (HttpException $e) {
                // Aun en OPTIONS, si CORS rechaza, retornar error
                Response::error($e->getMessage(), $e->getStatusCode());
            }
            http_response_code(204);
            header('Content-Length: 0');
            exit(0);
        }

        try {
            // Ejecutar middleware pipeline (CORS, rate limit, security headers)
            $this->middleware?->run($request);

            // Resolver ruta estática
            $handler = $this->resolveRoute($method, $path);

            if ($handler !== null) {
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

            Response::notFound("Endpoint {$method} {$path} not found on this server.");

        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->getStatusCode(), $e->getDetails());
        }
    }

    /**
     * Resuelve una ruta registrada. Retorna el handler o null.
     */
    private function resolveRoute(string $method, string $path): ?array {
        return $this->routes[$method][$path] ?? null;
    }

    /**
     * Normaliza la ruta eliminando barras inclinadas duplicadas y finales.
     */
    private function normalizePath(string $path): string {
        return '/' . trim($path, '/');
    }
}
