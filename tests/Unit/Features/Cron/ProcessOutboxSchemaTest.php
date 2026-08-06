<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Cron;

use PHPUnit\Framework\TestCase;
use App\Features\Cron\Actions\ProcessOutboxAction;
use PDO;
use PDOStatement;

/**
 * Tests del auto-heal del schema de event_outbox (todo 19).
 *
 * Se capturan las sentencias SQL via mock de PDO (patron de la Wave 2:
 * ProvisionalBookingRepositoryTest::buildRepo): se valida que una tabla
 * LEGACY recibe ALTER de attempts -> ALTER de next_attempt_at -> BACKFILL en
 * ese orden (el backfill es EL mecanismo unico, fijado tras el ALTER; nunca
 * COALESCE como sustituto). No toca ninguna BD real.
 */
final class ProcessOutboxSchemaTest extends TestCase {
    /** @var array<int,string> */
    private array $sqls = [];

    /** @var array<string,int> Cuenta de columnas reportada por information_schema. */
    private array $columnCounts = [];

    private function buildAction(): ProcessOutboxAction {
        $infoSchemaCount = 0;
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$infoSchemaCount): PDOStatement {
            $this->sqls[] = $sql;
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('rowCount')->willReturn(0);
            if (str_contains($sql, 'information_schema.COLUMNS')) {
                // Orden fijo del heal: 1a consulta = attempts, 2a = next_attempt_at.
                $infoSchemaCount++;
                $col = $infoSchemaCount === 1 ? 'attempts' : 'next_attempt_at';
                $stmt->method('fetchColumn')->willReturn($this->columnCounts[$col] ?? 1);
            }
            return $stmt;
        });
        $pdo->method('exec')->willReturnCallback(function (string $sql): int {
            $this->sqls[] = $sql;
            return 1;
        });
        return new ProcessOutboxAction($pdo, fn (mixed $e): mixed => null);
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

    public function testLegacyTableGetsAltersThenBackfillInOrder(): void {
        // Tabla legacy (sin columnas): ALTER de attempts, ALTER de
        // next_attempt_at y el BACKFILL INMEDIATAMENTE DESPUES del ALTER
        // (fix MAJOR r7 + MINOR r9: mecanismo unico, sin COALESCE).
        $this->columnCounts = ['attempts' => 0, 'next_attempt_at' => 0];
        $action = $this->buildAction();

        $action->ensureOutboxSchema();

        $this->assertSqlCount(1, 'ALTER TABLE event_outbox ADD COLUMN attempts INT NOT NULL DEFAULT 0');
        $this->assertSqlCount(1, 'ALTER TABLE event_outbox ADD COLUMN next_attempt_at DATETIME NULL');
        $this->assertSqlCount(1, 'UPDATE event_outbox SET next_attempt_at = NOW() WHERE next_attempt_at IS NULL');

        // Orden: el backfill va DESPUES de ambos ALTERs.
        $iAlter = array_search('ALTER TABLE event_outbox ADD COLUMN attempts INT NOT NULL DEFAULT 0 AFTER status', $this->sqls, true);
        $iBackfill = array_search('UPDATE event_outbox SET next_attempt_at = NOW() WHERE next_attempt_at IS NULL', $this->sqls, true);
        $this->assertNotFalse($iAlter, 'ALTER de attempts debe ejecutarse.');
        $this->assertNotFalse($iBackfill, 'Backfill debe ejecutarse.');
        $this->assertGreaterThan($iAlter, $iBackfill, 'Backfill inmediatamente despues del ALTER.');
    }

    public function testExistingColumnsSkipAlterButKeepBackfill(): void {
        // Boot idempotente: columnas presentes -> sin ALTER, backfill corre
        // igual (cubriria cualquier NULL residual).
        $this->columnCounts = ['attempts' => 1, 'next_attempt_at' => 1];
        $action = $this->buildAction();

        $action->ensureOutboxSchema();

        $this->assertSqlCount(0, 'ALTER TABLE event_outbox');
        $this->assertSqlCount(1, 'UPDATE event_outbox SET next_attempt_at = NOW() WHERE next_attempt_at IS NULL');
    }
}
