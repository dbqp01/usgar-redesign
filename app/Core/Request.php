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
        $this->queryParams = $this->sanitize($_GET);

        $rawHeaders = $this->extractHeaders();
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                $rawHeaders[strtolower((string)$k)] = (string)$v;
            }
        }
        $this->headers = $rawHeaders;

        if ($path !== null) {
            $this->path = '/' . trim($path, '/');
        } else {
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            $parts = explode('?', $uri, 2);
            $this->path = '/' . trim($parts[0], '/');
        }

        $this->body = $this->sanitize($body ?? $this->parseBody());
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
     */
    public static function isHttps(): bool {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
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

    /**
     * Normaliza los datos de entrada recursivamente sin alterar su contenido.
     *
     * NOTA (2026-08-01): antes aplicaba htmlspecialchars() a todos los strings.
     * Eso corrompia datos legitimos (contrasenas con '&'/'<', nombres con
     * '&', comillas) causando doble-escape y logins rotos. El modelo correcto
     * es: validar/tipar la entrada aqui (Validator en cada action) y escapar
     * la salida SOLO donde se renderiza HTML (frontend / escapes de salida).
     * La API responde JSON, que no interpreta HTML.
     */
    private function sanitize(mixed $data): mixed {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $k => $v) {
                $sanitized[$k] = $this->sanitize($v);
            }
            return $sanitized;
        }
        return $data;
    }
}
