<?php
declare(strict_types=1);

namespace App\Core\Events;

/**
 * Interfaz para todos los Eventos de Dominio en el sistema.
 */
interface EventInterface {
    public function getName(): string;
    public function getPayload(): array;
    public function getOccurredAt(): \DateTimeImmutable;
}
