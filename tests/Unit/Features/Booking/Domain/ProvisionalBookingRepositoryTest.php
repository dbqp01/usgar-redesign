<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Booking\Domain;

use PHPUnit\Framework\TestCase;
use App\Core\BookingStatus;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use PDO;
use PDOException;

/**
 * Tests unitarios de ProvisionalBookingRepository (Wave 2, todos 9-12).
 *
 * Los SQL se capturan via mocks de PDO/PDOStatement: se valida la FORMA de
 * cada sentencia (UPDATEs condicionales por-target, auto-heal con ALTER
 * atomico, consultas con event_type) sin tocar ninguna BD.
 */
final class ProvisionalBookingRepositoryTest extends TestCase {
    /** @var array<int,string> */
    private array $sqls = [];

    /** @var array<int,array<string,mixed>> Resultados para information_schema.STATISTICS. */
    private array $statisticsRows = [];

    /** @var array<int,int> Resultados de fetchColumn para consultas information_schema.COLUMNS. */
    private array $columnCounts = [];

    private int $alterFailuresLeft = 0;

    private function buildRepo(): ProvisionalBookingRepository {
        $pdo = $this->createMock(PDO::class);

        $pdo->method('prepare')->willReturnCallback(function (string $sql): \PDOStatement {
            $this->sqls[] = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('rowCount')->willReturn(1);
            if (str_contains($sql, 'information_schema.COLUMNS')) {
                $default = 1;
                if (str_contains($sql, "COLUMN_NAME = 'event_type'")) {
                    $default = (int)($this->columnCounts['event_type'] ?? 1);
                }
                $stmt->method('fetchColumn')->willReturn($default);
            }
            return $stmt;
        });

        $pdo->method('query')->willReturnCallback(function (string $sql): \PDOStatement {
            $this->sqls[] = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            if (str_contains($sql, 'information_schema.STATISTICS')) {
                // Por referencia: lee el estado en el momento de la llamada.
                $stmt->method('fetchAll')->willReturnCallback(fn (): array => $this->statisticsRows);
            }
            return $stmt;
        });

        $pdo->method('exec')->willReturnCallback(function (string $sql): int {
            $this->sqls[] = $sql;
            if (str_contains($sql, 'ADD UNIQUE KEY uk_payment_event') && $this->alterFailuresLeft > 0) {
                $this->alterFailuresLeft--;
                throw new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction');
            }
            return 1;
        });

        return new ProvisionalBookingRepository($pdo);
    }

    private function assertSqlCount(int $expected, string $needle): void {
        $count = 0;
        foreach ($this->sqls as $sql) {
            if (str_contains($sql, $needle)) {
                $count++;
            }
        }
        $this->assertSame($expected, $count, "SQL con '{$needle}' debe aparecer {$expected} vez/veces. SQLs: " . json_encode($this->sqls));
    }

    // =====================================================================
    // Todo 9 — updateStatus con guard por-target + expired_paid.
    // =====================================================================

    public function testUpdateStatusPaidTargetsOnlyTransientStatuses(): void {
        $repo = $this->buildRepo();

        $this->assertTrue($repo->updateStatus('CART-1', BookingStatus::Paid->value));

        $this->assertSqlCount(1, "WHERE cart_id = :cartId AND status IN ('pending','manual_review','fraud_review')");
    }

    public function testUpdateStatusFraudReviewUsesSameGuardedTarget(): void {
        $repo = $this->buildRepo();

        $this->assertTrue($repo->updateStatus('CART-1', BookingStatus::FraudReview->value));

        $this->assertSqlCount(1, "WHERE cart_id = :cartId AND status IN ('pending','manual_review','fraud_review')");
    }

    public function testUpdateStatusExpiredPaidTargetsOnlyExpiredHolds(): void {
        $repo = $this->buildRepo();

        $this->assertTrue($repo->updateStatus('CART-1', BookingStatus::ExpiredPaid->value));

        $this->assertSqlCount(1, "WHERE cart_id = :cartId AND status IN ('expired')");
    }

