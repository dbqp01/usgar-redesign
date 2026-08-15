<?php
declare(strict_types=1);

// Bootstrap de tests PHPUnit: autoloader de Composer (PSR-4: App\ => app/).
// El Autoloader.php casero quedo eliminado (2026-08-10).
require_once __DIR__ . '/../vendor/autoload.php';

// Marca de entorno de tests (2026-08-15): sustituye a APP_ENV=testing, que
// se ELIMINO de raiz por decision del dueño. El codigo de produccion ya
// referenciaba !defined('PHP_TESTING') en Response/Export para no hacer
// exit()/flush() bajo PHPUnit, pero la constante nunca se definia — los
// tests la suplian con Config::set('APP_ENV','testing'). Ahora es la unica
// senal: definida SOLO aqui (nunca en produccion).
if (!defined('PHP_TESTING')) {
    define('PHP_TESTING', true);
}
