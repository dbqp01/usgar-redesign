<?php
declare(strict_types=1);

namespace App\Core;

use Exception;

/**
 * Excepción HTTP base para respuestas de error tipadas.
 * Permite que controllers lancen excepciones en vez de llamar exit() directamente,
 * facilitando testing y manejo uniforme de errores en el Router/Middleware.
 */
class HttpException extends Exception {
    public function __construct(
        string $message,
        private readonly int $statusCode = 500,
        private readonly array $details = []
    ) {
        parent::__construct($message, $statusCode);
    }

    public function getStatusCode(): int {
        return $this->statusCode;
    }

    public function getDetails(): array {
        return $this->details;
    }

    /**
     * Convierte la excepción al formato de payload JSON estándar de la API.
     */
    public function toPayload(): array {
        $payload = [
            'success' => false,
            'error'   => $this->getMessage(),
        ];
        if (!empty($this->details)) {
            $payload['details'] = $this->details;
        }
        return $payload;
    }

    // --- Factory methods para errores comunes ---

    public static function badRequest(string $message = 'Bad Request', array $details = []): self {
        return new self($message, 400, $details);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self {
        return new self($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): self {
        return new self($message, 403);
    }

    public static function notFound(string $message = 'Not Found'): self {
        return new self($message, 404);
    }

    public static function tooManyRequests(string $message = 'Too Many Requests'): self {
        return new self($message, 429);
    }

    public static function internal(string $message = 'Internal Server Error'): self {
        return new self($message, 500);
    }
}