    public function testUpdateStatusUnknownTargetFailsClosedWithoutSql(): void {
        $repo = $this->buildRepo();

        // 'failed' (rama refund legacy del webhook) NO es una transicion
        // declarada: fail-closed, nunca WHERE solo por cart_id.
        $this->assertFalse($repo->updateStatus('CART-1', 'failed'));

        $this->assertCount(0, $this->sqls, 'No debe ejecutarse SQL para targets no declarados.');
    }

    public function testCleanExpiredCartsFromSetIncludesManualAndFraudReview(): void {
        $repo = $this->buildRepo();

        $repo->cleanExpiredCarts();

        $this->assertSqlCount(1, "status IN ('pending','manual_review','fraud_review') AND expires_at < NOW()");
    }

    public function testExpiredPaidExistsInEnumAndIsTerminal(): void {
        $status = BookingStatus::tryFrom('expired_paid');
        $this->assertNotNull($status, 'BookingStatus::ExpiredPaid debe existir.');
        $this->assertTrue($status->isTerminal(), 'expired_paid es terminal (requiere resolucion manual).');
    }

    public function testRecordAlertInsertsAlertRow(): void {
        $repo = $this->buildRepo();

        $this->assertTrue($repo->recordAlert('CART-1', '555', 'expired_paid'));

        $this->assertSqlCount(1, 'INSERT INTO payment_alerts (cart_id, payment_id, alert_type)');
    }

    // =====================================================================
    // Todo 10 — room_locks: get-or-create + SELECT FOR UPDATE.
    // =====================================================================

    public function testLockRoomGetOrCreateThenSelectForUpdate(): void {
        $repo = $this->buildRepo();

        $this->assertTrue($repo->lockRoom('1:3'));

        $this->assertSqlCount(1, 'INSERT INTO room_locks (room_id) VALUES (:room_id) ON DUPLICATE KEY UPDATE room_id = room_id');
        $this->assertSqlCount(1, 'SELECT room_id FROM room_locks WHERE room_id = :room_id FOR UPDATE');
    }

    // =====================================================================
    // Todo 11 — isPaymentProcessed: event_type + FOR UPDATE + fail-closed.
    // =====================================================================

    public function testIsPaymentProcessedFiltersByEventTypeWithForUpdate(): void {
        $repo = $this->buildRepo();

        $repo->isPaymentProcessed('555', 'refunded');

        $this->assertSqlCount(1, 'WHERE payment_id = :payment_id AND event_type = :event_type');
        $this->assertSqlCount(1, 'FOR UPDATE');
    }

    public function testIsPaymentProcessedThrowsOnDbFailureFailClosed(): void {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willThrowException(new PDOException('connection lost'));
        $repo = new ProvisionalBookingRepository($pdo);

        // FAIL-CLOSED (todo 11): un error de BD en el chequeo NUNCA devuelve
        // false (fail-open); debe propagarse para que el webhook responda 500.
        $this->expectException(PDOException::class);
        $repo->isPaymentProcessed('555', 'approved');
    }

    // =====================================================================
    // Todo 12 — processed_payments: event_type + indice unico compuesto.
    // =====================================================================

    public function testMarkPaymentProcessedWritesEventTypeColumn(): void {
        $repo = $this->buildRepo();

        $this->assertTrue($repo->markPaymentProcessed('555', 'CART-1', 'approved'));

        $this->assertSqlCount(1, 'INSERT INTO processed_payments (payment_id, cart_id, status, event_type)');
        $this->assertSqlCount(1, 'ON DUPLICATE KEY UPDATE status = VALUES(status), event_type = VALUES(event_type)');
    }

