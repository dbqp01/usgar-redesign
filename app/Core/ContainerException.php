<?php
declare(strict_types=1);

namespace App\Core;

use Exception;

/**
 * Error generico del contenedor de dependencias.
 * Implementa la interfaz estandar PSR-11 (psr/container) manteniendo la
 * herencia de \Exception para compatibilidad con los catch existentes.
 */
class ContainerException extends Exception implements \Psr\Container\ContainerExceptionInterface {
}
