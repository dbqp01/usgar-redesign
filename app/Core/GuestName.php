<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Utilidad para dividir un nombre completo en first + last name.
 * Los defaults de cada adaptador (Guest, '') se aplican en el call site.
 */
class GuestName {

    /**
     * Divide un nombre completo en maximo 2 partes.
     *
     * @return array<int, string>
     */
    public static function split(string $fullName): array {
        return explode(' ', $fullName, 2);
    }
}
