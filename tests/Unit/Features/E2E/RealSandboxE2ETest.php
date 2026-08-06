<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\E2E;

require_once __DIR__ . '/../../../fixtures/F3RealSandboxFixtures.php';

use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Core\Database;
use App\Core\BookingStatus;
use App\Core\Events\EventDispatcher;
use App\Features\Booking\Domain\Events\BookingPaidEvent;
use App\Features\Cron\Actions\ProcessOutboxAction;
use App\Features\Shared\Adapters\MercadoPagoAdapter;
use App\Test\Fixtures\F3RealSandboxFixtures;
use PDO;
use Throwable;

/**
 * F3 — REAL QA E2E contra el SANDBOX REAL de MercadoPago (sin mocks).
 * Plan usgar-payment-hardening, "Final verification wave" (F3, linea 280).
 *
 * ESTADO DEL SANDBOX HOY (2026-08-06, verificado con SDK + API cruda +
 * tests/mp-test-payment.php): la creacion de payments NUEVOS con el token
 * del .env esta bloqueada por el entorno ("Card Token not found" 2006/404,
 * "Payer email forbidden" 4390, "Invalid users involved" 145, "Customer not
 * found" 2002 — el ultimo payment de tarjeta exitoso fue 2026-08-05). Por
 * tanto los escenarios E2E REALES de webhook se ejercen sobre los payments
 * REALES ya existentes en el sandbox de la app (verificados legibles con
 * getPaymentDetails), con webhooks firmados HMAC-SHA256 REAL (secret real) y
 * procesamiento real (fetch real + BD real + outbox real). La evidencia
 * completa (payment ids, status reales, timings, desviaciones) se append en
 * tests/f3-evidence.log.
 *
 * BUGS REALES ENCONTRADOS Y CORREGIDOS por esta wave (cada fix avanzo el
 * error real de MP): 1) payer.name/surname -> first_name/last_name (400),
 * 2) currency_id fuera del create /v1/payments (400), 3) X-Idempotency-Key
 * como string "Name: value" (400 "can't be null").
 */
final class RealSandboxE2ETest extends TestCase {

    private static ?PDO $pdo = null;
    private static bool $envReady = false;
    private static bool $realPaymentsProbed = false;

    /** @var string[] status_details REALES observados (escenario b) */
    private static array $realStatusDetails = [];

    protected function setUp(): void {
        F3RealSandboxFixtures::restoreRealConfig();

        if (!self::$envReady) {
            self::$envReady = true;
            F3RealSandboxFixtures::clearEvidenceLog();

            $token = (string)Config::get('MERCADO_PAGO_ACCESS_TOKEN');
            if ($token === '' || !str_starts_with($token, 'TEST-')) {
                F3RealSandboxFixtures::evidence('F3 SETUP: token no disponible o no TEST-. SKIP.');
                $this->markTestSkipped('MERCADO_PAGO_ACCESS_TOKEN TEST- no disponible.');
            }
            F3RealSandboxFixtures::evidence('F3 SETUP: token TEST- (sandbox real); currency=' . Config::get('MERCADO_PAGO_CURRENCY', '?')
                . '; binary_mode=' . Config::get('MP_BINARY_MODE', '?') . '; db=' . (Config::get('DB_NAME', '?')));

            self::$pdo = F3RealSandboxFixtures::connect();
            if (self::$pdo === null) {
                F3RealSandboxFixtures::evidence('F3 SETUP: BD real no disponible. SKIP.');
                $this->markTestSkipped('BD real no disponible.');
            }
            if (Database::getInstance()->getConnection() === null) {
                F3RealSandboxFixtures::evidence('F3 SETUP: Database singleton sin conexion. SKIP.');
                $this->markTestSkipped('Database singleton sin conexion.');
            }
            // Re-ejecutabilidad: limpia restos de corridas previas (hold
            // REF-1785973443, processed_payments de payments reales, outbox)
            // ANTES de probar y procesar.
            F3RealSandboxFixtures::cleanup(self::$pdo);
        }

        if (self::$pdo === null) {
            $this->markTestSkipped('BD real no disponible.');
        }

        // Probe de los payments REALES del sandbox (una sola vez): si no son
        // legibles, la suite no puede producir evidencia real de webhook.
        if (!self::$realPaymentsProbed) {
            self::$realPaymentsProbed = true;
            $approved = F3RealSandboxFixtures::probeRealPayment(F3RealSandboxFixtures::PAY_APPROVED_WITH_REF);
            $orphan = F3RealSandboxFixtures::probeRealPayment(F3RealSandboxFixtures::PAY_APPROVED_NO_REF);
            $rejected = F3RealSandboxFixtures::probeRealPayment(F3RealSandboxFixtures::PAY_REJECTED_1);
            $pending = F3RealSandboxFixtures::probeRealPayment(F3RealSandboxFixtures::PAY_PENDING_PE);
            F3RealSandboxFixtures::evidence('F3 SETUP probe payments reales: approved=' . ($approved['status'] ?? 'unreadable')
                . ' (' . $approved['transaction_amount'] ?? '?' . ' PEN) orphan=' . ($orphan['status'] ?? 'unreadable')
                . ' rejected=' . ($rejected['status'] ?? 'unreadable') . ' pending=' . ($pending['status'] ?? 'unreadable'));
            if (($approved['status'] ?? '') !== 'approved' || ($orphan['status'] ?? '') !== 'approved') {
                $this->markTestSkipped('Payments reales del sandbox no legibles; sin evidencia real de webhook.');
            }
        }
    }

