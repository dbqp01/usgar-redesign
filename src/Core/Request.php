<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Abstracción de la petición HTTP. Encapsula parámetros, cuerpo y headers.
 * Fix: IP spoofing via X-Forwarded-For — ahora solo confía en proxies configurados.
 * Fix: JSON body parsing con JSON_THROW_ON_ERROR.
 */
class Request {
    private readonly string $method;
    private readonly string $path;
    private readonly array $queryParams;
    private readonly array $headers;
    private readonly ?array $body;

    public function __construct() {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->queryParams = $_GET;
        $this->headers = $this->extractHeaders();

        // Parsear el Path quitando query string y barras finales
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $parts = explode('?', $uri, 2);
        $this->path = '/' . trim($parts[0], '/');

        // Parsear cuerpo JSON si corresponde
        $this->body = $this->parseBody();
    }

    public function getMethod(): string {
        return $this->method;
    }

    public function getPath(): string {
        return $this->path;
    }

    public function getQueryParams(): array {
        return $this->queryParams;
    }

    public function getQuery(string $key, mixed $default = null): mixed {
        return $this->queryParams[$key] ?? $default;
    }

    public function getHeaders(): array {
        return $this->headers;
    }

    public function getHeader(string $key): ?string {
        $normalizedKey = strtolower($key);
        return $this->headers[$normalizedKey] ?? null;
    }

    public function getBody(): ?array {
        return $this->body;
    }

    public function get(string $key, mixed $default = null): mixed {
        return $this->body[$key] ?? $default;
    }

    /**
     * Obtiene la dirección IP real del cliente de forma segura.
     * Solo confía en headers de proxy si REMOTE_ADDR está en la lista de proxies confiables.
     */
    public function getIp(): string {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $trustedProxies = Config::getTrustedProxies();

        // Solo leer headers de proxy si la petición viene de un proxy confiable
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
     * Parsea el body de la petición (JSON o Form Data) de forma segura (SEC-06).
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

    private function extractHeaders(): array {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $key = strtolower(str_replace('_', '-', substr($name, 5)));
                $headers[$key] = $value;
            } elseif ($name === 'CONTENT_TYPE') {
                $headers['content-type'] = $value;
            } elseif ($name === 'CONTENT_LENGTH') {
                $headers['content-length'] = $value;
            }
        }
        return $headers;
    }
}
