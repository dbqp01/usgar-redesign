<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Cron;

require_once __DIR__ . '/../../../fixtures/W2TestDoubles.php';
require_once __DIR__ . '/../../../fixtures/W4TestDoubles.php';

use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Features\Cron\Actions\ProcessOutboxAction;
use App\Test\Fixtures\TestDb;
use App\Test\Fixtures\W4Db;
use PDO;

/**
 * Tests del cron de outbox transaccional (Wave 4, todos 19 y 20).
 *
 * Semantica LITERAL del todo 19 (fijada tras 8 rondas de revision dual):
 *  - backfill de migracion como mecanismo UNICO (NULL -> procesado en el
 *    primer run; NUNCA COALESCE como sustituto).
 *  - FAILED incrementa attempts ANTES de fijar next_attempt_at =
 *    NOW() + backoff(attempts); alerta terminal sobre el valor PRE-incremento
 *    (attempts == MAX-1).
 *  - reclaim de IN_PROGRESS con lease vencido: attempts+1 < MAX -> PENDING;
 *    attempts+1 >= MAX -> FAILED terminal con attempts = MAX (NUNCA
 *    PENDING@MAX silencioso); residual PENDING@MAX -> FAILED + alerta.
 *  - claim con FOR UPDATE SKIP LOCKED + lease GRACE = 30 min (todo 20).
 *
 * BD real con filas aisladas (prefijo W4TEST-*) + limpieza estricta
 * setUp/tearDown (mismo patron que la Wave 2). El dispatcher es un doble
 * in-memory del PORT de dominio (permite inyectar fallos y contar llamadas —
 * nunca un mock de resultados de MercadoPago).
 */
final class ProcessOutboxActionTest extends TestCase {
    private const HOTEL_ID = 999999;
    private const ROOM_TYPE = 999999;
    private const CHECK_IN = '2028-01-01';
    private const CHECK_OUT = '2028-01-03';

    private ?PDO $pdo = null;
    private string $prefix = '';
    /** @var array<int,string> */
    private array $dispatched = [];

    protected function setUp(): void {
        Config::set('EXCHANGE_RATE_USD_PEN', '3.80');
        Config::set('MERCADO_PAGO_CURRENCY', 'PEN');

        $this->pdo = TestDb::connect();
        if ($this->pdo === null) {
            $this->markTestSkipped('BD no disponible: tests de outbox omitidos (limitacion documentada).');
        }
        W4Db::cleanup($this->pdo);
        // Auto-heal real del cron (mismo mecanismo que produce el deploy):
        // crea/ALTeriza event_outbox con attempts + next_attempt_at.
        (new ProcessOutboxAction($this->pdo, fn (mixed $e): mixed => null))->ensureOutboxSchema();
        $this->prefix = 'W4TEST-' . date('YmdHis') . '-' . random_int(1000, 9999);
        $this->dispatched = [];
    }

    protected function tearDown(): void {
        if ($this->pdo !== null) {
            W4Db::cleanup($this->pdo);
        }
    }

    /** Dispatcher de exito: registra cada cart procesado. */
    private function okDispatcher(): callable {
        return function (mixed $event) {
            $this->dispatched[] = method_exists($event, 'getCartId') ? $event->getCartId() : (string)$event;
        };
    }

    /** Dispatcher que SIEMPRE lanza (fallo vivo -> FAILED con backoff). */
    private function throwingDispatcher(): callable {
        return function (mixed $event) {
            throw new \RuntimeException('PMS fuera de linea (fallo vivo)');
        };
    }

    private function action(callable $dispatch, bool $heal = true): ProcessOutboxAction {
        $action = new ProcessOutboxAction($this->pdo, $dispatch);
        if ($heal) {
            $action->ensureOutboxSchema();
        }
        return $action;
    }

    /** Pone un evento reclamable en el outbox (gate del todo 19). */
    private function insertReclaimable(string $cartId, string $status = 'PENDING', int $attempts = 0): int {
        return W4Db::insertOutboxEvent($this->pdo, $cartId, $status, $attempts, 'NOW() - INTERVAL 1 MINUTE');
    }

    /** Vence el backoff de una fila (los backoffs son de 1..16 min; no se espera en el test). */
    private function expireBackoff(int $id): void {
        $this->pdo->prepare("UPDATE event_outbox SET next_attempt_at = NOW() - INTERVAL 1 MINUTE WHERE id = :id")
            ->execute([':id' => $id]);
    }

