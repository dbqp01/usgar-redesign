<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Abstraccion de la peticion HTTP. Encapsula parametros, cuerpo y headers.
 * Fix: IP spoofing via X-Forwarded-For — ahora solo confia en proxies configurados.
 * Fix: JSON body parsing con JSON_THROW_ON_ERROR.
 */
class Request {
    private readonly string $method;
    private readonly string $path;
    /** @var array<string, mixed> */
    private readonly array $queryParams;
    /** @var array<string, string> */
    private readonly array $headers;
    /** @var array<string, mixed>|null */
    private readonly ?array $body;

    /**
     * @param array<string, string>|null $headers
     * @param array<string, mixed>|null  $body
     */
    public function __construct(
        ?string $method = null,
        ?string $path = null,
        ?array $headers = null,
        ?array $body = null
    ) {
        $this->method = strtoupper($method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // PHP fills $_GET for HTTP requests, but CLI cron dispatch only sets
        // REQUEST_URI from argv. Parse that URI as a defensive fallback so
        // query parameters such as action/cart_id are not silently dropped.
        $requestUri = $path ?? ($_SERVER['REQUEST_URI'] ?? '/');
        $uriParts = explode('?', $requestUri, 2);
        $queryParams = $_GET;
        if (isset($uriParts[1]) && $uriParts[1] !== '') {
            $uriQuery = [];
            parse_str($uriParts[1], $uriQuery);
            // URI values are authoritative for the URI being dispatched,
            // while preserving explicitly injected $_GET keys in tests.
            $queryParams = array_replace($queryParams, $uriQuery);
        }
        $this->queryParams = $queryParams;

        $rawHeaders = $this->extractHeaders();
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                $rawHeaders[strtolower((string)$k)] = (string)$v;
            }
        }
        $this->headers = $rawHeaders;

        if ($path !== null) {
            // Explicit paths used by CLI/tests may still contain a query
            // string; keep routing on the path component only.
            $this->path = '/' . trim($uriParts[0], '/');
        } else {
            $this->path = '/' . trim($uriParts[0], '/');
        }

