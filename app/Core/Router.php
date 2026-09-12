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
     * Registra una ruta para el metodo DELETE.
     *
     * @param string $path Ruta HTTP
     * @param array{0: class-string, 1: string}|string $handler Array [Clase, Metodo] o Nombre de Clase Invocable
     */
    public function delete(string $path, array|string $handler): void {
        $this->routes['DELETE'][$this->normalizePath($path)] = $handler;
    }

    /**
     * Procesa la peticion actual: middleware → resolve route → dispatch action/controller.
     */
    public function dispatch(Request $request): void {
        $method = $request->getMethod();
        $path = $this->normalizePath($request->getPath());

        // Emit correlation before middleware so rejected CORS/rate-limit
        // requests still satisfy the API contract and can be traced in logs.
        if (!headers_sent()) {
            header('X-Request-ID: ' . $request->getRequestId());
        }

        if ($method === 'OPTIONS') {
            try {
                $this->middleware?->run($request);
            } catch (HttpException $e) {
                // A rejected preflight must keep the same error envelope and
                // code as every other route.  Do not continue into the 204
                // branch after responding (Response::error only exits in
                // production; tests intentionally keep executing).
                $this->respondToHttpException($e);
                return;
            } catch (\Throwable $e) {
                $this->logUnhandled($e, $request);
                Response::error('Internal Server Error', 500, 'UNHANDLED_EXCEPTION');
                return;
            }
            http_response_code(204);
            header('Content-Length: 0');
            if (!defined('PHP_TESTING')) {
                exit(0);
            }
            return;
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

            // Fix P2-5 (2026-08-12, RFC 9110 §9.6.2): si la ruta existe pero no
            // para este metodo, responder 405 con header Allow (antes: 404).
            $allowed = $this->allowedMethods($path);
            if ($allowed !== []) {
                header('Allow: ' . implode(', ', $allowed));
                Response::error('Method Not Allowed', 405, 'METHOD_NOT_ALLOWED');
                return;
            }

            Response::notFound("Endpoint {$method} {$path} not found on this server.");

        } catch (HttpException $e) {
            $this->respondToHttpException($e);
        } catch (\Throwable $e) {
            $this->logUnhandled($e, $request);
            Response::error('Internal Server Error', 500, 'UNHANDLED_EXCEPTION');
        }
    }

    private function respondToHttpException(HttpException $exception): void {
        Response::fromHttpException($exception);
    }

    private function logUnhandled(\Throwable $exception, Request $request): void {
        Logger::error('Unhandled exception in Router: ' . $exception->getMessage(), [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'request_id' => $request->getRequestId(),
        ]);
    }

    /**
     * Metodos HTTP registrados para una ruta (para el header Allow del 405).
     *
     * @return list<string>
     */
    private function allowedMethods(string $path): array {
        $allowed = [];
        foreach ($this->routes as $method => $paths) {
            if (isset($paths[$path])) {
                $allowed[] = $method;
            }
        }
        return $allowed;
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
        // Treat repeated separators as the same route. Request already strips
        // the query string, so only the path component is normalized here.
        $collapsed = preg_replace('#/{2,}#', '/', $path) ?? $path;
        return '/' . trim($collapsed, '/');
    }
}