    private function getRow(int $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM event_outbox WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function countDispatched(string $cartId): int {
        return count(array_filter($this->dispatched, fn (string $c): bool => $c === $cartId));
    }

    // =====================================================================
    // Todo 19 — backfill de migracion (mecanismo UNICO).
    // =====================================================================

    public function testRowWithNullNextAttemptIsProcessedOnFirstRun(): void {
        // Fila legacy con next_attempt_at NULL (antes del deploy): el backfill
        // del cron (UPDATE ... WHERE next_attempt_at IS NULL) la hace
        // procesable en el PRIMER run. NULL <= NOW() es NULL/false en SQL —
        // sin el backfill se estancaria en silencio (fix MAJOR r7).
        $id = W4Db::insertOutboxEvent($this->pdo, $this->prefix . '-NULL', 'PENDING', 0, 'NULL');

        $this->action($this->okDispatcher())->run();

        $row = $this->getRow($id);
        $this->assertSame('COMPLETED', $row['status'], 'Fila con next_attempt_at NULL se procesa en el primer run (backfill).');
        $this->assertSame(1, $this->countDispatched($this->prefix . '-NULL'));
    }

    public function testBackfillRunsRightAfterAlterOnLegacyTable(): void {
        // Cubierto por ProcessOutboxSchemaTest (SQL-capture): el heal ALTERa
        // la tabla legacy y corre el backfill inmediatamente despues. Aqui se
        // verifica la SEMANTICA de red: una fila legacy insertada en una tabla
        // recien ALTerizada se procesa en el primer run.
        $id = W4Db::insertOutboxEvent($this->pdo, $this->prefix . '-LEG', 'PENDING', 0, 'NULL');
        $stats = $this->action($this->okDispatcher())->run();
        $row = $this->getRow($id);
        $this->assertSame('COMPLETED', $row['status']);
        $this->assertSame(1, $stats['claimed']);
    }

    // =====================================================================
    // Todo 19 — FAILED reintentable + backoff + tope MAX + alerta terminal.
    // =====================================================================

    public function testFailedEventIsRetriedAndCompletes(): void {
        $id = $this->insertReclaimable($this->prefix . '-RETRY');

        // Run 1: falla -> FAILED@1 con next_attempt_at = NOW() + backoff(1)=1min.
        $this->action($this->throwingDispatcher())->run();
        $row = $this->getRow($id);
        $this->assertSame('FAILED', $row['status']);
        $this->assertSame(1, (int)$row['attempts'], 'FAILED incrementa attempts ANTES del backoff.');

        // Run 2 (backoff vencido): el listener ya funciona -> COMPLETED.
        $this->expireBackoff($id);
        $this->action($this->okDispatcher())->run();
        $row = $this->getRow($id);
        $this->assertSame('COMPLETED', $row['status'], 'FAILED se reintenta en el siguiente run y completa.');
        $this->assertSame(1, $this->countDispatched($this->prefix . '-RETRY'));
    }

    public function testCrashLoopUntilMaxIsTerminalWithAlertAndNeverReclaimed(): void {
        $id = $this->insertReclaimable($this->prefix . '-LOOP');

        // 4 fallos vivos: attempts 1..4 (backoff 1,2,4,8 min).
        for ($i = 1; $i <= 4; $i++) {
            $this->expireBackoff($id);
            $stats = $this->action($this->throwingDispatcher())->run();
            $row = $this->getRow($id);
            $this->assertSame('FAILED', $row['status'], "Run {$i} deja FAILED.");
            $this->assertSame($i, (int)$row['attempts'], "Run {$i} incrementa attempts a {$i}.");
        }

        // El 5to fallo vivo: pre-incremento attempts == MAX-1 (4) -> el
        // incremento ATERRIZA en MAX -> terminal + alerta.
        $this->expireBackoff($id);
        $stats = $this->action($this->throwingDispatcher())->run();
        $row = $this->getRow($id);
        $this->assertSame('FAILED', $row['status']);
        $this->assertSame(ProcessOutboxAction::MAX_ATTEMPTS, (int)$row['attempts'], 'Terminal: attempts == MAX.');
        $this->assertSame(1, $stats['terminal'], 'La transicion FAILED viva que aterriza en MAX emite alerta terminal.');

        // Run siguiente con listener OK: NO se reclama (gate attempts < MAX).
        $stats = $this->action($this->okDispatcher())->run();
        $row = $this->getRow($id);
        $this->assertSame('FAILED', $row['status'], 'FAILED@MAX no se reintenta jamas.');
        $this->assertSame(0, $this->countDispatched($this->prefix . '-LOOP'));
    }

    public function testBackoffMinutesSequenceIsBounded(): void {
        // backoff(attempts) = min(2^(attempts-1), 16) minutos.
        $this->assertSame(1, ProcessOutboxAction::backoffMinutes(1));
        $this->assertSame(2, ProcessOutboxAction::backoffMinutes(2));
        $this->assertSame(4, ProcessOutboxAction::backoffMinutes(3));
        $this->assertSame(8, ProcessOutboxAction::backoffMinutes(4));
        $this->assertSame(16, ProcessOutboxAction::backoffMinutes(5));
        $this->assertSame(16, ProcessOutboxAction::backoffMinutes(6), 'Backoff acotado a 16 min.');
    }

    // =====================================================================
    // Todo 19 — reclaim de IN_PROGRESS (lease vencido) + terminal + residual.
    // =====================================================================

    public function testInProgressWithFutureLeaseIsNotReclaimed(): void {
        $id = W4Db::insertOutboxEvent($this->pdo, $this->prefix . '-LIVE', 'IN_PROGRESS', 0, 'NOW() + INTERVAL 60 MINUTE');

        $this->action($this->okDispatcher())->run();

        $row = $this->getRow($id);
        $this->assertSame('IN_PROGRESS', $row['status'], 'Evento VIVO (lease futuro) no se reclama ni procesa.');
        $this->assertSame(0, $this->countDispatched($this->prefix . '-LIVE'));
    }

    public function testInProgressWithExpiredLeaseIsReclaimedAndProcessed(): void {
        $id = W4Db::insertOutboxEvent($this->pdo, $this->prefix . '-CRASH', 'IN_PROGRESS', 0, 'NOW() - INTERVAL 1 MINUTE');

        $this->action($this->okDispatcher())->run();

        $row = $this->getRow($id);
        $this->assertSame('COMPLETED', $row['status'], 'IN_PROGRESS con lease vencido (crash del worker) se reclama y procesa.');
        $this->assertSame(1, $this->countDispatched($this->prefix . '-CRASH'));
    }

    public function testInProgressAtMaxMinusOneWithExpiredLeaseGoesTerminalNotPending(): void {
        // IN_PROGRESS@MAX-1 (4) con lease vencido: el reclaim usa attempts+1 < MAX
        // (4+1=5 no es < 5) -> NO vuelve a PENDING@MAX (quedaria atascado
        // silenciosamente); el branch terminal (attempts+1 >= MAX) lo marca
        // FAILED con attempts = MAX (fix MAJOR r8/r9/r10).
        $id = W4Db::insertOutboxEvent($this->pdo, $this->prefix . '-MAX1', 'IN_PROGRESS', ProcessOutboxAction::MAX_ATTEMPTS - 1, 'NOW() - INTERVAL 1 MINUTE');

        $stats = $this->action($this->okDispatcher())->run();

        $row = $this->getRow($id);
        $this->assertSame('FAILED', $row['status'], 'IN_PROGRESS@MAX-1 con lease vencido -> FAILED terminal, NUNCA PENDING@MAX.');
        $this->assertSame(ProcessOutboxAction::MAX_ATTEMPTS, (int)$row['attempts'], 'Terminal fija attempts = MAX.');
        $this->assertSame(1, $stats['terminal'], 'Alerta terminal en el reclaim.');
        $this->assertSame(0, $this->countDispatched($this->prefix . '-MAX1'));
    }

    public function testResidualPendingAtMaxGoesFailedWithAlert(): void {
        // Residual: PENDING con attempts >= MAX (nunca reclamado) -> FAILED +
        // alerta. NUNCA PENDING silencioso.
        $id = W4Db::insertOutboxEvent($this->pdo, $this->prefix . '-RES', 'PENDING', ProcessOutboxAction::MAX_ATTEMPTS, 'NOW() - INTERVAL 1 MINUTE');

        $stats = $this->action($this->okDispatcher())->run();

        $row = $this->getRow($id);
        $this->assertSame('FAILED', $row['status'], 'PENDING@MAX residual -> FAILED terminal.');
        $this->assertSame(1, $stats['terminal']);
        $this->assertSame(0, $this->countDispatched($this->prefix . '-RES'));
    }

    // =====================================================================
    // Todo 20 — claim con lease (GRACE 30 min) + run con listener lento.
    // =====================================================================

    public function testClaimSetsInProgressWithGraceLeaseBeforeProcessing(): void {
        $cartId = $this->prefix . '-LEASE';
        $id = $this->insertReclaimable($cartId);

        // El dispatcher observa el estado de la fila MIENTRAS procesa: debe
        // estar IN_PROGRESS con lease ~30 min en el futuro (el reclaim del
        // todo 19 lo respeta; otro cron no puede robarlo).
        $observed = null;
        $dispatch = function () use ($id, &$observed) {
            $stmt = $this->pdo->prepare(
                "SELECT status, (next_attempt_at > NOW() + INTERVAL " . (ProcessOutboxAction::GRACE_MINUTES - 5) . " MINUTE) AS lease_ok
                 FROM event_outbox WHERE id = :id"
            );
            $stmt->execute([':id' => $id]);
            $observed = $stmt->fetch(PDO::FETCH_ASSOC);
        };

        $this->action($dispatch)->run();

        $this->assertNotNull($observed, 'El dispatcher debio observar la fila durante el procesamiento.');
        $this->assertIsArray($observed);
        /** @var array<string, mixed> $observed */
        $this->assertSame('IN_PROGRESS', $observed['status'], 'Claim marca IN_PROGRESS ANTES de procesar.');
        $this->assertSame('1', (string)$observed['lease_ok'], 'Lease = NOW() + GRACE (>= ' . (ProcessOutboxAction::GRACE_MINUTES - 5) . ' min).');
        $this->assertSame('COMPLETED', $this->getRow($id)['status']);
    }

    public function testRunWithSlowListenersCompletesAllEvents(): void {
        // set_time_limit(0): un run con listeners lentos (3 x 0.25s) completa
        // sin que PHP mate el proceso; todos los eventos quedan COMPLETED.
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->insertReclaimable($this->prefix . '-SLOW' . $i);
        }

        $slowDispatch = function (mixed $event) {
            usleep(250_000);
            $this->dispatched[] = method_exists($event, 'getCartId') ? $event->getCartId() : (string)$event;
        };

        $stats = $this->action($slowDispatch)->run();

        $this->assertSame(3, $stats['completed'], 'Los 3 eventos con listener lento completan.');
        foreach ($ids as $id) {
            $this->assertSame('COMPLETED', $this->getRow($id)['status']);
        }
        $this->assertCount(3, $this->dispatched);
    }