        $this->body = $body ?? $this->parseBody();
    }

    public function getMethod(): string {
        return $this->method;
    }

    public function getPath(): string {
        return $this->path;
    }

    public function getQuery(string $key, mixed $default = null): mixed {
        return $this->queryParams[$key] ?? $default;
    }

    public function getHeader(string $key): ?string {
        $normalizedKey = strtolower($key);
        return $this->headers[$normalizedKey] ?? null;
    }

    private ?string $requestId = null;

    /**
     * ID de correlacion de la peticion (P2-6): respeta el header
     * x-request-id entrante si existe (patron generate-if-missing,
     * http.dev/x-request-id) o genera uno CSPRNG. El middleware
     * Middleware::requestId() lo refleja en la respuesta y los logs.
     */
    public function getRequestId(): string {
        if ($this->requestId === null) {
            $incoming = $this->getHeader('x-request-id');
            // Keep the value safe to reflect as an HTTP header and bounded for logs.
            // Invalid client values are replaced with a fresh correlation ID.
            $this->requestId = self::isValidRequestId($incoming)
                ? $incoming
                : bin2hex(random_bytes(16));
        }
        return $this->requestId;
    }

    private static function isValidRequestId(?string $requestId): bool {
        if ($requestId === null || strlen($requestId) > 128) {
            return false;
        }

        // RFC 7230-safe opaque token: excludes CR/LF and all other controls.
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._~:+\/=-]{0,127}\z/D', $requestId) === 1;
    }

    /** @return array<string, mixed>|null */
    public function getBody(): ?array {
        return $this->body;
    }

    public function get(string $key, mixed $default = null): mixed {
        return $this->body[$key] ?? $default;
    }

    /**
     * Determina si la peticion espera una respuesta HTML (formulario clasico)
     * en lugar de JSON. Usado por las acciones de auth para redirigir.
     */
    public function isHtml(): bool {
        $accept = $this->getHeader('accept') ?? '';
        $requestedWith = $this->getHeader('x-requested-with') ?? '';
        $contentType = $this->getHeader('content-type') ?? '';

        if (str_contains($accept, 'application/json') || strtolower($requestedWith) === 'xmlhttprequest') {
            return false;
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded') || str_contains($contentType, 'multipart/form-data')) {
            return true;
        }

        return str_contains($accept, 'text/html');
    }

    /**
     * Determina si la peticion llega por HTTPS.
     *
     * X-Forwarded-Proto solo es confiable cuando la conexion directa procede
     * de un proxy configurado explicitamente. Los clientes pueden enviar este
     * header por su cuenta, por lo que nunca se acepta fuera de TRUSTED_PROXIES.
     */
    public static function isHttps(): bool {
        if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443) {
            return true;
        }

        $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remoteAddr === '' || !in_array($remoteAddr, Config::getTrustedProxies(), true)) {
            return false;
        }

        return strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
    }

    /**
     * Inicia la sesion PHP con parametros de cookie seguros si aun no esta activa.
     */
    public static function startSession(bool $isSecure): void {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isSecure,
                'httponly'  => true,
                'samesite' => 'Lax'
            ]);
            @session_start();
        }
    }

    /**
     * Obtiene la direccion IP real del cliente de forma segura.
     * Solo confia en headers de proxy si REMOTE_ADDR esta en la lista de proxies confiables.
     */
    public function getIp(): string {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $trustedProxies = Config::getTrustedProxies();

        // Solo leer headers de proxy si la peticion viene de un proxy confiable
        if (!empty($trustedProxies) && in_array($remoteAddr, $trustedProxies, true)) {
            $proxyHeaders = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'];
            foreach ($proxyHeaders as $header) {
                if (!empty($_SERVER[$header])) {
                    $ips = explode(',', $_SERVER[$header]);
                    $ip = trim($ips[0]);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $ip;
                    }
                }
            }
        }

        return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '127.0.0.1';
    }

    /**
     * Parsea el body de la peticion (JSON o Form Data) de forma segura (SEC-06).
     *
     * @return array<string, mixed>|null
     */
    private function parseBody(): ?array {
        if (!in_array($this->method, ['POST', 'PUT', 'PATCH'], true)) {
            return null;
        }

        $contentType = strtolower($this->headers['content-type'] ?? '');

        // Si es form-data o urlencoded, retornar $_POST
        if (str_contains($contentType, 'application/x-www-form-urlencoded') || str_contains($contentType, 'multipart/form-data')) {
            return $_POST;
        }

        $input = file_get_contents('php://input');
        if ($input === false || trim($input) === '') {
            return $_POST ?: [];
        }

        try {
            $parsed = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
            return is_array($parsed) ? $parsed : [];
        } catch (\JsonException $e) {
            Logger::error('[Request] JSON body parse error: ' . $e->getMessage());
            return $_POST ?: [];
        }
    }

    /**
     * @return array<string, string>
     */
    private function extractHeaders(): array {
        $headers = [];
        
        // 1. Fallback primario para entornos FastCGI (LiteSpeed/Apache) que omiten headers en $_SERVER
        if (function_exists('getallheaders')) {
            $all = getallheaders() ?: [];
            foreach ($all as $k => $v) {
                $headers[strtolower((string)$k)] = (string)$v;
            }
        }

        // 2. Procesamiento estándar de $_SERVER
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $key = strtolower(str_replace('_', '-', substr($name, 5)));
                // No sobreescribir si ya lo capturó getallheaders()
                if (!isset($headers[$key])) {
                    $headers[$key] = $value;
                }
            } elseif ($name === 'CONTENT_TYPE' && !isset($headers['content-type'])) {
                $headers['content-type'] = $value;
            } elseif ($name === 'CONTENT_LENGTH' && !isset($headers['content-length'])) {
                $headers['content-length'] = $value;
            }
        }
        return $headers;
    }
}
