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
    private static ?string $overrideDir = null;
    private const MAX_SIZE_BYTES = 5 * 1024 * 1024; // 5 MB

    /**
     * Directorios candidatos, en orden de preferencia. En produccion el
     * primero esta FUERA del arbol de deploy: el build reemplaza
     * public_html entero, asi que un log dentro se perdia en cada push
     * (verificado: el app.log de prod empieza en la fecha del ultimo build).
     * Si no es escribible se cae al arbol de deploy y, en ultimo caso,
     * a error_log().
     *
     * @internal Expuesto para tests.
     * @return list<string>
     */
    public static function candidateDirs(?string $deploymentBase): array {
        if (self::$overrideDir !== null) {
            return [self::$overrideDir];
        }
        $dirs = [];
        if ($deploymentBase !== null) {
            $dirs[] = $deploymentBase . DIRECTORY_SEPARATOR . 'logs';
        }
        $dirs[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs';
        return $dirs;
    }

    /** @internal Only tests should override the log directory. */
    public static function setDirectoryForTesting(?string $directory): void {
        self::$overrideDir = $directory;
        self::$logDir = '';
        self::$dirReady = false;
    }

    private static function init(): void {
        if (self::$dirReady) {
            return;
        }

        foreach (self::candidateDirs(Config::deploymentBase()) as $dir) {
            // Intentar crear el directorio; @mkdir suprime warnings si falla.
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                continue;
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                continue;
            }
            // Crear .htaccess para proteger logs de acceso web
            $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
            if (!file_exists($htaccess)) {
                @file_put_contents($htaccess, "Deny from all\n");
            }
            self::$logDir = $dir;
            self::$dirReady = true;
            return;
        }
        // Ningun directorio escribible -> $dirReady queda false -> usara error_log()
    }

    /**
     * Escribe un mensaje en el archivo de log con rotacion automatica.
     * Si el directorio de logs no existe o no es escribible, usa error_log() como fallback.
     *
     * @param array<string, mixed> $context
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

    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = []): void {
        self::log('INFO', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $message, array $context = []): void {
        self::log('WARNING', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function error(string $message, array $context = []): void {
        self::log('ERROR', $message, $context);
    }

    /**
     * Formatea la entrada de log como JSON estructurado.
     *
     * @param array<string, mixed> $context
     */
    private static function formatEntry(string $level, string $message, array $context): string {
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level'     => $level,
            'message'   => $message,
        ];
        if (!empty($context)) {
            $entry['context'] = $context;
        }
        return json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
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
