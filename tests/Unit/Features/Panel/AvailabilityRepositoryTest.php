<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Panel;

use App\Features\Panel\Domain\AvailabilityRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Regression coverage for unassigned QloApps booking rows (id_room=0). */
final class AvailabilityRepositoryTest extends TestCase
{
    public function testNonPositiveRoomIdIsMappedToTypeLevelBooking(): void
    {
        $pdo = new AvailabilityRepositoryFakePdo();
        $pdo->rows = [[
            'id_room' => 0,
            'room_num' => null,
            'id_product' => 42,
            'id_room_type' => 7,
            'date_from' => '2026-09-10 12:00:00',
            'date_to' => '2026-09-12 10:30:00',
            'total_paid_amount' => '380.00',
            'firstname' => 'Ana',
            'lastname' => 'Perez',
            'module' => 'mercadopago',
        ]];

        $repository = new AvailabilityRepository($pdo);
        $method = new ReflectionMethod(AvailabilityRepository::class, 'fetchQloBookingsRich');
        $method->setAccessible(true);
        $result = $method->invoke($repository, '2026-09-01', '2026-09-30', 1);

        self::assertCount(1, $result);
        self::assertNull($result[0]['room_id']);
        self::assertSame(7, $result[0]['id_room_type']);
    }
}

/** @phpstan-ignore-next-line */
final class AvailabilityRepositoryFakePdo extends PDO
{
    /** @var list<array<string,mixed>> */
    public array $rows = [];

    public function __construct() {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new AvailabilityRepositoryFakeStatement($this);
    }
}

/** @phpstan-ignore-next-line */
final class AvailabilityRepositoryFakeStatement extends PDOStatement
{
    private AvailabilityRepositoryFakePdo $pdo;

    public function __construct(AvailabilityRepositoryFakePdo $pdo)
    {
        $this->pdo = $pdo;
    }

    public function execute(?array $params = null): bool { return true; }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, ...$args): array
    {
        return $this->pdo->rows;
    }
}
