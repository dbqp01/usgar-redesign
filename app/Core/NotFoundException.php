<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Servicio no encontrado en el contenedor.
 * Implementa la interfaz estandar PSR-11 (psr/container) manteniendo la
 * herencia de ContainerException para compatibilidad con los catch existentes.
 */
class NotFoundException extends ContainerException implements \Psr\Container\NotFoundExceptionInterface {
}
