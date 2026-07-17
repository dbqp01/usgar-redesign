<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Pipeline de Middleware para cross-cutting concerns.
 * Ejecuta una lista de callables antes de despachar al controller.
 * Cada middleware recibe el Request y puede lanzar HttpException para cortar el flujo.
 *
 * Principio Abierto/Cerrado: agregar middleware no modifica Router ni Controllers.
 */
class Middleware {
    /** @var list<callable(Request): void> */
    private array $stack = [];

    /**
     * Registra un middleware en la pila.
     *
     * @param callable(Request): void $handler
     */
    public function add(callable $handler): self {
        $this->stack[] = $handler;
        return $this;
    }

    /**
     * Ejecuta todos los middleware en orden. Corta al primer HttpException.
     *
     * @throws HttpException Si algún middleware rechaza la petición
     */
    public function run(Request $request): void {
        foreach ($this->stack as $handler) {
            $handler($request);
        }
    }

    // --- Middleware factories preconfigurados ---

    /**
     * Middleware de CORS que valida origen y envía headers.
     * Lee orígenes permitidos desde Config.
     */
    public static function cors(): callable {
        return static function (Request $request): void {
            $allowedOrigins = Config::getAllowedOrigins();
            $origin = $request->getHeader('origin') ?? '';

            // Determinar Access-Control-Allow-Origin
            $allowOrigin = '*';
            if ($allowedOrigins !== ['*'] && $origin !== '') {
                if (!in_array($origin, $allowedOrigins, true)) {
                    throw HttpException::forbidden('Origin not allowed.');
                }
                $allowOrigin = $origin;
            }

            header("Access-Control-Allow-Origin: {$allowOrigin}");
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE, PUT');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, x-signature, x-request-id');

            if ($allowOrigin !== '*') {
                header('Vary: Origin');
            }
        };
    }

    /**
     * Middleware de Rate Limiting por IP.
     */
    public static function rateLimit(int $maxRequests = 60, int $windowSeconds = 600): callable {
        return static function (Request $request) use ($maxRequests, $windowSeconds): void {
            $ip = $request->getIp();
            if (!RateLimiter::check($ip, $maxRequests, $windowSeconds)) {
                throw HttpException::tooManyRequests(
                    'Demasiadas peticiones. Intenta de nuevo en unos minutos.'
                );
            }
        };
    }

    /**
     * Middleware de Security Headers.
     * Agrega headers defensivos para prevenir ataques comunes.
     */
    public static function securityHeaders(): callable {
        return static function (Request $request): void {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        };
    }
}
