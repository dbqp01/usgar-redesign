<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Abstracción de la respuesta HTTP. Administra cabeceras, CORS y salidas JSON.
 */
class Response {
    /**
     * Inicializa y envía las cabeceras CORS necesarias.
     * Si la petición es OPTIONS (Preflight), detiene el flujo y retorna 200 OK.
     */
    public static function initCors(): void {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE, PUT');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, x-signature, x-request-id');
        
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(200);
            exit(0);
        }
    }

    /**
     * Envía una respuesta JSON formateada y finaliza la ejecución de forma limpia.
     */
    public static function json(array $data, int $statusCode = 200): void {
        self::initCors();
        
        // Limpiar cualquier búfer de salida previo para evitar JSON corrompido
        if (ob_get_length()) {
            ob_clean();
        }

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit(0);
    }

    /**
     * Envía una respuesta de error uniforme.
     */
    public static function error(string $message, int $statusCode = 500, array $details = []): void {
        $payload = [
            'success' => false,
            'error' => $message
        ];

        if (!empty($details)) {
            $payload['details'] = $details;
        }

        self::json($payload, $statusCode);
    }

    /**
     * Respuestas comunes estandarizadas.
     */
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
