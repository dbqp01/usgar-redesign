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
        // Cola de resultados: [0] = hold de provisional_bookings,
        // [1] = moneda (fetch false => fallback PEN 1), [2] = customer
        // (fetchColumn 0 => insert), [3] = nombre de habitacion (false => default).
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

    public function __construct() {
        // No tocar la BD real: constructor vacio (PDO no se inicializa).
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->preparedQueries[] = $query;
        return new FakeStmtForSqlTest($this);
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
