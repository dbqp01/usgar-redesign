<?php
declare(strict_types=1);

namespace App\Core;

interface ContainerInterface {
    public function get(string $id): object;
    public function has(string $id): bool;
}
