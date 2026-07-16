<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Enrutador de peticiones HTTP de la API. Mapea rutas estáticas a Controladores y métodos.
 */
class Router {
    private array $routes = [];

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
     * Procesa la petición actual, resolviendo el controlador y método correspondiente.
     */
    public function dispatch(Request $request): void {
        $method = $request->getMethod();
        $path = $this->normalizePath($request->getPath());

        // Manejar Preflight de CORS antes de evaluar rutas
        if ($method === 'OPTIONS') {
            Response::initCors();
            exit(0);
        }

        if (isset($this->routes[$method][$path])) {
            $handler = $this->routes[$method][$path];
            $controllerClass = $handler[0];
            $action = $handler[1];

            if (class_exists($controllerClass)) {
                $controller = new $controllerClass();
                if (method_exists($controller, $action)) {
                    // Ejecutar la acción inyectando la Request
                    $controller->$action($request);
                    return;
                }
                
                Response::error("Action {$action} not found in controller {$controllerClass}.", 500);
            }
            
            Response::error("Controller class {$controllerClass} not found.", 500);
        }

        Response::notFound("Endpoint {$method} {$path} not found on this server.");
    }

    /**
     * Normaliza la ruta eliminando barras inclinadas duplicadas y finales.
     */
    private function normalizePath(string $path): string {
        return '/' . trim($path, '/');
    }
}
