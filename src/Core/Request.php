<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Abstracción de la petición HTTP. Encapsula parámetros, cuerpo y headers.
 */
class Request {
    private string $method;
    private string $path;
    private array $queryParams;
    private array $headers;
    private ?array $body;

    public function __construct() {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->queryParams = $_GET;
        $this->headers = $this->extractHeaders();
        
        // Parsear el Path quitando query string y barras finales
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $parts = explode('?', $uri, 2);
        $this->path = '/' . trim($parts[0], '/');
        
        // Parsear cuerpo JSON si corresponde
        $this->body = null;
        if (in_array($this->method, ['POST', 'PUT', 'PATCH'])) {
            $input = file_get_contents('php://input');
            $this->body = json_decode($input, true) ?? [];
        }
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

    public function getQuery(string $key, $default = null) {
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

    public function get(string $key, $default = null) {
        return $this->body[$key] ?? $default;
    }

    /**
     * Obtiene la dirección IP del cliente de forma segura.
     */
    public function getIp(): string {
        $headers = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '127.0.0.1';
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
