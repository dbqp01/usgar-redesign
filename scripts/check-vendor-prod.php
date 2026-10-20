<?php
declare(strict_types=1);

/**
 * Verifica que el vendor/ versionado sea utilizable tal cual en produccion.
 *
 * El repo versiona vendor/ con SOLO las dependencias de produccion
 * (composer.lock -> "packages"); las de desarrollo (phpstan, phpunit, ...)
 * se reinstalan con `composer install` y no se commitean. El autoloader de
 * Composer se versiona generado en modo --no-dev.
 *
 * Si alguien commitea un autoloader generado con las dev (por ejemplo tras
 * un `composer install`) sin versionar tambien los paquetes de desarrollo,
 * el sitio revienta al arrancar: el autoloader intenta incluir archivos que
 * no existen. Este script detecta exactamente eso, antes del deploy.
 *
 * Uso: php scripts/check-vendor-prod.php
 * Salida: 0 = correcto, 1 = vendor inconsistente.
 */

$root = dirname(__DIR__);
$composerDir = $root . '/vendor/composer';
$errors = [];

/** Comprueba que todas las rutas de un mapa composer existan en disco. */
$checkMap = static function (string $file, string $label, bool $expectDir = false) use (&$errors): void {
    if (!is_file($file)) {
        return; // el archivo no existe = esa seccion no aplica
    }
    /** @var array<string, string|array<string>> $map */
    $map = require $file;
    foreach ($map as $key => $paths) {
        foreach ((array) $paths as $path) {
            $exists = $expectDir ? is_dir($path) : is_file($path);
            if (!$exists) {
                $errors[] = sprintf('%s: "%s" apunta a %s que no existe', $label, $key, $path);
            }
        }
    }
};

// "files" se incluye al arrancar: si apunta a un paquete ausente, el sitio
// muere con un fatal antes de responder nada.
$checkMap($composerDir . '/autoload_files.php', 'autoload_files');
$checkMap($composerDir . '/autoload_classmap.php', 'autoload_classmap');
$checkMap($composerDir . '/autoload_psr4.php', 'autoload_psr4', true);
$checkMap($composerDir . '/autoload_namespaces.php', 'autoload_namespaces', true);

if (!is_file($root . '/vendor/autoload.php')) {
    $errors[] = 'falta vendor/autoload.php';
}

if ($errors) {
    fwrite(STDERR, "[vendor] vendor/ no es consistente con el autoloader versionado:\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - $e\n");
    }
    fwrite(STDERR, "[vendor] Suele pasar tras un `composer install` (dev): deja el arbol de produccion con\n");
    fwrite(STDERR, "        `composer install --no-dev` y vuelve a commitear vendor/composer/.\n");
    exit(1);
}

echo "[vendor] ok: el autoloader versionado resuelve contra los archivos presentes\n";
exit(0);
