<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Gestor de Logs con rotación básica y soporte para formato JSON estructurado.
 * Mejoras: rotación por tamaño (5MB), nivel WARNING, formato JSON en producción.
 */
class Logger {
    private static string $logDir = '';
    private const MAX_SIZE_BYTES = 5 * 1024 * 1024; // 5 MB

    private static function init(): void {
        if (self::$logDir === '') {
            self::$logDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs';
            if (!is_dir(self::$logDir)) {
                mkdir(self::$logDir, 0755, true);
            }
        }
    }

    /**
     * Escribe un mensaje en el archivo de log con rotación automática.
     */
    public static function log(string $level, string $message, array $context = []): void {
        self::init();

        $file = self::$logDir . DIRECTORY_SEPARATOR . 'app.log';

        // Rotación: si el archivo supera MAX_SIZE, renombrar a .log.1
        self::rotateIfNeeded($file);

        $entry = self::formatEntry($level, $message, $context);
        file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
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
     * Formatea la entrada de log. JSON en producción, texto plano en desarrollo.
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
     * Rotación simple: si el archivo supera 5MB, moverlo a .log.1.
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
