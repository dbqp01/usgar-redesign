<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Panel;

use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Features\Shared\Adapters\QloAppAdapter;

/**
 * Test de regresión del descuento de disable_dates en la disponibilidad web
 * (fix panel 2026-08-15): un bloqueo del dueño en qlo_htl_room_disable_dates
 * debe reducir available_qty — nadie compra en la web una habitación
 * bloqueada (anti-overbooking, preocupación del dueño).
 */
final class QloAppAdapterDisableDatesTest extends TestCase {
    public function testGetAvailableRoomsCountsDisableDatesAsOccupied(): void {
        Config::set('QLOAPPS_DEFAULT_LANG_ID', '1');

        $pdo = new DisableDatesFakePdo();
        $adapter = new QloAppAdapter($pdo);

        $result = $adapter->getAvailableRooms('2026-09-01', '2026-09-03');

        // El SQL debe consultar qlo_htl_room_disable_dates con el rango.
        $allQueries = implode("\n", $pdo->preparedQueries);
        $this->assertStringContainsString('qlo_htl_room_disable_dates', $allQueries);
        $this->assertStringContainsString(':date_from_disabled', $allQueries);
        $this->assertStringContainsString(':date_to_disabled', $allQueries);

        // Parámetros del rango para disable_dates bindeados.
        $this->assertContains('2026-09-01', $pdo->executedParams[0] ?? []);
        $this->assertContains('2026-09-03', $pdo->executedParams[0] ?? []);

        // 3 total - 1 reserva - 1 bloqueo = 1 disponible.
        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['available_qty']);
    }

    public function testGetAvailabilityCalendarCountsDisableDatesPerDay(): void {
        Config::set('QLOAPPS_DEFAULT_LANG_ID', '1');

        $pdo = new DisableDatesFakePdo();
        $adapter = new QloAppAdapter($pdo);

        $result = $adapter->getAvailabilityCalendar('2026-09-01', '2026-09-05');

        $allQueries = implode("\n", $pdo->preparedQueries);
        $this->assertStringContainsString('qlo_htl_room_disable_dates', $allQueries);

        // 3 habitaciones - 1 booking (día 1-3) - 1 disable (día 2-4, pero el
        // día 4 es la salida del bloqueo → se libera, misma semántica que las
        // reservas: el día de checkout no cuenta como ocupado).
        // día 1: 2 libres · día 2: 1 · día 3: 1 · día 4: 3 · día 5: 3.
        $this->assertSame(2, $result['2026-09-01'][1]);
        $this->assertSame(1, $result['2026-09-02'][1]);
        $this->assertSame(1, $result['2026-09-03'][1]);
        $this->assertSame(3, $result['2026-09-04'][1]);
        $this->assertSame(3, $result['2026-09-05'][1]);
    }
}

/** Fake PDO que responde consultas de inventario/booking/holds/disable. */
final class DisableDatesFakePdo extends \PDO {
    /** @var list<string> */
    public array $preparedQueries = [];
    /** @var list<array<string, mixed>> */
    public array $executedParams = [];
    private int $queryIndex = 0;

    public function __construct() {
        // Constructor vacío: no conecta a BD real.
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false {
        $this->preparedQueries[] = $query;
        return new DisableDatesFakeStmt($this);
    }

    public function beginTransaction(): bool {
        return true;
    }

    public function commit(): bool {
        return true;
    }

    public function rollBack(): bool {
        return true;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false {
        return $this->prepare($query);
    }
}

final class DisableDatesFakeStmt extends \PDOStatement {
    private DisableDatesFakePdo $pdo;
    /** @var list<array<string, mixed>> */
    private array $rows = [];

    public function __construct(DisableDatesFakePdo $pdo) {
        $this->pdo = $pdo;
    }

    public function execute(?array $params = null): bool {
        $this->pdo->executedParams[] = $params ?? [];
        $q = end($this->pdo->preparedQueries) ?: '';
        $this->rows = [];

        // Marcadores del SELECT principal (la query de ROOMS contiene subqueries
        // de booking_detail/disable/holds — no matchear por el nombre de tabla).
        if (str_contains($q, 'rt.id AS id_room_type')) {
            // Inventario: 1 tipo, 3 habitaciones (booked_count ya incluye 1
            // booking + 1 disable — el adapter los suma en la subquery).
            $this->rows = [[
                'id_room_type' => 1,
                'id_product'   => 1,
                'room_name'    => 'Doble Superior',
                'price'        => 90.0,
                'max_guests'   => 3,
                'total_rooms'  => 3,
                'booked_count' => 2,
            ]];
        } elseif (str_contains($q, 'SELECT bd.id_product')) {
            $this->rows = [[
                'id_product' => 1,
                'date_from'  => '2026-09-01 00:00:00',
                'date_to'    => '2026-09-03 23:59:59',
            ]];
        } elseif (str_contains($q, 'rd.id_room_type, rd.id_room')) {
            // Un bloqueo del día 2 al 4.
            $this->rows = [[
                'id_room_type' => 1,
                'id_room'      => 3,
                'date_from'    => '2026-09-02',
                'date_to'      => '2026-09-04',
            ]];
        } elseif (str_contains($q, 'pb.id_room_type')) {
            $this->rows = [];
        }

        return true;
    }

    public function fetchAll(int $mode = \PDO::FETCH_BOTH, ...$args): array {
        return $this->rows;
    }

    public function fetch(int $mode = \PDO::FETCH_BOTH, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return $this->rows[0] ?? false;
    }

    public function fetchColumn(int $column = 0): mixed {
        $first = $this->rows[0] ?? [];
        $vals = array_values($first);
        return $vals[$column] ?? false;
    }

    public function rowCount(): int {
        return count($this->rows);
    }
}
