<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Gestor de Logs simple para la aplicación.
 * Registra errores e información en archivos de logs dentro de la carpeta /logs.
 */
class Logger {
    private static string $logDir = '';

    /**
     * Inicializa la ruta de la carpeta de logs.
     */
    private static function init(): void {
        if (self::$logDir === '') {
            self::$logDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs';
            if (!is_dir(self::$logDir)) {
                mkdir(self::$logDir, 0755, true);
            }
        }
    }

    /**
     * Escribe un mensaje en el archivo de log.
     */
    public static function log(string $level, string $message): void {
        self::init();
        $date = date('Y-m-d H:i:s');
        $formattedMessage = "[{$date}] [{$level}] {$message}" . PHP_EOL;
        $file = self::$logDir . DIRECTORY_SEPARATOR . 'app.log';
        
        file_put_contents($file, $formattedMessage, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $message): void {
        self::log('INFO', $message);
    }

    public static function error(string $message): void {
        self::log('ERROR', $message);
    }

    public static function debug(string $message): void {
        self::log('DEBUG', $message);
    }
}
