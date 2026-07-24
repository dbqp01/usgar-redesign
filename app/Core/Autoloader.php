<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Autoloader PSR-4 personalizado para cargar dinamicamente las clases del proyecto.
 * Evita la necesidad de dependencias complejas y funciona de forma nativa en Hostinger.
 */
class Autoloader {
    /**
     * Registra el cargador de clases.
     *
     * @param string $baseDir Ruta base correspondiente al namespace raiz (ej: /src)
     */
    public static function register(string $baseDir): void {
        spl_autoload_register(function (string $class) use ($baseDir) {
            // Prefijo de namespace del proyecto
            $prefix = 'App\\';
            $len = strlen($prefix);
            
            // Verificar si la clase utiliza el prefijo
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            // Obtener el nombre relativo de la clase
            $relativeClass = substr($class, $len);
            
            // Construir la ruta absoluta del archivo
            $file = $baseDir . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

            // Si el archivo existe, requerirlo
            if (file_exists($file)) {
                require_once $file;
            }
        });
    }
}
