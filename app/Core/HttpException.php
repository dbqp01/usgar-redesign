<?php
declare(strict_types=1);

namespace App\Core;

use Exception;

/**
 * Excepcion HTTP base para respuestas de error tipadas.
 * Permite que controllers lancen excepciones en vez de llamar exit() directamente,
 * facilitando testing y manejo uniforme de errores en el Router/Middleware.
 */
class HttpException extends Exception {
    private readonly int $statusCode;
    private readonly string $errorCode;
    /** @var array<string, mixed> */
    private readonly array $details;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message,
        int $statusCode = 500,
        ?string $errorCode = null,
        array $details = []
    ) {
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode ?? self::codeForStatus($statusCode);
        $this->details = $details;
        parent::__construct($message, $statusCode);
    }

    public function getStatusCode(): int {
        return $this->statusCode;
    }

    public function getErrorCode(): string {
        return $this->errorCode;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array {
        return $this->details;
    }

    // --- Factory methods para errores comunes ---

    /** @param array<string, mixed> $details */
    public static function badRequest(string $message = 'Bad Request', array $details = []): self {
        return new self($message, 400, 'BAD_REQUEST', $details);
    }

    /** @param array<string, mixed> $details */
    public static function missingCredentials(string $message = 'Faltan credenciales de API requeridas en el servidor.', array $details = []): self {
        return new self($message, 503, 'MISSING_CREDENTIALS', $details);
    }

    /** @param array<string, mixed> $details */
    public static function unauthorized(string $message = 'Unauthorized', array $details = []): self {
        return new self($message, 401, 'UNAUTHORIZED', $details);
    }

    /** @param array<string, mixed> $details */
    public static function forbidden(string $message = 'Forbidden', array $details = []): self {
        return new self($message, 403, 'FORBIDDEN', $details);
    }

    /** @param array<string, mixed> $details */
    public static function notFound(string $message = 'Not Found', array $details = []): self {
        return new self($message, 404, 'NOT_FOUND', $details);
    }

    /** @param array<string, mixed> $details */
    public static function tooManyRequests(string $message = 'Too Many Requests', array $details = []): self {
        return new self($message, 429, 'TOO_MANY_REQUESTS', $details);
    }

    /** @param array<string, mixed> $details */
    public static function internal(string $message = 'Internal Server Error', array $details = []): self {
        return new self($message, 500, 'INTERNAL_ERROR', $details);
    }

    private static function codeForStatus(int $statusCode): string {
        return match ($statusCode) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            422 => 'VALIDATION_ERROR',
            429 => 'TOO_MANY_REQUESTS',
            502 => 'BAD_GATEWAY',
            503 => 'SERVICE_UNAVAILABLE',
            default => $statusCode >= 500 ? 'INTERNAL_ERROR' : 'ERROR',
        };
    }
}
