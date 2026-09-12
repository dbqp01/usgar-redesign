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
     * @throws HttpException Si algun middleware rechaza la peticion
     */
    public function run(Request $request): void {
        foreach ($this->stack as $handler) {
            $handler($request);
        }
    }

    // --- Middleware factories preconfigurados ---

    /**
     * Middleware de CORS que valida origen y envia headers.
     * Lee origenes permitidos desde Config.
     */
    public static function cors(): callable {
        return static function (Request $request): void {
            $allowedOrigins = Config::getAllowedOrigins();
            $origin = $request->getHeader('origin') ?? '';
            // Some form submissions omit Origin but still send Referer.
            // Validate its origin against the same authoritative allowlist.
            if ($origin === '' && in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $origin = self::originFromReferer($request->getHeader('referer') ?? '');
            }

            // An explicit allowlist is authoritative. A request without an
            // Origin header is same-origin/non-CORS, so omit ACAO entirely;
            // never fall back to '*' after production has configured origins.
            $allowOrigin = self::resolveCorsOrigin($allowedOrigins, $origin);
            $hasExplicitAllowlist = $allowedOrigins !== ['*'];
            if ($hasExplicitAllowlist) {
                header('Vary: Origin');
            }

            if ($allowOrigin !== null) {
                header("Access-Control-Allow-Origin: {$allowOrigin}");
            }
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token, x-signature, x-request-id');

            if ($allowOrigin !== null && $allowOrigin !== '*') {
                header('Access-Control-Allow-Credentials: true');
            }
        };
    }

    /**
     * Resolves the CORS response origin without touching HTTP state.
     *
     * @param list<string> $allowedOrigins
     * @return string|null '*' for the development wildcard, the requested
     * origin for an allowlist hit, or null for same-origin/non-CORS requests.
     * @throws HttpException when an explicit allowlist rejects the origin.
     */
    public static function resolveCorsOrigin(array $allowedOrigins, string $origin): ?string {
        if ($allowedOrigins === ['*']) {
            return '*';
        }
        if ($origin === '') {
            return null;
        }
        if (!in_array($origin, $allowedOrigins, true)) {
            throw HttpException::forbidden('Origin not allowed.');
        }
        return $origin;
    }

    /** Return the scheme/host/port origin represented by a Referer URL. */
    private static function originFromReferer(string $referer): string {
        if ($referer === '') {
            return '';
        }
        $parts = parse_url($referer);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)) {
            return '';
        }
        $origin = strtolower((string)$parts['scheme']) . '://' . strtolower((string)$parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . (int)$parts['port'];
        }
        return $origin;
    }

    /**
     * P3-3 (OWASP API4:2023, "limits missing or set inappropriately"): rutas
     * exentas del rate limit global por tener limites apropiados por diseno:
     * - /api/webhook: MercadoPago reintenta en rafagas legitimas; la unica
     *   ruta cara (fetch de detalles) solo corre con firma HMAC valida.
     * - /api/health: probe de monitoreo; un 429 aqui falsifica una caida.
     */
    private const EXEMPT_RATE_LIMIT_PATHS = ['/api/webhook', '/api/health'];

    /**
     * Middleware de Rate Limiting por IP.
     * Los limites se leen de Config (RATE_LIMIT_MAX_REQUESTS / RATE_LIMIT_WINDOW_SECONDS).
     */
    public static function rateLimit(?int $maxRequests = null, ?int $windowSeconds = null): callable {
        $maxRequests = $maxRequests ?? (int)(Config::get('RATE_LIMIT_MAX_REQUESTS', '60') ?? '60');
        $windowSeconds = $windowSeconds ?? (int)(Config::get('RATE_LIMIT_WINDOW_SECONDS', '600') ?? '600');
        return static function (Request $request) use ($maxRequests, $windowSeconds): void {
            // CORS preflight must remain side-effect free; it does not represent
            // an application request and must not consume the global budget.
            if ($request->getMethod() === 'OPTIONS') {
                return;
            }
            if (in_array($request->getPath(), self::EXEMPT_RATE_LIMIT_PATHS, true)) {
                return;
            }
            $ip = $request->getIp();
            try {
                $allowed = RateLimiter::check($ip, $maxRequests, $windowSeconds);
            } catch (RateLimitStorageException $e) {
                // Authentication-specific limiters call RateLimiter::check()
                // directly and therefore fail closed. The global limiter also
                // covers static/public traffic; keep those endpoints available
                // when local file storage is temporarily unavailable.
                return;
            }
            if (!$allowed) {
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
            header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://sdk.mercadopago.com https://*.mercadopago.com https://http2.mlstatic.com https://*.mlstatic.com https://unpkg.com https://www.mercadolibre.com https://*.mercadolibre.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://unpkg.com https://http2.mlstatic.com https://*.mlstatic.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: https: blob: *.tile.openstreetmap.org https://*.mlstatic.com https://*.mercadopago.com https://*.mercadolibre.com; connect-src 'self' https://api.mercadopago.com https://*.mercadopago.com https://events.mercadopago.com https://*.mercadolibre.com https://*.mlstatic.com https://cms.usgarhoteles.com; frame-src 'self' https://www.mercadopago.com https://*.mercadopago.com https://*.mercadolibre.com https://*.mercadolibre.com.pe https://*.mercadopago.com.pe;");
            header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        };
    }

    /**
     * Middleware de correlacion de peticiones (P2-6, 2026-08-12): refleja en
     * la respuesta el header x-request-id entrante o uno generado (patron
     * generate-if-missing, http.dev/x-request-id). El valor queda disponible
     * via Request::getRequestId() para logs y errores.
     */
    public static function requestId(): callable {
        return static function (Request $request): void {
            header('X-Request-ID: ' . $request->getRequestId());
        };
    }
}
