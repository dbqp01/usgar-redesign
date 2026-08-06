<?php
declare(strict_types=1);

namespace App\Test\Unit\Core\Events;

require_once __DIR__ . '/../../../fixtures/W4TestDoubles.php';

use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Core\Database;
use App\Core\Events\EventDispatcher;
use App\Features\Cron\Actions\ProcessOutboxAction;
use App\Test\Fixtures\W4TestEvent;
use PDO;
use Throwable;

/**
 * Tests del outbox transaccional (Wave 4, todo 18).
 *
 * El INSERT del evento en event_outbox ocurre DENTRO de la transaccion del
 * llamador (webhook) ANTES del commit, con next_attempt_at = NOW() para
 * eventos frescos (fix r6 de Momus: sin esto, `next_attempt_at NULL <= NOW()`
 * es NULL/false en SQL y el cron del todo 19 nunca los procesaria). Sin
 * transaccion activa -> autocommit propio (back-compat para
 * ReconcilePaymentsAction).
 *
 * Se usa la conexion REAL del singleton (la misma que usa EventDispatcher):
 * filas aisladas W4TEST-* + limpieza estricta por id. El patrón de outbox
 * verificado contra microservices.io (transactional-outbox): el mensaje se
 * persiste en la MISMA transaccion que el cambio de negocio; si el commit
 * se confirma, el evento YA esta en el outbox (no se pierde entre commit y
 * ACK).
 */
final class EventDispatcherTest extends TestCase {
    private ?PDO $pdo = null;
    private string $prefix = '';
    private int $beforeId = 0;
    /** @var array<int, int> ids de filas creadas por este test. */
    private array $createdIds = [];

    protected function setUp(): void {
        Config::set('APP_ENV', 'testing');
        Config::set('EXCHANGE_RATE_USD_PEN', '3.80');

        $this->pdo = Database::getInstance()->getConnection();
        if ($this->pdo === null) {
            $this->markTestSkipped('BD no disponible: tests de outbox omitidos (limitacion documentada).');
        }
        // Auto-heal real del cron (mismo mecanismo del deploy).
        (new ProcessOutboxAction($this->pdo, fn (mixed $e): mixed => null))->ensureOutboxSchema();

        // Listener no-op: sin listeners registrados, dispatch retornaria antes
        // del INSERT del outbox.
        EventDispatcher::getInstance()->subscribe('booking.paid', new \App\Test\Fixtures\W4NoopListener());

        $this->prefix = 'W4TEST-' . date('YmdHis') . '-' . random_int(1000, 9999);
        $this->beforeId = (int)$this->pdo->query('SELECT COALESCE(MAX(id),0) FROM event_outbox')->fetchColumn();
        $this->createdIds = [];
    }

    protected function tearDown(): void {
        if ($this->pdo !== null) {
            foreach ($this->createdIds as $id) {
                $this->pdo->prepare('DELETE FROM event_outbox WHERE id = :id')->execute([':id' => $id]);
            }
        }
    }

    /**
     * Localiza la fila que el dispatcher inserto para un evento W4TestEvent:
     * el payload es base64(serialize) — el marker NO es fiable para LIKE
     * (base64 parte la subcadena en limites de 3 bytes), asi que se filtran
     * las candidatas en PHP (id > beforeId, event_name = booking.paid) y se
     * deserializa para confirmar la identidad exacta.
     */
    private function findEventRow(string $cartId): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM event_outbox WHERE id > :before AND event_name = :name ORDER BY id DESC LIMIT 20'
        );
        $stmt->execute([':before' => $this->beforeId, ':name' => 'booking.paid']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $obj = @unserialize(base64_decode((string)$row['payload']));
            if ($obj instanceof W4TestEvent && $obj->getCartId() === $cartId) {
                $this->createdIds[] = (int)$row['id'];
                return $row;
            }
        }
        return null;
    }

    // =====================================================================
    // Todo 18 — INSERT dentro de la txn del llamador + next_attempt_at.
    // =====================================================================

    public function testEventIsInOutboxAfterCommitEvenIfPostCommitWorkFails(): void {
        // QA- del acceptance: inyeccion de fallo ENTRE commit y ACK -> el
        // evento YA esta en event_outbox (no se pierde en la ventana
        // commit->ACK). El INSERT corre DENTRO de la txn del llamador.
        $cartId = $this->prefix . '-POSTCOMMIT';

        $this->pdo->beginTransaction();
        EventDispatcher::getInstance()->dispatch(new W4TestEvent($cartId));
        $this->pdo->commit();

        // Fallo simulado despues del commit (excepcion entre commit y ACK).
        try {
            throw new \RuntimeException('fallo post-commit simulado');
        } catch (Throwable $e) {
            // ignorado: lo que importa es que el evento ya persistio
        }

        $row = $this->findEventRow($cartId);
        $this->assertNotNull($row, 'El evento YA esta en event_outbox tras el commit (outbox transaccional).');
        $this->assertSame('PENDING', $row['status']);
        $this->assertNotNull($row['next_attempt_at'], 'Evento fresco fija next_attempt_at = NOW() (nunca NULL).');
        $this->assertSame(0, (int)$row['attempts']);
    }

    public function testFreshEventGetsNextAttemptAtNowInsideCallerTransaction(): void {
        // El INSERT se une a la transaccion del llamador: dentro de la misma
        // conexion la fila es visible ANTES del commit (uncommitted) y su
        // next_attempt_at esta fijado.
        $cartId = $this->prefix . '-TXN';

        $this->pdo->beginTransaction();
        EventDispatcher::getInstance()->dispatch(new W4TestEvent($cartId));
        $rowBeforeCommit = $this->findEventRow($cartId);
        $this->pdo->commit();

        $this->assertNotNull($rowBeforeCommit, 'El evento es visible dentro de la txn antes del commit.');
        $this->assertNotNull($rowBeforeCommit['next_attempt_at'], 'next_attempt_at = NOW() para eventos frescos.');
    }

    public function testDispatchWithoutActiveTransactionUsesAutocommit(): void {
        // Back-compat para ReconcilePaymentsAction: sin transaccion activa el
        // INSERT corre en autocommit propio y la fila es visible de inmediato.
        $cartId = $this->prefix . '-AUTO';

        EventDispatcher::getInstance()->dispatch(new W4TestEvent($cartId));

        $row = $this->findEventRow($cartId);
        $this->assertNotNull($row, 'Sin txn activa: autocommit propio, fila visible de inmediato.');
        $this->assertNotNull($row['next_attempt_at']);
    }

    public function testRollbackOfCallerTransactionRemovesEvent(): void {
        // Corolario del patron transactional-outbox: si el llamador hace
        // rollback, el evento NO debe persistir (el mensaje vive y muere con
        // la transaccion de negocio).
        $cartId = $this->prefix . '-ROLLBACK';

        $this->pdo->beginTransaction();
        EventDispatcher::getInstance()->dispatch(new W4TestEvent($cartId));
        $this->pdo->rollBack();

        $this->assertNull($this->findEventRow($cartId), 'Rollback del llamador descarta el evento (outbox transaccional).');
    }
}
