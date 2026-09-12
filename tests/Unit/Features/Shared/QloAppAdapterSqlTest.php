<?php
declare(strict_types=1);

namespace Tests\Unit\Features\Shared;

use App\Features\Shared\Adapters\QloAppAdapter;
use PHPUnit\Framework\TestCase;
use PDO;
use PDOStatement;

/**
 * P2-3 (2026-08-12): verifica que las consultas SQL de confirmOrder() se
 * interpolan con las constantes del adapter y NO contienen literales
 * "self::CONST" (los strings SQL de doble comilla no expanden constantes).
 * Sin red ni BD real: PDO fake que captura los strings de prepare().
 */
final class QloAppAdapterSqlTest extends TestCase {
    public function testConfirmOrderQueriesUseInterpolatedConstants(): void {
        $pdo = new FakePdoForSqlTest();
        // Cola de resultados: [0] = hold, [1] = orden existente (false),
        // [2] = moneda (fetch false => fallback PEN 1), [3] = nombre de habitación.
        $pdo->fetchQueue = [
            [
                'id_hotel' => '1', 'id_room_type' => '3', 'checkin' => '2026-09-01',
                'checkout' => '2026-09-03', 'guest_data' => '{"guests": 2, "phone": "123456789"}',
            ],
            false,
            false,
            false,
        ];
        $pdo->fetchColumnQueue = [0, false];

        $adapter = new QloAppAdapter($pdo);
        $result = $adapter->confirmOrder('USGAR-test-cart-1', 380.0, 'Ana Perez', 'ana@example.com');

        // Debe llegar a crear la orden (paso 9) y devolver su id.
        $this->assertSame('1', $result);

        $allQueries = implode("\n", $pdo->preparedQueries);
        $allParams = implode("\n", array_map(
            fn($p): string => is_array($p) ? implode('|', array_map('strval', array_values($p))) : '',
            $pdo->executedParams
        ));

        // Ninguna query debe contener el literal self::CONST sin expandir.
        $this->assertStringNotContainsString('self::', $allQueries);
        $this->assertStringNotContainsString('SHOP_GROUP_ID', $allQueries);

        // Los valores de las constantes deben aparecer interpolados en el SQL.
        $this->assertStringContainsString('VALUES (1, 1, ?, ?, ?, ?, ?, 1, 1, NOW(), NOW())', $allQueries);
        $this->assertStringContainsString('VALUES (1, 1, 1, 1, ?, 0, 0, NOW(), NOW())', $allQueries);
        // payment label y module viajan como PARAMETROS (confirmOrder parametrizado
        // 2026-08-15: el panel del dueno crea reservas walk-in con module propio).
        $this->assertStringContainsString('0, 0, 2, ?, ?, ?', $allQueries);
        $this->assertStringContainsString('Mercado Pago (Online)', $allParams);
        $this->assertStringContainsString('mercadopago', $allParams);
        $this->assertStringContainsString("'USGAR Hotels'", $allQueries);
        $this->assertStringContainsString("'San Pedro'", $allQueries);

        // Los horarios de checkin/checkout viajan como PARAMETROS de execute().
        $this->assertStringContainsString('2026-09-01 12:00:00', $allParams);
        $this->assertStringContainsString('2026-09-03 10:30:00', $allParams);
    }

    public function testPanelChannelIsCarriedByExistingConfirmOrderArguments(): void {
        $pdo = new FakePdoForSqlTest();
        $pdo->fetchQueue = [[
            'id_hotel' => '1', 'id_room_type' => '3', 'checkin' => '2026-09-01',
            'checkout' => '2026-09-03', 'guest_data' => '{"guests":2}',
        ], false, false, false];
        $pdo->fetchColumnQueue = [0, false];

        $adapter = new QloAppAdapter($pdo);
        self::assertSame('1', $adapter->confirmOrder(
            'USGAR-panel-phone', 380.0, 'Ana Perez', 'ana@example.com',
            7, 'panel-phone', 'Panel (phone)'
        ));
        $params = implode("\n", array_map(
            fn($p): string => is_array($p) ? implode('|', array_map('strval', array_values($p))) : '',
            $pdo->executedParams
        ));
        self::assertStringContainsString('panel-phone', $params);
        self::assertStringContainsString('Panel (phone)', $params);
    }

    public function testMultiRoomDetailsAllocateExactGatewayCurrencyTotal(): void {
        $pdo = new FakePdoForSqlTest();
        $pdo->fetchQueue = [[
            'id_hotel' => '1', 'id_room_type' => '3', 'checkin' => '2026-09-01',
            'checkout' => '2026-09-03', 'guest_data' => '{"guests":2}',
            'room_data' => json_encode([
                ['id_room_type' => 3, 'id_product' => 30, 'room_name' => 'A', 'guests' => 2, 'room_total' => 1.0],
                ['id_room_type' => 4, 'id_product' => 40, 'room_name' => 'B', 'guests' => 2, 'room_total' => 3.0],
            ], JSON_THROW_ON_ERROR),
        ], false, false, false, false];
        $pdo->fetchColumnQueue = [0, false, false];

        $adapter = new QloAppAdapter($pdo);
        self::assertSame('1', $adapter->confirmOrder(
            'USGAR-multi-currency', 100.0, 'Ana Perez', 'ana@example.com'
        ));

        $params = array_map(
            static fn($p): string => is_array($p) ? implode('|', array_map('strval', array_values($p))) : '',
            $pdo->executedParams
        );
        // USD line weights 1:3 allocate exactly 25 and 75 PEN; both detail
        // tables receive the same amounts and their sum equals the header.
        self::assertContains('1|30|A|25|25|25|25|25', $params);
        self::assertContains('1|40|B|75|75|75|75|75', $params);
    }
}

/** @phpstan-ignore-next-line */
final class FakePdoForSqlTest extends PDO {
    /** @var list<string> */
    public array $preparedQueries = [];
    /** @var list<array<int|string, mixed>|null> */
    public array $executedParams = [];
    /** @var list<mixed> */
    public array $fetchQueue = [];
    /** @var list<mixed> */
    public array $fetchColumnQueue = [];
    private int $fetchIndex = 0;
    private int $fetchColumnIndex = 0;
    private bool $transaction = false;

    public function __construct() {
        // No tocar la BD real: constructor vacio (PDO no se inicializa).
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->preparedQueries[] = $query;
        return new FakeStmtForSqlTest($this);
    }

    public function beginTransaction(): bool {
        $this->transaction = true;
        return true;
    }

    public function commit(): bool {
        $this->transaction = false;
        return true;
    }

    public function rollBack(): bool {
        $this->transaction = false;
        return true;
    }

    public function inTransaction(): bool {
        return $this->transaction;
    }

    public function lastInsertId(?string $name = null): string {
        return '1';
    }

    public function nextFetchResult(): mixed {
        return $this->fetchQueue[$this->fetchIndex++] ?? false;
    }

    public function nextFetchColumnResult(): mixed {
        return $this->fetchColumnQueue[$this->fetchColumnIndex++] ?? false;
    }
}

/** @phpstan-ignore-next-line */
final class FakeStmtForSqlTest extends PDOStatement {
    private FakePdoForSqlTest $pdo;

    public function __construct(FakePdoForSqlTest $pdo) {
        $this->pdo = $pdo;
    }

    public function execute(?array $params = null): bool {
        $this->pdo->executedParams[] = $params;
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return $this->pdo->nextFetchResult();
    }

    public function fetchColumn(int $column = 0): mixed {
        return $this->pdo->nextFetchColumnResult();
    }
}