    public function testEnsureTablesExistMigratesLegacyUniqueIndexAtomically(): void {
        // Escenario: tabla legacy con UNIQUE(payment_id) (indice 'payment_id')
        // y sin event_type. La migracion debe: ADD COLUMN -> backfill ->
        // ALTER ATOMICO en UNA sentencia (DROP INDEX + ADD UNIQUE).
        $this->columnCounts['event_type'] = 0;
        $this->statisticsRows = [
            ['INDEX_NAME' => 'PRIMARY', 'NON_UNIQUE' => 0, 'cols' => 'id'],
            ['INDEX_NAME' => 'payment_id', 'NON_UNIQUE' => 0, 'cols' => 'payment_id'],
        ];
        $repo = $this->buildRepo();

        $repo->ensureTablesExist();

        $this->assertSqlCount(1, 'ALTER TABLE processed_payments ADD COLUMN event_type VARCHAR(32) NULL');
        $this->assertSqlCount(1, "UPDATE processed_payments SET event_type = 'approved' WHERE event_type IS NULL");
        $this->assertSqlCount(
            1,
            'ALTER TABLE processed_payments DROP INDEX `payment_id`, ADD UNIQUE KEY uk_payment_event (payment_id, event_type)'
        );
        // Atomicidad (fix MAJOR r9): el DROP y el ADD viven en UNA sentencia.
        $alterSqls = array_values(array_filter($this->sqls, fn (string $s): bool => str_contains($s, 'ALTER TABLE processed_payments')));
        $this->assertCount(2, $alterSqls, 'ADD COLUMN + ALTER atomico = 2 ALTERs en total.');
        $last = (string) end($alterSqls);
        $this->assertTrue(
            str_contains($last, 'DROP INDEX `payment_id`') && str_contains($last, 'ADD UNIQUE KEY uk_payment_event (payment_id, event_type)'),
            'DROP + ADD deben estar en la MISMA sentencia (sin ventana sin indice).'
        );
    }

    public function testEnsureTablesExistSkipsWhenCompositeIndexAlreadyExists(): void {
        // Boot idempotente: ya migrado -> ni ADD ni DROP.
        $this->columnCounts['event_type'] = 1;
        $this->statisticsRows = [
            ['INDEX_NAME' => 'PRIMARY', 'NON_UNIQUE' => 0, 'cols' => 'id'],
            ['INDEX_NAME' => 'uk_payment_event', 'NON_UNIQUE' => 0, 'cols' => 'payment_id,event_type'],
        ];
        $repo = $this->buildRepo();

        $repo->ensureTablesExist();

        $this->assertSqlCount(0, 'ALTER TABLE processed_payments');
        $this->assertSqlCount(0, 'DROP INDEX');
        $this->assertSqlCount(0, 'ADD UNIQUE KEY uk_payment_event');
    }

    public function testEnsureTablesExistFailsClosedOnUnexpectedUniqueIndex(): void {
        // FAIL-CLOSED (fix MINOR r8): si el indice unico no es exactamente
        // single-column UNIQUE(payment_id), NO se dropea NADA.
        $this->columnCounts['event_type'] = 1;
        $this->statisticsRows = [
            ['INDEX_NAME' => 'PRIMARY', 'NON_UNIQUE' => 0, 'cols' => 'id'],
            ['INDEX_NAME' => 'weird_idx', 'NON_UNIQUE' => 0, 'cols' => 'payment_id,cart_id'],
        ];
        $repo = $this->buildRepo();

        $repo->ensureTablesExist();

        $this->assertSqlCount(0, 'DROP INDEX', 'Nunca dropear un indice no validado.');
        $this->assertSqlCount(0, 'ADD UNIQUE KEY uk_payment_event');
    }

    public function testEnsureTablesExistRetriesAlterOnLockTimeout(): void {
        // FALLO DE EJECUCION DEL ALTER (fix MINOR r10): retry x3 ante
        // metadata-lock timeout (1205).
        $this->columnCounts['event_type'] = 0;
        $this->statisticsRows = [
            ['INDEX_NAME' => 'payment_id', 'NON_UNIQUE' => 0, 'cols' => 'payment_id'],
        ];
        $this->alterFailuresLeft = 2; // falla 2 veces, el tercer intento pasa
        $repo = $this->buildRepo();

        $repo->ensureTablesExist();

        $this->assertSqlCount(3, 'ADD UNIQUE KEY uk_payment_event (payment_id, event_type)');
    }
}
