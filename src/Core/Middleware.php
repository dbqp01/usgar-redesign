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
                header('Access-Control-Allow-Credentials: true');
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
     * Agrega headers defensivos completos para prevenir ataques XSS, Clickjacking y MIME-sniffing.
     */
    public static function securityHeaders(): callable {
        return static function (Request $request): void {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://sdk.mercadopago.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: https: blob:; connect-src 'self' https://api.mercadopago.com https://api.channex.io https://cms.hotelesusgar.com; frame-src 'self' https://www.mercadopago.com;");
            header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

            if (Config::isProduction()) {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            }
        };
    }
}
