<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Abstracción de la respuesta HTTP. Administra salidas JSON estandarizadas.
 * CORS fue movido a Middleware::cors() para cumplir SRP.
 */
class Response {
    /**
     * Envía una respuesta JSON formateada y finaliza la ejecución.
     * Usa JSON_THROW_ON_ERROR para detección temprana de errores de serialización
     * (per json-standards skill).
     */
    public static function json(array $data, int $statusCode = 200): void {
        // Limpiar cualquier búfer de salida previo para evitar JSON corrompido
        if (ob_get_length()) {
            ob_clean();
        }

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        try {
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Logger::error('[Response] JSON encode error: ' . $e->getMessage());
            http_response_code(500);
            echo '{"success":false,"error":"Internal serialization error"}';
        }
        exit(0);
    }

    /**
     * Envía una respuesta de error uniforme.
     */
    public static function error(string $message, int $statusCode = 500, string $code = 'ERROR', array $details = []): void {
        $payload = [
            'success' => false,
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
        ];

        if (!empty($details)) {
            $payload['error']['details'] = $details;
        }

        self::json($payload, $statusCode);
    }

    // --- Respuestas comunes estandarizadas ---

    public static function badRequest(string $message = 'Bad Request'): void {
        self::error($message, 400);
    }

    public static function unauthorized(string $message = 'Unauthorized'): void {
        self::error($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): void {
        self::error($message, 403);
    }

    public static function notFound(string $message = 'Not Found'): void {
        self::error($message, 404);
    }

    public static function tooManyRequests(string $message = 'Too Many Requests'): void {
        self::error($message, 429);
    }
}
