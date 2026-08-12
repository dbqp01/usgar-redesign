<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Interfaz del contenedor de dependencias.
 * Estandar PSR-11 oficial (psr/container) desde 2026-08-10 — la
 * implementacion manual anterior (get/has duplicados) queda eliminada.
 */
interface ContainerInterface extends \Psr\Container\ContainerInterface {
}
