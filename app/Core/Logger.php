<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Gestor de Logs con rotacion basica y soporte para formato JSON estructurado.
 * Mejoras: rotacion por tamano (5MB), nivel WARNING, formato JSON en produccion.
 * Resiliente: si no puede escribir en archivo, usa error_log() como fallback.
 */
class Logger {
    private static string $logDir = '';
    private static bool $dirReady = false;
    private const MAX_SIZE_BYTES = 5 * 1024 * 1024; // 5 MB

    private static function init(): void {
        if (self::$logDir === '') {
            self::$logDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs';
        }

        if (!self::$dirReady) {
            if (!is_dir(self::$logDir)) {
                // Intentar crear el directorio; @mkdir suprime warnings si falla
                $created = @mkdir(self::$logDir, 0755, true);
                if ($created) {
                    // Crear .htaccess para proteger logs de acceso web
                    $htaccess = self::$logDir . DIRECTORY_SEPARATOR . '.htaccess';
                    if (!file_exists($htaccess)) {
                        @file_put_contents($htaccess, "Deny from all\n");
                    }
                    self::$dirReady = true;
                }
                // Si no se pudo crear, $dirReady queda false -> usara error_log()
            } else {
                self::$dirReady = true;
            }
        }
    }

    /**
     * Escribe un mensaje en el archivo de log con rotacion automatica.
     * Si el directorio de logs no existe o no es escribible, usa error_log() como fallback.
     */
    public static function log(string $level, string $message, array $context = []): void {
        self::init();

        $entry = self::formatEntry($level, $message, $context);

        if (self::$dirReady) {
            $file = self::$logDir . DIRECTORY_SEPARATOR . 'app.log';
            self::rotateIfNeeded($file);
            $written = @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
            if ($written !== false) {
                return; // Escritura exitosa
            }
        }

        // Fallback: usar error_log() del servidor (aparece en Hostinger error logs)
        error_log("[USGAR-{$level}] {$message}" . (!empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : ''));
    }

    public static function info(string $message, array $context = []): void {
        self::log('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void {
        self::log('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void {
        self::log('ERROR', $message, $context);
    }

    public static function debug(string $message, array $context = []): void {
        self::log('DEBUG', $message, $context);
    }

    /**
     * Formatea la entrada de log. JSON en produccion, texto plano en desarrollo.
     */
    private static function formatEntry(string $level, string $message, array $context): string {
        $date = date('Y-m-d H:i:s');

        if (Config::isProduction()) {
            $entry = [
                'timestamp' => $date,
                'level'     => $level,
                'message'   => $message,
            ];
            if (!empty($context)) {
                $entry['context'] = $context;
            }
            return json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        }

        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        return "[{$date}] [{$level}] {$message}{$contextStr}" . PHP_EOL;
    }

    /**
     * Rotacion simple: si el archivo supera 5MB, moverlo a .log.1.
     */
    private static function rotateIfNeeded(string $file): void {
        if (!file_exists($file)) {
            return;
        }

        $size = filesize($file);
        if ($size !== false && $size > self::MAX_SIZE_BYTES) {
            $rotated = $file . '.1';
            @rename($file, $rotated);
        }
    }
}
