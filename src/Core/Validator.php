<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Servicio de validación reutilizable para parámetros de entrada de la API.
 * Elimina duplicación de validaciones entre controllers (DRY).
 * Lanza HttpException en caso de error — capturada por el Router.
 */
class Validator {
    /**
     * Valida que todos los campos requeridos estén presentes y no vacíos.
     *
     * @param array       $data   Datos de entrada (body o query)
     * @param list<string> $fields Lista de campos requeridos
     * @throws HttpException Si falta algún campo
     */
    public static function requireFields(array $data, array $fields): void {
        $missing = [];
        foreach ($fields as $field) {
            if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
                $missing[] = $field;
            }
        }
        if (!empty($missing)) {
            throw HttpException::badRequest(
                'Faltan parámetros requeridos: ' . implode(', ', $missing) . '.'
            );
        }
    }

    /**
     * Valida un par de fechas check-in / check-out.
     * Verifica formato YYYY-MM-DD y que checkIn < checkOut.
     *
     * @throws HttpException Si el formato o rango es inválido
     */
    public static function dateRange(string $checkIn, string $checkOut): void {
        $pattern = '/^\d{4}-\d{2}-\d{2}$/';

        if (!preg_match($pattern, $checkIn) || !preg_match($pattern, $checkOut)) {
            throw HttpException::badRequest('Formato de fecha inválido. Se espera YYYY-MM-DD.');
        }

        // Validar que las fechas sean reales (ej: 2026-02-30 no existe)
        if (!self::isRealDate($checkIn) || !self::isRealDate($checkOut)) {
            throw HttpException::badRequest('La fecha proporcionada no existe.');
        }

        if (strtotime($checkIn) >= strtotime($checkOut)) {
            throw HttpException::badRequest(
                'La fecha de checkIn debe ser estrictamente anterior a la de checkOut.'
            );
        }
    }

    /**
     * Valida formato de email usando filter_var (per php-8-modern skill).
     *
     * @throws HttpException Si el formato es inválido
     */
    public static function email(string $email): string {
        $clean = filter_var($email, FILTER_VALIDATE_EMAIL);
        if ($clean === false) {
            throw HttpException::badRequest('Formato de email inválido.');
        }
        return $clean;
    }

    /**
     * Valida que un valor sea un entero positivo.
     *
     * @throws HttpException Si el valor no es un entero positivo
     */
    public static function positiveInt(mixed $value, string $fieldName = 'value'): int {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($int === false) {
            throw HttpException::badRequest("{$fieldName} debe ser un entero positivo.");
        }
        return $int;
    }

    /**
     * Verifica que una fecha YYYY-MM-DD sea real (no 2026-02-30).
     */
    private static function isRealDate(string $date): bool {
        $parts = explode('-', $date);
        if (count($parts) !== 3) {
            return false;
        }
        return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
    }
}
