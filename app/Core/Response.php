<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Abstraccion de la respuesta HTTP. Administra salidas JSON estandarizadas.
 * CORS fue movido a Middleware::cors() para cumplir SRP.
 */
class Response {
    /**
     * Envia una respuesta JSON formateada y finaliza la ejecucion.
     * Usa JSON_THROW_ON_ERROR para deteccion temprana de errores de serializacion
     * (per json-standards skill).
     */
    public static function json(array $data, int $statusCode = 200): void {
        // Limpiar cualquier bufer de salida previo para evitar JSON corrompido
        if (ob_get_length()) {
            ob_clean();
        }

        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
        }

        try {
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Logger::error('[Response] JSON encode error: ' . $e->getMessage());
            if (!headers_sent()) {
                http_response_code(500);
            }
            echo '{"success":false,"error":"Internal serialization error"}';
        }

        if (!defined('PHP_TESTING') && Config::get('APP_ENV') !== 'testing') {
            exit(0);
        }
    }

    /**
     * Envia una respuesta JSON y cierra la conexión HTTP con el cliente sin detener el script PHP.
     * Ideal para webhooks en entornos FastCGI, permitiendo tareas pesadas en background.
     */
    public static function jsonAsync(array $data, int $statusCode = 200): void {
        if (ob_get_length()) {
            ob_clean();
        }

        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
            header('Connection: close');
        }

        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            // Si no hay fastcgi_finish_request, enviamos Content-Length para ayudar a que el cliente cierre
            if (!function_exists('fastcgi_finish_request') && !headers_sent()) {
                header('Content-Length: ' . strlen($json));
            }
            echo $json;
        } catch (\JsonException $e) {
            Logger::error('[Response] JSON encode error async: ' . $e->getMessage());
            echo '{"success":false}';
        }

        // Vaciar todos los búferes de salida
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();

        // Si usamos FPM/LiteSpeed, esto cierra la conexión instantáneamente.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    /**
     * Envia una respuesta de error uniforme.
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
