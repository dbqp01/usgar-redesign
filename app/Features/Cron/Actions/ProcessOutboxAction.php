<?php
declare(strict_types=1);

namespace App\Features\Cron\Actions;

use App\Core\Events\EventInterface;
use App\Core\Logger;
use PDO;
use Throwable;

/**
 * Accion del cron de outbox transaccional (Wave 4, todos 19 y 20).
 * Invocada por cron/process_outbox.php.
 *
 * Maquina de estados — SEMANTICA LITERAL del plan (todo 19, fijada tras 8
 * rondas de revision dual; cualquier desviacion de reclaim/terminal/backoff/
 * lease/backfill es un fallo):
 *
 *   Gate de claim: status IN ('PENDING','FAILED') AND attempts < MAX (5)
 *   AND next_attempt_at <= NOW() -> claim con FOR UPDATE SKIP LOCKED ->
 *   IN_PROGRESS con lease next_attempt_at = NOW() + GRACE (30 min, todo 20)
 *   -> dispatchNow -> COMPLETED | FAILED.
 *
 *   Transicion FAILED (fallo vivo): attempts = attempts + 1 ANTES de fijar
 *   next_attempt_at = NOW() + backoff(attempts). La alerta terminal se
 *   evalua sobre el valor PRE-incremento (attempts == MAX - 1 antes del
 *   incremento -> el incremento ATERRIZA en MAX). FAILED SOBRESCRIBE el
 *   lease con el backoff INCONDICIONALMENTE (el lease NOW()+GRACE lo escribe
 *   SOLO el claim; si FAILED conservara el lease, el gate esperaria 30 min y
 *   el backoff seria no-op).
 *
 *   Reclaim de IN_PROGRESS huerfanos (crash del worker: kill/deploy/OOM)
 *   con LEASE vencido, particion DISJUNTA Y EXHAUSTIVA:
 *     attempts + 1 < MAX  -> PENDING (reintentable; NUNCA PENDING@MAX)
 *     attempts + 1 >= MAX -> FAILED terminal, attempts = MAX + alerta
 *   Residual: PENDING con attempts >= MAX (nunca reclamado) -> FAILED +
 *   alerta. Eventos en MAX -> FAILED terminal + alerta, NUNCA PENDING
 *   silencioso.
 *
 *   Backfill de migracion (fix MAJOR r7 + MINOR r9): inmediatamente despues
 *   del ALTER, UPDATE event_outbox SET next_attempt_at = NOW() WHERE
 *   next_attempt_at IS NULL — el mecanismo UNICO (COALESCE solo como
 *   invariante asertada, nunca como sustituto).
 *
 * Convergencia eventual (todo 20): dos eventos del mismo cart (paid, refund)
 * pueden ser reclamados por workers paralelos y entregarse DESORDENADOS al
 * PMS durante una ventana de redeploy — NO es perdida: el dedup +
 * throw-on-null + backoff de los todos 21/22 dan convergencia eventual. El
 * claim ordena por id ASC (FIFO) porque cart_id vive en el payload
 * serializado, no en una columna (el orden por cart_id seria una columna
 * nueva fuera del scope del plan).
 */
class ProcessOutboxAction {
    public const MAX_ATTEMPTS = 5;
    public const GRACE_MINUTES = 30;
    public const BATCH_SIZE = 50;

    private PDO $pdo;
    /** @var callable(EventInterface): void */
    private $dispatch;

    /**
     * @param callable(EventInterface): void $dispatch Ejecuta el evento
     *                                                  (dispatchNow en el cron real).
     */
    public function __construct(PDO $pdo, callable $dispatch) {
        $this->pdo = $pdo;
        $this->dispatch = $dispatch;
    }

    /** Backoff exponencial acotado: min(2^(attempts-1), 16) minutos. */
    public static function backoffMinutes(int $attempts): int {
        return min(2 ** max(0, $attempts - 1), 16);
    }