    // =====================================================================
    // Todo 20 — dos crons concurrentes no procesan el mismo evento.
    // =====================================================================

    public function testConcurrentRunsProcessEachEventExactlyOnce(): void {
        // 2 procesos PHP REALES (proc_open + PHP_BINARY) corriendo
        // ProcessOutboxAction sobre el mismo outbox (5 eventos). El claim con
        // FOR UPDATE SKIP LOCKED + lease IN_PROGRESS garantiza que cada
        // evento se entrega EXACTAMENTE 1 vez (el log compartido lo cuenta).
        $cartIds = [];
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $cartIds[] = $this->prefix . '-CONC' . $i;
            $ids[] = W4Db::insertOutboxEvent($this->pdo, $cartIds[$i], 'PENDING', 0, 'NOW()');
        }

        $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'w4-outbox-' . getmypid() . '.log';
        @unlink($log);

        $repoRoot = dirname(__DIR__, 4); // tests/Unit/Features/Cron -> raiz
        $worker = $repoRoot . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'w4-outbox-worker.php';
        $cmd = [PHP_BINARY, $worker, $log];
        $spawn = function () use ($cmd, $repoRoot): array {
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($cmd, $descriptors, $pipes, $repoRoot);
            if (!is_resource($proc)) {
                return ['code' => -1, 'out' => '', 'err' => 'proc_open unavailable'];
            }
            $out = '';
            $err = '';
            $start = microtime(true);
            do {
                $r = [$pipes[1], $pipes[2]];
                $w = null;
                $e = null;
                if (stream_select($r, $w, $e, 0, 200000) > 0) {
                    foreach ($r as $stream) {
                        $chunk = fread($stream, 8192);
                        if ($chunk !== false && $chunk !== '') {
                            if ($stream === $pipes[1]) {
                                $out .= $chunk;
                            } else {
                                $err .= $chunk;
                            }
                        }
                    }
                }
                if (microtime(true) - $start > 120) {
                    proc_terminate($proc);
                    return ['code' => -2, 'out' => $out, 'err' => 'worker timeout'];
                }
                $status = proc_get_status($proc);
            } while ($status['running']);
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return ['code' => proc_close($proc), 'out' => trim($out), 'err' => trim($err)];
        };

        try {
            $r1 = $spawn();
            $r2 = $spawn();

            $this->assertSame(0, $r1['code'], 'Worker 1: ' . $r1['out'] . ' ' . $r1['err']);
            $this->assertSame(0, $r2['code'], 'Worker 2: ' . $r2['out'] . ' ' . $r2['err']);

            // Cada cart exactamente 1 vez en el log de entregas.
            $lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [];
            foreach ($cartIds as $cartId) {
                $count = count(array_filter($lines, fn (string $l): bool => $l === $cartId));
                $this->assertSame(1, $count, "El evento {$cartId} debe entregarse EXACTAMENTE 1 vez (2 workers concurrentes).");
            }

            // Todos los eventos quedan COMPLETED (por id, no por payload).
            foreach ($ids as $id) {
                $this->assertSame('COMPLETED', $this->getRow($id)['status'], "Evento {$id} debe quedar COMPLETED.");
            }
        } finally {
            @unlink($log);
        }
    }
}