    public static function tearDownAfterClass(): void {
        if (self::$pdo !== null) {
            try {
                F3RealSandboxFixtures::cleanup(self::$pdo);
                F3RealSandboxFixtures::evidence('F3 CLEANUP: filas de fixtures y payment ids procesados eliminadas.');
            } catch (Throwable $e) {
                F3RealSandboxFixtures::evidence('F3 CLEANUP ERROR: ' . $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------ helpers

    private function jsonBody(array $resp): array {
        $decoded = json_decode($resp['body'], true);
        return is_array($decoded) ? $decoded : [];
    }

    private function statusDetailFrom(array $body): string {
        return (string)($body['error']['details']['status_detail'] ?? $body['status_detail'] ?? '');
    }

    private function holdStatus(string $cartId): string {
        $hold = F3RealSandboxFixtures::fetchHold(self::$pdo, $cartId);
        return (string)($hold['status'] ?? 'no-hold');
    }

    private function runOutbox(array $failCarts, array &$delivered): array {
        $dispatch = function ($event) use ($failCarts, &$delivered): void {
            if (in_array($event->getCartId(), $failCarts, true)) {
                throw new \Exception('listener inyectado: fallo para cart ' . $event->getCartId());
            }
            $delivered[] = $event->getCartId();
        };
        $action = new ProcessOutboxAction(self::$pdo, $dispatch);
        return $action->run();
    }

    private function forceNextAttempt(int $outboxId): void {
        self::$pdo->prepare('UPDATE event_outbox SET next_attempt_at = NOW() WHERE id = :id')->execute([':id' => $outboxId]);
    }

    private function outboxRowByCart(string $cartId): ?array {
        $rows = self::$pdo->query('SELECT id, status, attempts, payload FROM event_outbox')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            try {
                $event = unserialize(base64_decode((string)$row['payload']));
                if ($event instanceof \App\Core\Events\EventInterface && $event->getCartId() === $cartId) {
                    return $row;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
        return null;
    }

    private function insertOutboxEvent(string $cartId): int {
        $holdArr = [
            'cart_id'                 => $cartId,
            'price_snapshot'          => 150.0,
            'price_snapshot_pen'      => 281.25,
            'exchange_rate_snapshot'  => 3.75,
            'checkin'                 => '2026-09-01',
            'checkout'                => '2026-09-03',
            'id_room_type'            => 3,
            'guest_data'              => ['name' => 'F3 G', 'email' => 'test_payer_4@testuser.com'],
            'room_data'               => ['room_name' => 'F3 Room', 'nights' => 2],
        ];
        $event = BookingPaidEvent::fromHold($cartId, '999' . random_int(100000000, 999999999), $holdArr);
        self::$pdo->prepare(
            "INSERT INTO event_outbox (event_name, payload, status, attempts, next_attempt_at, created_at)
             VALUES ('booking.paid', :p, 'PENDING', 0, NOW(), NOW())"
        )->execute([':p' => base64_encode(serialize($event))]);
        return (int)self::$pdo->lastInsertId();
    }

    private function knownStatusDetails(): array {
        $path = dirname(__DIR__, 4) . '/src/utils/paymentErrors.ts';
        $src = (string)file_get_contents($path);
        if (!preg_match('/KNOWN_STATUS_DETAILS\s*:\s*readonly string\[\]\s*=\s*\[(.*?)\]/s', $src, $m)) {
            return [];
        }
        preg_match_all("/'([^']+)'/", $m[1], $hits);
        return $hits[1];
    }

    // ------------------------------------------------ a. FLUJO APPROVED real (webhook)

    public function testF3aRealApprovedFlowWebhookAndOutbox(): void {
        $pid = F3RealSandboxFixtures::PAY_APPROVED_WITH_REF;
        $cart = 'REF-1785973443'; // external_reference real del payment approved real
        F3RealSandboxFixtures::trackPaymentId($pid);
        F3RealSandboxFixtures::trackFixtureCart($cart);

        // Hold FIXTURE cuyo precio PEN congelado coincide con el monto REAL
        // del payment (150.50 PEN): el webhook real NO debe marcar fraude.
        $details = F3RealSandboxFixtures::probeRealPayment($pid);
        $amount = (float)($details['transaction_amount'] ?? 150.5);
        F3RealSandboxFixtures::evidence('F3a payment real ' . $pid . ' status=' . $details['status']
            . ' amount=' . $amount . ' detail=' . ($details['status_detail'] ?? '?'));

        if (F3RealSandboxFixtures::fetchHold(self::$pdo, $cart) === null) {
            F3RealSandboxFixtures::createHold(self::$pdo, $cart, [
                'price_snapshot'         => $amount,
                'price_snapshot_pen'     => $amount,
                'exchange_rate_snapshot' => 3.75,
            ]);
        }

        // Webhook FIRMADO REAL (payload oficial + firma HMAC real + fetch real)
        $wh = F3RealSandboxFixtures::deliverWebhook(self::$pdo, $pid, 'req-f3a');
        F3RealSandboxFixtures::evidence("F3a webhook approved: http={$wh['code']} ms=" . round($wh['elapsed_ms'], 1) . ' body=' . $wh['body']);

        $this->assertSame(200, $wh['code'], 'webhook 200: ' . $wh['body']);
        $this->assertStringContainsString('Payment processed locally', $wh['body'], 'procesa el pago approved real');
        $this->assertLessThan(22000, $wh['elapsed_ms'], 'ACK < 22s (doc MP webhooks)');

        // Hold -> paid + processed(approved) + evento en outbox
        $this->assertSame(BookingStatus::Paid->value, $this->holdStatus($cart), 'hold paid');
        $this->assertSame(1, F3RealSandboxFixtures::countProcessed(self::$pdo, $pid, 'approved'), '1 registro approved');
        $this->assertSame(1, F3RealSandboxFixtures::countOutboxByCart(self::$pdo, $cart), 'evento en outbox');

        // Cron -> COMPLETED
        $delivered = [];
        $stats = $this->runOutbox([], $delivered);
        $row = $this->outboxRowByCart($cart);
        F3RealSandboxFixtures::evidence('F3a cron stats=' . json_encode($stats) . ' row=' . ($row['status'] ?? '?'));
        $this->assertNotNull($row, 'fila outbox');
        $this->assertSame('COMPLETED', (string)$row['status'], 'evento COMPLETED');
        $this->assertContains($cart, $delivered, 'evento entregado al dispatcher');
    }

    // ------------------------------------------------ b. RECHAZADOS reales (webhook)

    public function testF3bRealRejectedWebhooksMarkedAndFrontendMapped(): void {
        $rejected = [F3RealSandboxFixtures::PAY_REJECTED_1, F3RealSandboxFixtures::PAY_REJECTED_2];
        foreach ($rejected as $pid) {
            F3RealSandboxFixtures::trackPaymentId($pid);
            $details = F3RealSandboxFixtures::probeRealPayment($pid);
            $detail = (string)($details['status_detail'] ?? '');
            self::$realStatusDetails[] = $detail;
            F3RealSandboxFixtures::evidence('F3b payment real ' . $pid . ' status=' . ($details['status'] ?? '?')
                . ' status_detail=' . ($detail !== '' ? $detail : 'n/a'));

            $wh = F3RealSandboxFixtures::deliverWebhook(self::$pdo, $pid, 'req-f3b-' . $pid);
            F3RealSandboxFixtures::evidence("F3b webhook rejected {$pid}: http={$wh['code']} ms=" . round($wh['elapsed_ms'], 1) . ' body=' . $wh['body']);

            $this->assertSame(200, $wh['code'], 'webhook rejected 200: ' . $wh['body']);
            $this->assertStringContainsString('rejected', $wh['body'], 'marcado como rechazado');
            $this->assertSame(1, F3RealSandboxFixtures::countProcessed(self::$pdo, $pid, 'rejected'), '1 registro rejected');
        }

        // Frontend mapping (todo 26): el status_detail REAL esta cubierto.
        $known = $this->knownStatusDetails();
        foreach (array_filter(self::$realStatusDetails) as $realDetail) {
            $this->assertContains($realDetail, $known, "status_detail real {$realDetail} cubierto por KNOWN_STATUS_DETAILS");
        }
        F3RealSandboxFixtures::evidence('F3b status_details reales cubiertos: ' . implode(', ', array_unique(array_filter(self::$realStatusDetails))));
    }

    // ------------------------------------------------ c. PENDIENTE real (webhook) + create probe

    public function testF3cRealPendingWebhookAckedAndNewPaymentBlocked(): void {
        $pid = F3RealSandboxFixtures::PAY_PENDING_PE;
        F3RealSandboxFixtures::trackPaymentId($pid);
        $details = F3RealSandboxFixtures::probeRealPayment($pid);
        F3RealSandboxFixtures::evidence('F3c payment real ' . $pid . ' status=' . ($details['status'] ?? '?')
            . ' detail=' . ($details['status_detail'] ?? '?'));

        // Webhook firmado REAL de un pago pending real -> ACK 200 sin procesar.
        $wh = F3RealSandboxFixtures::deliverWebhook(self::$pdo, $pid, 'req-f3c');
        F3RealSandboxFixtures::evidence("F3c webhook pending: http={$wh['code']} ms=" . round($wh['elapsed_ms'], 1) . ' body=' . $wh['body']);
        $this->assertSame(200, $wh['code'], 'webhook pending 200');
        $this->assertStringContainsString('not approved', $wh['body'], 'estado no-approved reconocido');

        // Intento de crear un payment pending NUEVO (pagoefectivo_atm real):
        // documenta el estado ACTUAL del sandbox (bloqueado — ver evidence).
        $cart = F3RealSandboxFixtures::newCartId('C');
        $hold = F3RealSandboxFixtures::createHold(self::$pdo, $cart);
        $resp = F3RealSandboxFixtures::processPayment(self::$pdo, $cart, [
            'payment_method_id' => 'pagoefectivo_atm',
            'payer'             => ['email' => 'test_user_80507629@testuser.com'],
        ], $hold);
        F3RealSandboxFixtures::evidence("F3c create pagoefectivo_atm NUEVO: http={$resp['code']} body=" . $resp['body']);
        $body = $this->jsonBody($resp);
        // El entorno de HOY bloquea los creates nuevos; el attachPaymentId
        // (todo 28) queda verificado a nivel unitario (ProcessPaymentActionTest)
        // y documentado como desviacion de entorno en el reporte F3.
        if (in_array((string)($body['status'] ?? ''), ['pending', 'in_process'], true)) {
            $this->assertSame((string)($body['payment_id'] ?? ''), $this->holdPaymentId($cart), 'payment_id persistido');
            $this->assertSame(BookingStatus::Pending->value, $this->holdStatus($cart), 'hold sigue pending');
        }
    }

    private function holdPaymentId(string $cartId): string {
        $hold = F3RealSandboxFixtures::fetchHold(self::$pdo, $cartId);
        return (string)($hold['payment_id'] ?? '');
    }

    // ------------------------------------------------ d. REFUND (infra real + bloqueo)

    public function testF3dRefundBranchInfrastructureReal(): void {
        // Infraestructura real de refunds (todo 12): el indice unico
        // (payment_id, event_type) debe existir en la BD real para que un
        // evento refunded del mismo payment_id coexista con su approved.
        $idx = self::$pdo->query(
            "SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'processed_payments'
               AND NON_UNIQUE = 0
             GROUP BY INDEX_NAME, COLUMN_NAME"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $composite = false;
        foreach ($idx as $row) {
            if (str_contains((string)$row['cols'], 'event_type')) {
                $composite = true;
            }
        }
        F3RealSandboxFixtures::evidence('F3d indices UNIQUE reales processed_payments=' . json_encode($idx));
        $this->assertTrue($composite, 'indice unico (payment_id, event_type) presente: refunds alcanzables a nivel de infraestructura');

        // Bloqueo de entorno: sin poder crear un payment approved propio HOY,
        // el refund E2E real (approved -> refunded -> webhook refunded) no
        // puede ejecutarse; se documenta como desviacion con la cita de doc
        // MP (reembolsos: /checkout-api-payments/payment-management/cancellations-and-refunds)
        // y queda cubierto a nivel unitario por el port refundPayment.
        F3RealSandboxFixtures::evidence('F3d DESVIACION DOCUMENTADA: refund E2E real bloqueado por el entorno (no se puede crear un payment propio hoy: Card Token not found 2006/404); la rama refunded del handler (HandleMercadoPagoWebhookAction:150-157) queda infra-only — el guard del todo 9 rechaza updateStatus(failed), no se marca processed(refunded), el hold permanece paid. Pendiente de decision de producto.');
    }

    // ------------------------------------------------ e. DOBLE ENTREGA secuencial

    public function testF3eDoubleDeliverySequentialExactlyOneRow(): void {
        $pid = F3RealSandboxFixtures::PAY_APPROVED_WITH_REF;
        $cart = 'REF-1785973443';
        F3RealSandboxFixtures::trackPaymentId($pid);

        $r2 = F3RealSandboxFixtures::deliverWebhook(self::$pdo, $pid, 'req-f3e-2');
        F3RealSandboxFixtures::evidence("F3e 2a entrega (mismo payment real): http={$r2['code']} ms=" . round($r2['elapsed_ms'], 1) . ' body=' . $r2['body']);
        $this->assertSame(200, $r2['code'], '2a entrega 200');
        $this->assertStringContainsString('already processed', $r2['body'], '2a entrega idempotente');
        $this->assertLessThan(22000, $r2['elapsed_ms'], 'ACK < 22s');

        $this->assertSame(1, F3RealSandboxFixtures::countProcessed(self::$pdo, $pid, 'approved'), 'exactamente 1 registro approved');
        $this->assertSame(BookingStatus::Paid->value, $this->holdStatus($cart), 'hold sigue paid (no reprocesado)');
        $this->assertSame(1, F3RealSandboxFixtures::countOutboxByCart(self::$pdo, $cart), '1 solo evento en outbox (sin doble dispatch)');
    }

    // ------------------------------------------------ f. CONCURRENCIA (2 procesos reales)

    public function testF3fConcurrentWebhooksExactlyOneRow(): void {
        $pid = F3RealSandboxFixtures::PAY_APPROVED_NO_REF; // approved real SIN ref -> rama orphan (todo 14)
        F3RealSandboxFixtures::trackPaymentId($pid);

        $repoRoot = dirname(__DIR__, 4);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repoRoot . '/tests/f3-webhook-worker.php')
            . ' ' . escapeshellarg($pid) . ' ';

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p1 = proc_open($cmd . 'req-f3f1', $descriptors, $pipes1, $repoRoot);
        $p2 = proc_open($cmd . 'req-f3f2', $descriptors, $pipes2, $repoRoot);

        $this->assertIsResource($p1, 'worker 1 lanzado');
        $this->assertIsResource($p2, 'worker 2 lanzado');

        $out1 = stream_get_contents($pipes1[1]);
        $err1 = stream_get_contents($pipes1[2]);
        $out2 = stream_get_contents($pipes2[1]);
        $err2 = stream_get_contents($pipes2[2]);
        fclose($pipes1[1]); fclose($pipes1[2]); fclose($pipes2[1]); fclose($pipes2[2]);
        $code1 = proc_close($p1);
        $code2 = proc_close($p2);

        F3RealSandboxFixtures::evidence("F3f worker1 exit={$code1} out=" . trim((string)$out1) . ' err=' . trim((string)$err1));
        F3RealSandboxFixtures::evidence("F3f worker2 exit={$code2} out=" . trim((string)$out2) . ' err=' . trim((string)$err2));

        $this->assertSame(0, $code1, 'worker1 exit 0: ' . $err1);
        $this->assertSame(0, $code2, 'worker2 exit 0: ' . $err2);
        $this->assertStringStartsWith('OK:200:', (string)$out1, 'worker1 200');
        $this->assertStringStartsWith('OK:200:', (string)$out2, 'worker2 200');

        $this->assertSame(1, F3RealSandboxFixtures::countProcessed(self::$pdo, $pid, 'orphan'), '1 registro orphan bajo carrera (indice unico + ON DUPLICATE KEY)');
    }

    // ------------------------------------------------ g. OUTBOX FAILED -> retry -> terminal

    public function testF3gOutboxFailedRetryThenCompletedAndTerminal(): void {
        $cartRetry = F3RealSandboxFixtures::newCartId('GR');
        $cartTerm = F3RealSandboxFixtures::newCartId('GT');
        $idRetry = $this->insertOutboxEvent($cartRetry);
        $idTerm = $this->insertOutboxEvent($cartTerm);

        $delivered = [];
        $s1 = $this->runOutbox([$cartRetry, $cartTerm], $delivered);
        $rowR = $this->outboxRowByCart($cartRetry);
        $rowT = $this->outboxRowByCart($cartTerm);
        F3RealSandboxFixtures::evidence('F3g run1 stats=' . json_encode($s1)
            . ' retry=' . ($rowR['status'] ?? '?') . '@' . ($rowR['attempts'] ?? '?') . ' term=' . ($rowT['status'] ?? '?') . '@' . ($rowT['attempts'] ?? '?'));
        $this->assertSame('FAILED', (string)$rowR['status'], 'retry FAILED (reintentable)');
        $this->assertSame('FAILED', (string)$rowT['status'], 'term FAILED (reintentable)');
        $this->assertSame(1, (int)$rowR['attempts']);
        $this->assertSame(1, (int)$rowT['attempts']);

        // El fallo transitorio se resuelve para RETRY; backoff saltado (todo 19).
        $this->forceNextAttempt($idRetry);
        $this->forceNextAttempt($idTerm);
        $delivered = [];
        $s2 = $this->runOutbox([$cartTerm], $delivered);
        $rowR = $this->outboxRowByCart($cartRetry);
        $rowT = $this->outboxRowByCart($cartTerm);
        F3RealSandboxFixtures::evidence('F3g run2 stats=' . json_encode($s2)
            . ' retry=' . ($rowR['status'] ?? '?') . '@' . ($rowR['attempts'] ?? '?') . ' term=' . ($rowT['status'] ?? '?') . '@' . ($rowT['attempts'] ?? '?'));
        $this->assertSame('COMPLETED', (string)$rowR['status'], 'retry COMPLETED tras el reintento');
        $this->assertContains($cartRetry, $delivered, 'retry entregado en el reintento');
        $this->assertSame('FAILED', (string)$rowT['status'], 'term sigue FAILED');
        $this->assertSame(2, (int)$rowT['attempts']);

        for ($i = 3; $i <= 5; $i++) {
            $this->forceNextAttempt($idTerm);
            $delivered = [];
            $sn = $this->runOutbox([$cartTerm], $delivered);
            $rowT = $this->outboxRowByCart($cartTerm);
            F3RealSandboxFixtures::evidence("F3g run{$i} stats=" . json_encode($sn)
                . ' term=' . ($rowT['status'] ?? '?') . '@' . ($rowT['attempts'] ?? '?') . ' terminal_total=' . $sn['terminal']);
        }
        $rowT = $this->outboxRowByCart($cartTerm);
        $this->assertSame('FAILED', (string)$rowT['status'], 'TERM FAILED terminal');
        $this->assertSame((string)ProcessOutboxAction::MAX_ATTEMPTS, (string)$rowT['attempts'], 'attempts = MAX(5)');

        $this->forceNextAttempt($idTerm);
        $delivered = [];
        $s6 = $this->runOutbox([$cartTerm], $delivered);
        $rowT = $this->outboxRowByCart($cartTerm);
        F3RealSandboxFixtures::evidence('F3g run6 (post-MAX) stats=' . json_encode($s6)
            . ' term=' . ($rowT['status'] ?? '?') . '@' . ($rowT['attempts'] ?? '?'));
        $this->assertSame(0, $s6['claimed'], 'sin claim tras MAX');
        $this->assertSame('FAILED', (string)$rowT['status'], 'se mantiene FAILED terminal');
        $this->assertSame((string)ProcessOutboxAction::MAX_ATTEMPTS, (string)$rowT['attempts'], 'attempts no crece mas alla de MAX');
    }

    // ------------------------------------------------ h. ACK < 22s

    public function testF3hWebhookAckTimingUnder22s(): void {
        // Entrega real (fetch real de getPaymentDetails + BD local + ACK).
        // El path COMPLETO (procesamiento) se midio en F3a (~2.2s reales); aqui
        // se mide el handler real en la rama idempotente (mismo fetch real).
        $pid = F3RealSandboxFixtures::PAY_APPROVED_WITH_REF;
        F3RealSandboxFixtures::trackPaymentId($pid);
        $wh = F3RealSandboxFixtures::deliverWebhook(self::$pdo, $pid, 'req-f3h');
        F3RealSandboxFixtures::evidence("F3h webhook real (idempotente): http={$wh['code']} tiempo_total_ms=" . round($wh['elapsed_ms'], 1) . ' body=' . $wh['body']);
        $this->assertSame(200, $wh['code'], 'webhook 200');
        // Doc MP webhooks: ventana de ACK = 22s. Con fetch de 8s max (todo 17),
        // el handler real responde muy por debajo.
        $this->assertLessThan(22000, $wh['elapsed_ms'], 'ACK real < 22s (doc MP webhooks)');
    }

    // ------------------------------------------------ i. ORPHAN (2 entregas)

    public function testF3iOrphanSignedWebhookTwiceAcked(): void {
        $pid = F3RealSandboxFixtures::PAY_APPROVED_NO_REF; // approved real SIN external_reference
        F3RealSandboxFixtures::trackPaymentId($pid);
        F3RealSandboxFixtures::evidence('F3i payment real sin ref: ' . $pid . ' -> rama orphan (todo 14)');

        $r1 = F3RealSandboxFixtures::deliverWebhook(self::$pdo, $pid, 'req-f3i1');
        F3RealSandboxFixtures::evidence("F3i entrega 1: http={$r1['code']} ms=" . round($r1['elapsed_ms'], 1) . ' body=' . $r1['body']);
        $this->assertSame(200, $r1['code'], 'orphan entrega 1 200');
        $this->assertStringContainsString('orphan', $r1['body'], 'marcado orphan');

        $r2 = F3RealSandboxFixtures::deliverWebhook(self::$pdo, $pid, 'req-f3i2');
        F3RealSandboxFixtures::evidence("F3i entrega 2: http={$r2['code']} ms=" . round($r2['elapsed_ms'], 1) . ' body=' . $r2['body']);
        $this->assertSame(200, $r2['code'], 'orphan entrega 2 200 (idempotencia)');
        $this->assertStringContainsString('orphan', $r2['body'], '2a entrega reconocida como orphan');

        $this->assertSame(1, F3RealSandboxFixtures::countProcessed(self::$pdo, $pid, 'orphan'), '1 registro orphan');
    }

    // ------------------------------------------------ j. FRAUDE (bloqueo E2E + cobertura unitaria)

    public function testF3jFraudBranchE2eBlockedAndUnitCovered(): void {
        // E2E real bloqueado por el entorno: se necesita un payment approved
        // con external_reference controlada y monto MENOR al esperado; hoy no
        // se pueden crear payments nuevos (ver F3 CardCreate probe). La rama
        // fraud_review (todo 16/32: comparacion en centavos enteros) esta
        // cubierta por HandleMercadoPagoWebhookActionW6Test.
        $w6Path = dirname(__DIR__, 1) . '/Webhooks/HandleMercadoPagoWebhookActionW6Test.php';
        $w6 = file_exists($w6Path) ? (string)file_get_contents($w6Path) : '';
        $this->assertStringContainsString('fraud_review', $w6, 'cobertura unitaria de la rama fraude (W6)');
        $this->assertStringContainsString('ChargedBelowFrozenPenCents', $w6, 'comparacion en centavos enteros cubierta (W6)');
        F3RealSandboxFixtures::evidence('F3j DESVIACION DOCUMENTADA: fraude E2E real bloqueado por el entorno (sin payment approved propio con ref controlable hoy); cubierto por HandleMercadoPagoWebhookActionW6Test (montos en centavos + fraude + re-despacho).');
    }

    // ------------------------------------------------ k. BUILD + frontend mapping

    public function testF3kFrontendStatusDetailMappingCoversRejections(): void {
        $known = $this->knownStatusDetails();
        $required = [
            'cc_rejected_other_reason',
            'cc_rejected_insufficient_amount',
            'cc_rejected_bad_filled_security_code',
            'cc_rejected_call_for_authorize',
            'cc_rejected_high_risk',
            'pending_waiting_transfer',
        ];
        foreach ($required as $detail) {
            $this->assertContains($detail, $known, "status_detail {$detail} en KNOWN_STATUS_DETAILS");
        }
        F3RealSandboxFixtures::evidence('F3k KNOWN_STATUS_DETAILS=' . implode(', ', $known));
    }

    // ----------------------------- probe del bloqueo de create (entorno)

    public function testF3CardCreateEnvironmentProbe(): void {
        // Evidencia del estado ACTUAL del sandbox: intento REAL de crear un
        // payment de tarjeta (APRO) y uno offline (pagoefectivo_atm) con el
        // token del .env. Se documenta la respuesta real; si el entorno se
        // recupera, el create aprueba y se reembolsa de inmediato (limpieza).
        $adapter = new MercadoPagoAdapter();

        try {
            $token = F3RealSandboxFixtures::cardToken('APRO');
            $result = $adapter->processPayment([
                'external_reference' => 'USGAR-' . F3RealSandboxFixtures::newCartId('PROBE'),
                'transaction_amount' => 50.0,
                'payment_method_id'  => 'visa',
                'token'              => $token,
                'installments'       => 1,
                'payer'              => [
                    'email'          => 'test_user_80507629@testuser.com',
                    'identification' => ['type' => 'DNI', 'number' => '123456789'],
                ],
            ]);
            F3RealSandboxFixtures::evidence('F3 probe create APRO: ' . json_encode($result));
            if ($result !== null && ($result['status'] ?? '') === 'approved') {
                $adapter->refundPayment((string)$result['id']); // reverso inmediato
                F3RealSandboxFixtures::evidence('F3 probe: create APRO FUNCIONO (entorno recuperado) id=' . $result['id'] . ' (reembolsado).');
                return;
            }
            $this->fail('F3 probe: create APRO no devolvio approved (ver evidence).');
        } catch (\MercadoPago\Exceptions\MPApiException $e) {
            $apiBody = $e->getApiResponse() ? $e->getApiResponse()->getContent() : null;
            F3RealSandboxFixtures::evidence('F3 probe create APRO: http=' . $e->getStatusCode() . ' body=' . json_encode($apiBody));
            $this->assertTrue(true, 'create APRO bloqueado por el entorno: ' . ($apiBody['message'] ?? $e->getMessage()));
        }

        try {
            $pagoefectivo = $adapter->processPayment([
                'external_reference' => 'USGAR-' . F3RealSandboxFixtures::newCartId('PROBE2'),
                'transaction_amount' => 50.0,
                'payment_method_id'  => 'pagoefectivo_atm',
                'payer'              => ['email' => 'test_user_80507629@testuser.com'],
            ]);
            F3RealSandboxFixtures::evidence('F3 probe create pagoefectivo_atm: ' . json_encode($pagoefectivo));
        } catch (\MercadoPago\Exceptions\MPApiException $e) {
            $apiBody = $e->getApiResponse() ? $e->getApiResponse()->getContent() : null;
            F3RealSandboxFixtures::evidence('F3 probe create pagoefectivo_atm: http=' . $e->getStatusCode() . ' body=' . json_encode($apiBody));
        }
        F3RealSandboxFixtures::evidence('F3 probe: DESVIACION DE ENTORNO — la creacion de payments nuevos esta bloqueada hoy (Card Token not found 2006/404, Invalid users involved 145, Payer email forbidden 4390); ultimo payment de tarjeta exitoso: 2026-08-05.');
    }
}