    /**
     * Auto-heal del schema de event_outbox (patron ensureTablesExist):
     * CREATE IF NOT EXISTS con el schema completo + ALTER de columnas
     * attempts / next_attempt_at si faltan + BACKFILL de migracion.
     */
    public function ensureOutboxSchema(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS event_outbox (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                event_name VARCHAR(255) NOT NULL,
                payload TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                processed_at DATETIME NULL DEFAULT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'PENDING',
                attempts INT NOT NULL DEFAULT 0,
                next_attempt_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $altered = false;
        if (!$this->columnExists('event_outbox', 'attempts')) {
            $this->pdo->exec("ALTER TABLE event_outbox ADD COLUMN attempts INT NOT NULL DEFAULT 0 AFTER status");
            $altered = true;
        }
        if (!$this->columnExists('event_outbox', 'next_attempt_at')) {
            $this->pdo->exec("ALTER TABLE event_outbox ADD COLUMN next_attempt_at DATETIME NULL AFTER attempts");
            $altered = true;
        }
        if ($altered) {
            Logger::info('ProcessOutboxAction: columnas attempts/next_attempt_at garantizadas en event_outbox.');
        }

        // BACKFILL DE MIGRACION — mecanismo UNICO para filas legacy con
        // next_attempt_at NULL (sin el, `next_attempt_at <= NOW()` es
        // NULL/false en SQL y todos los eventos legacy se estancarian en
        // silencio tras el deploy). Corre en cada run (idempotente): los
        // productores fijan NOW(), asi que en operacion normal no hay NULLs.
        $this->pdo->exec("UPDATE event_outbox SET next_attempt_at = NOW() WHERE next_attempt_at IS NULL");
    }

    /** Verifica si una columna existe (information_schema). Literales internos. */
    private function columnExists(string $table, string $column): bool {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
        );
        $stmt->execute([':t' => $table, ':c' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * @return array{claimed: int, completed: int, failed: int, reclaimed: int, terminal: int}
     */
    public function run(): array {
        // Todo 20: sin limite de tiempo — 50 eventos x listeners lentos
        // superaria el default de 30s (el kill dejaria IN_PROGRESS y el
        // reclaim del todo 19 reintentaria con duplicados).
        set_time_limit(0);

        $stats = ['claimed' => 0, 'completed' => 0, 'failed' => 0, 'reclaimed' => 0, 'terminal' => 0];

        $this->ensureOutboxSchema();
        $stats['reclaimed'] = $this->reclaimStale($stats);

        $claimed = $this->claimBatch();
        $stats['claimed'] = count($claimed);

        foreach ($claimed as $row) {
            $this->processOne($row, $stats);
        }

        return $stats;
    }

    /**
     * Reclaim de IN_PROGRESS huerfanos con lease vencido + residuales.
     * Solo toca eventos cuyo lease (todo 20) ya expiro: un evento VIVO
     * (recien reclamado) tiene next_attempt_at en el futuro y NO se toca.
     *
     * @param array{claimed:int,completed:int,failed:int,reclaimed:int,terminal:int} $stats
     */
    private function reclaimStale(array &$stats): int {
        $reclaimed = 0;

        // a) IN_PROGRESS con lease vencido y aun reintentable -> PENDING
        //    (attempts + 1 < MAX; fix MAJOR r8: con `attempts < MAX` un
        //    reclaim sobre attempts=MAX-1 dejaria PENDING@MAX excluido para
        //    siempre).
        $upd = $this->pdo->prepare(
            "UPDATE event_outbox SET status = 'PENDING', attempts = attempts + 1
             WHERE status = 'IN_PROGRESS' AND next_attempt_at <= NOW()
               AND attempts + 1 < " . self::MAX_ATTEMPTS
        );
        $upd->execute();
        $reclaimed += $upd->rowCount();

        // b) IN_PROGRESS con lease vencido en MAX -> FAILED terminal con
        //    attempts = MAX (fix MAJOR r9/r10: la particion attempts+1 >= MAX
        //    es exhaustiva; se FIJA attempts = MAX, no solo el predicado —
        //    sin esto, IN_PROGRESS@4 seria FAILED@4 y el gate attempts < MAX
        //    lo reclamaria otra vez en crash-loop MAS ALLA de MAX) + alerta.
        $terminal = $this->staleIds(
            "status = 'IN_PROGRESS' AND next_attempt_at <= NOW() AND attempts + 1 >= " . self::MAX_ATTEMPTS
        );
        if (!empty($terminal)) {
            $in = implode(',', array_map('intval', $terminal));
            $this->pdo->exec(
                "UPDATE event_outbox SET status = 'FAILED', attempts = " . self::MAX_ATTEMPTS
                . ", next_attempt_at = NOW() WHERE id IN ({$in})"
            );
            $stats['terminal'] += count($terminal);
            foreach ($terminal as $tid) {
                Logger::error(
                    "ALERTA OUTBOX: evento {$tid} IN_PROGRESS con lease vencido alcanzo MAX ("
                    . self::MAX_ATTEMPTS . ") — FAILED terminal."
                );
            }
        }

        // c) Residual: PENDING con attempts >= MAX (nunca reclamado) ->
        //    FAILED + alerta. NUNCA PENDING silencioso.
        $residual = $this->staleIds("status = 'PENDING' AND attempts >= " . self::MAX_ATTEMPTS);
        if (!empty($residual)) {
            $in = implode(',', array_map('intval', $residual));
            $this->pdo->exec(
                "UPDATE event_outbox SET status = 'FAILED', next_attempt_at = NOW() WHERE id IN ({$in})"
            );
            $stats['terminal'] += count($residual);
            foreach ($residual as $rid) {
                Logger::error(
                    "ALERTA OUTBOX: evento {$rid} PENDING en MAX (" . self::MAX_ATTEMPTS
                    . ") sin reclamar — FAILED terminal."
                );
            }
        }

        return $reclaimed;
    }

    /**
     * Ids de filas que cumplen una condicion de terminalidad. $where usa
     * SOLO constantes internas (nunca input de usuario).
     *
     * @return array<int, int>
     */
    private function staleIds(string $where): array {
        $rows = $this->pdo->query("SELECT id FROM event_outbox WHERE {$where}")->fetchAll(PDO::FETCH_ASSOC);
        return array_map('intval', array_column($rows, 'id'));
    }

    /**
     * Claim transaccional: SELECT ... FOR UPDATE SKIP LOCKED (doble cron
     * concurrente no procesa el mismo evento) + marca IN_PROGRESS con lease
     * next_attempt_at = NOW() + GRACE. El commit libera los locks; el lease
     * futuro protege la fila del reclaim del todo 19 mientras se procesa.
     *
     * @return array<int, array<string, mixed>>
     */
    private function claimBatch(): array {
        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare(
                "SELECT id, event_name, payload, attempts FROM event_outbox
                 WHERE status IN ('PENDING','FAILED')
                   AND attempts < " . self::MAX_ATTEMPTS . "
                   AND next_attempt_at <= NOW()
                 ORDER BY id ASC
                 LIMIT :limit FOR UPDATE SKIP LOCKED"
            );
            $stmt->bindValue(':limit', self::BATCH_SIZE, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $upd = $this->pdo->prepare(
                    "UPDATE event_outbox SET status = 'IN_PROGRESS',
                            next_attempt_at = NOW() + INTERVAL " . self::GRACE_MINUTES . " MINUTE
                     WHERE id = :id"
                );
                $upd->execute([':id' => (int)$row['id']]);
            }
            $this->pdo->commit();
            return $rows;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Logger::error('ProcessOutboxAction claim error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Procesa un evento reclamado: dispatchNow + COMPLETED, o transicion
     * FAILED con incremento de attempts + backoff + alerta terminal.
     *
     * @param array<string, mixed>                                          $row
     * @param array{claimed:int,completed:int,failed:int,reclaimed:int,terminal:int} $stats
     */
    private function processOne(array $row, array &$stats): void {
        $id = (int)$row['id'];
        $preAttempts = (int)($row['attempts'] ?? 0);

        try {
            $eventObj = unserialize(base64_decode((string)$row['payload']));
            if (!$eventObj instanceof EventInterface) {
                throw new \Exception('Unserialized payload is not an EventInterface');
            }

            ($this->dispatch)($eventObj);

            $upd = $this->pdo->prepare("UPDATE event_outbox SET status = 'COMPLETED', processed_at = NOW() WHERE id = :id");
            $upd->execute([':id' => $id]);
            $stats['completed']++;
        } catch (Throwable $e) {
            Logger::error('ProcessOutboxAction error procesando evento ' . $id . ': ' . $e->getMessage());

            // Todo 19: la alerta terminal se evalua sobre el valor
            // PRE-incremento (attempts == MAX - 1 antes del incremento).
            $isTerminal = $preAttempts === self::MAX_ATTEMPTS - 1;
            $newAttempts = $preAttempts + 1;
            $backoff = self::backoffMinutes($newAttempts);

            // FAILED incrementa attempts ANTES de fijar el backoff, y
            // SOBRESCRIBE el lease INCONDICIONALMENTE (fix MAJOR r6).
            $upd = $this->pdo->prepare(
                "UPDATE event_outbox SET status = 'FAILED', attempts = :att,
                        next_attempt_at = NOW() + INTERVAL {$backoff} MINUTE, processed_at = NOW()
                 WHERE id = :id"
            );
            $upd->execute([':id' => $id, ':att' => $newAttempts]);
            $stats['failed']++;

            if ($isTerminal) {
                $stats['terminal']++;
                Logger::error(
                    'ALERTA OUTBOX: evento ' . $id . ' alcanzo MAX (' . self::MAX_ATTEMPTS
                    . ') intentos — FAILED terminal.'
                );
            }
        }
    }
}
