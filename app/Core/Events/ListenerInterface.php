<?php
declare(strict_types=1);

namespace App\Core\Events;

/**
 * Interfaz para los escuchadores (listeners) de eventos.
 */
interface ListenerInterface {
    public function handle(EventInterface $event): void;
}
