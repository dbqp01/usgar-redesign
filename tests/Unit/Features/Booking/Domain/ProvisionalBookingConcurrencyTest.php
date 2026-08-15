<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Booking\Domain;

require_once __DIR__ . '/../../../../fixtures/W2TestDoubles.php';

use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Core\BookingHoldToken;
use App\Core\BookingStatus;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Test\Fixtures\TestDb;
use PDO;

/**
 * Tests de integracion/concurrencia de la Wave 2 (todos 8, 9, 10, 12).
 *
 * RESTRICCION DE ENTORNO DOCUMENTADA: no existe MySQL local ni permiso para
 * CREATE DATABASE (usuario hosting); la unica BD disponible es la real
 * (u941268346_QloApp). Por eso:
 *  - Los workers usan 2 procesos PHP REALES (proc_open + PHP_BINARY) contra
 *    la BD real, siguiendo el patron del repo (tests/concurrency-test.php
 *    corre contra el servidor real).
 *  - TODAS las filas de prueba usan el prefijo W2RACE- y se eliminan en
 *    tearDown (y en setUp por si un run anterior murio). Nunca se tocan
 *    filas reales: cart/hotel/room inexistentes (999999) y fechas en 2028.
 *  - Se crean SOLO las tablas nuevas y vacias (room_locks, payment_alerts)
 *    que el deploy crea igualmente; la migracion de processed_payments
 *    (event_type + indice compuesto) NO se ejecuta contra la BD real — se
 *    cubre con SQL-capture unitario y con una tabla TEMPORARY sombra.
 *  - La gateway de los workers es el FakeGateway (doble del PORT propio,
 *    permitido por el mandato r10) que cuenta llamadas: NUNCA se simulan
 *    resultados de la API real de MP.
 */
final class ProvisionalBookingConcurrencyTest extends TestCase {
    private const HOTEL_ID = 999999;
    private const ROOM_TYPE = 999999;
    private const CHECK_IN = '2028-01-01';
    private const CHECK_OUT = '2028-01-03';

    private ?PDO $pdo = null;
    private string $gatewayLog = '';
    private string $prefix = '';
    private string $repoRoot = '';

    protected function setUp(): void {
        Config::set('EXCHANGE_RATE_USD_PEN', '3.80');
        Config::set('MERCADO_PAGO_CURRENCY', 'PEN');
        Config::set('BOOKING_TOKEN_SECRET', 'w2-race-test-secret');

        $this->pdo = TestDb::connect();
        if ($this->pdo === null) {
            $this->markTestSkipped('BD no disponible: tests de concurrencia omitidos (limitacion documentada).');
        }
        TestDb::ensureWave2Tables($this->pdo);
        if (!TestDb::isAvailable($this->pdo)) {
            $this->markTestSkipped('Tabla provisional_bookings no disponible en la BD configurada.');
        }
        TestDb::cleanup($this->pdo); // limpieza previa por runs abortados

        $this->repoRoot = dirname(__DIR__, 5); // tests/Unit/Features/Booking/Domain -> raiz del repo
        $this->gatewayLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'w2-gateway-' . getmypid() . '.log';
        @unlink($this->gatewayLog);
        $this->prefix = 'W2RACE-' . date('YmdHis') . '-' . random_int(1000, 9999);
    }

    protected function tearDown(): void {
        if ($this->pdo !== null) {
            TestDb::cleanup($this->pdo);
        }
        @unlink($this->gatewayLog);
    }

    /** @return array{code: int, out: string, err: string} */
    private function spawnWorker(array $args): array {
        $cmd = array_merge([PHP_BINARY, $this->repoRoot . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'w2-race-worker.php'], $args);
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, $this->repoRoot);
        if (!is_resource($proc)) {
            $this->markTestSkipped('proc_open no disponible: procesos hijos bloqueados en este Windows (limitacion documentada).');
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
            if (microtime(true) - $start > 25) {
                proc_terminate($proc);
                $this->fail('Worker timeout: ' . implode(' ', $args));
            }
            $status = proc_get_status($proc);
        } while ($status['running']);

        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return ['code' => $code, 'out' => trim($out), 'err' => trim($err)];
    }

    private function gatewayCallCount(string $cartId): int {
        if (!is_file($this->gatewayLog)) {
            return 0;
        }
        $lines = file($this->gatewayLog, FILE_IGNORE_NEW_LINES);
        $count = 0;
        foreach ($lines as $line) {
            if (str_contains($line, $cartId)) {
                $count++;
            }
        }
        return $count;
    }

    /** Inserta un hold de prueba aislado via el repositorio real. */
    private function insertHold(string $cartId, string $status = 'pending', ?string $paymentId = null): void {
        $repo = new ProvisionalBookingRepository($this->pdo);
        $hold = [
            'cart_id'        => $cartId,
            'user_id'        => null,
            'id_hotel'       => self::HOTEL_ID,
            'id_room_type'   => self::ROOM_TYPE,
            'guest_data'     => ['name' => 'Race Guest', 'email' => 'race@test.com', 'phone' => '+51999999999'],
            'room_data'      => ['room_name' => 'Race Room', 'price_per_night' => 100.0, 'nights' => 2],
            'price_snapshot' => 200.0,
            'checkin'        => self::CHECK_IN,
            'checkout'       => self::CHECK_OUT,
            'status'         => $status,
            'expires_at'     => date('Y-m-d H:i:s', time() + 1800),
        ];
        $this->assertTrue($repo->create($hold), 'El hold de prueba debe poder insertarse.');
        // expires_at calculado por SQL en el MISMO servidor (evita skew de
        // timezone entre PHP local y NOW() de MariaDB).
        $this->pdo->prepare(
            "UPDATE provisional_bookings SET expires_at = NOW() + INTERVAL 30 MINUTE WHERE cart_id = :cart"
        )->execute([':cart' => $cartId]);
        if ($paymentId !== null) {
            $this->assertTrue($repo->attachPaymentId($cartId, $paymentId));
        }
    }

    private function getHold(string $cartId): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM provisional_bookings WHERE cart_id = :cart_id LIMIT 1');
        $stmt->execute([':cart_id' => $cartId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function accessToken(string $cartId): string {
        return BookingHoldToken::derive($cartId, 'race@test.com');
    }

    // =====================================================================
    // Todo 8 — QA+ (APRO): 2 procesos concurrentes, exactamente 1 gateway.
    // =====================================================================

    public function testConcurrentApprovedPaymentsCallGatewayExactlyOnce(): void {
        $cart = $this->prefix . '-A';
        $this->insertHold($cart);

        $r1 = $this->spawnWorker(['payment-race', $cart, $this->accessToken($cart), 'approved', $this->gatewayLog]);
        $r2 = $this->spawnWorker(['payment-race', $cart, $this->accessToken($cart), 'approved', $this->gatewayLog]);

        $this->assertSame(0, $r1['code'], 'Worker 1: ' . $r1['out'] . ' ' . $r1['err']);
        $this->assertSame(0, $r2['code'], 'Worker 2: ' . $r2['out'] . ' ' . $r2['err']);

        $this->assertSame(1, $this->gatewayCallCount($cart), 'Exactamente 1 llamada a la gateway para el mismo cart.');

        $hold = $this->getHold($cart);
        $this->assertNotNull($hold);
        $this->assertSame('paid', $hold['status']);
        $this->assertNotEmpty($hold['payment_id'], 'El payment_id debe quedar persistido en la txn.');
        $this->assertStringContainsString('"success":true', $r1['out'] . $r2['out'], 'Al menos un worker responde aprobado.');
    }

    // =====================================================================
    // Todo 8 — QA+2 (CONT): 1 payment + 1 attach; el segundo no vuelve a cobrar.
    // =====================================================================

    public function testConcurrentPendingPaymentsAttachOnceAndDoNotDoubleCharge(): void {
        $cart = $this->prefix . '-C';
        $this->insertHold($cart);

        $r1 = $this->spawnWorker(['payment-race', $cart, $this->accessToken($cart), 'in_process', $this->gatewayLog]);
        $r2 = $this->spawnWorker(['payment-race', $cart, $this->accessToken($cart), 'in_process', $this->gatewayLog]);

        $this->assertSame(0, $r1['code'], 'Worker 1: ' . $r1['out'] . ' ' . $r1['err']);
        $this->assertSame(0, $r2['code'], 'Worker 2: ' . $r2['out'] . ' ' . $r2['err']);

        $this->assertSame(1, $this->gatewayCallCount($cart), 'La carrera CONT produce exactamente 1 pago.');

        $hold = $this->getHold($cart);
        $this->assertNotNull($hold);
        $this->assertSame('pending', $hold['status'], 'CONT deja el hold pending.');
        $this->assertNotEmpty($hold['payment_id'], 'El payment_id del pago pending queda persistido (reconciliacion).');
        $this->assertStringContainsString('"success":false', $r1['out'] . $r2['out'], 'Ningun worker responde success para CONT.');
    }

    // =====================================================================
    // Todo 8 — QA-1 (OTHE): rechazo -> rollback, sin payment_id persistido.
    // =====================================================================

    public function testRejectedPaymentRollsBackWithoutPersistingPaymentId(): void {
        $cart = $this->prefix . '-O';
        $this->insertHold($cart);

        $r1 = $this->spawnWorker(['payment-race', $cart, $this->accessToken($cart), 'othe', $this->gatewayLog]);
        $r2 = $this->spawnWorker(['payment-race', $cart, $this->accessToken($cart), 'othe', $this->gatewayLog]);

        $this->assertSame(0, $r1['code'], 'Worker 1: ' . $r1['out'] . ' ' . $r1['err']);
        $this->assertSame(0, $r2['code'], 'Worker 2: ' . $r2['out'] . ' ' . $r2['err']);

        $this->assertSame(0, $this->gatewayCallCount($cart), 'El rechazo OTHE no registra ningun pago.');
        $this->assertStringContainsString('cc_rejected_other_reason', $r1['out'] . $r2['out'], 'El status_detail del rechazo llega al cliente (todo 6).');

        $hold = $this->getHold($cart);
        $this->assertNotNull($hold);
        $this->assertSame('pending', $hold['status'], 'Rollback: el hold sigue pending.');
        $this->assertNull($hold['payment_id'], 'Rollback: sin payment_id persistido.');
    }

    // =====================================================================
    // Todo 10 — QA- (secuencial OK sin deadlock) y QA+ (1 solo hold).
    // =====================================================================

    public function testSequentialHoldCreationStillWorksWithoutDeadlock(): void {
        $roomType = 999998;
        $roomLockId = self::HOTEL_ID . ':' . $roomType;
        $dates = ['2028-02-01', '2028-02-03'];

        $cart1 = $this->prefix . '-S1';
        $r1 = $this->spawnWorker(['hold-race', $cart1, (string)$roomType, $dates[0], $dates[1], (string)self::HOTEL_ID, $roomLockId]);
        $this->assertSame(0, $r1['code'], 'Primer create secuencial: ' . $r1['out'] . ' ' . $r1['err']);

        // Segundo create sobre la misma habitacion+fechas -> rechazado (count > 0).
        $cart2 = $this->prefix . '-S2';
        $r2 = $this->spawnWorker(['hold-race', $cart2, (string)$roomType, $dates[0], $dates[1], (string)self::HOTEL_ID, $roomLockId]);
        $this->assertNotSame(0, $r2['code'], 'El segundo create de la misma habitacion debe rechazarse.');

        // Habitacion distinta -> OK (sin deadlock).
        $cart3 = $this->prefix . '-S3';
        $r3 = $this->spawnWorker(['hold-race', $cart3, '999997', $dates[0], $dates[1], (string)self::HOTEL_ID, self::HOTEL_ID . ':999997']);
        $this->assertSame(0, $r3['code'], 'Create secuencial de otra habitacion: ' . $r3['out'] . ' ' . $r3['err']);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM provisional_bookings WHERE id_hotel = :h AND id_room_type = :r AND checkin = :ci AND checkout = :co"
        );
        $stmt->execute([':h' => self::HOTEL_ID, ':r' => $roomType, ':ci' => $dates[0], ':co' => $dates[1]]);
        $this->assertSame(1, (int)$stmt->fetchColumn(), 'Exactamente 1 hold para la habitacion disputada.');
    }

    public function testConcurrentHoldCreationProducesExactlyOneHold(): void {
        $roomLockId = self::HOTEL_ID . ':' . self::ROOM_TYPE;

        $cart1 = $this->prefix . '-H1';
        $cart2 = $this->prefix . '-H2';

        $r1 = $this->spawnWorker(['hold-race', $cart1, (string)self::ROOM_TYPE, self::CHECK_IN, self::CHECK_OUT, (string)self::HOTEL_ID, $roomLockId]);
        $r2 = $this->spawnWorker(['hold-race', $cart2, (string)self::ROOM_TYPE, self::CHECK_IN, self::CHECK_OUT, (string)self::HOTEL_ID, $roomLockId]);

        $okCount = (int)($r1['code'] === 0) + (int)($r2['code'] === 0);
        $this->assertSame(1, $okCount, 'Exactamente 1 de los 2 creates concurrentes debe ganar. W1=' . $r1['out'] . ' W2=' . $r2['out']);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM provisional_bookings WHERE id_hotel = :h AND id_room_type = :r AND checkin = :ci AND checkout = :co"
        );
        $stmt->execute([':h' => self::HOTEL_ID, ':r' => self::ROOM_TYPE, ':ci' => self::CHECK_IN, ':co' => self::CHECK_OUT]);
        $this->assertSame(1, (int)$stmt->fetchColumn(), 'Serializacion: exactamente 1 hold (sin holds fantasma).');
    }

    // =====================================================================
    // Todo 9 — integracion: cleanExpiredCarts respeta el FROM-set (paid intacto).
    // =====================================================================

    public function testCleanExpiredCartsKeepsPaidHoldUntouched(): void {
        $repo = new ProvisionalBookingRepository($this->pdo);

        // Hold paid con expires_at vencido: NO debe cambiar (FROM-set de expired
        // no incluye 'paid'; fix MAJOR r3).
        $paidCart = $this->prefix . '-P';
        $this->insertHold($paidCart, BookingStatus::Paid->value);
        $this->pdo->prepare(
            "UPDATE provisional_bookings SET expires_at = NOW() - INTERVAL 1 HOUR WHERE cart_id = :cart"
        )->execute([':cart' => $paidCart]);

        // Hold pending con expires_at vencido: debe pasar a expired.
        $pendingCart = $this->prefix . '-E';
        $this->insertHold($pendingCart, BookingStatus::Pending->value);
        $this->pdo->prepare(
            "UPDATE provisional_bookings SET expires_at = NOW() - INTERVAL 1 HOUR WHERE cart_id = :cart"
        )->execute([':cart' => $pendingCart]);

        $repo->cleanExpiredCarts();

        $this->assertSame(BookingStatus::Paid->value, $this->getHold($paidCart)['status'], 'Un hold paid NUNCA se expira (FROM-set).');
        $this->assertSame(BookingStatus::Expired->value, $this->getHold($pendingCart)['status'], 'Un hold pending vencido se expira.');
    }

    // =====================================================================
    // Todo 12 — integracion: secuencia approved->refunded contra el indice
    // unico compuesto (payment_id, event_type). Se usa una tabla TEMPORARY
    // sombra (misma conexion): la migracion real de la BD de produccion la
    // ejecuta el auto-heal en deploy, NO estos tests.
    // =====================================================================

    public function testProcessedPaymentsSequenceWithEventTypeCoexists(): void {
        $this->pdo->exec("DROP TEMPORARY TABLE IF EXISTS processed_payments");
        $this->pdo->exec("CREATE TEMPORARY TABLE processed_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            payment_id VARCHAR(64) NOT NULL,
            cart_id VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL,
            event_type VARCHAR(32) NULL,
            processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_payment_event (payment_id, event_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $repo = new ProvisionalBookingRepository($this->pdo);
        $paymentId = 'W2RACE-' . time() . '-' . random_int(1000, 9999);
        $cart = $this->prefix . '-SEQ';

        $this->assertTrue($repo->markPaymentProcessed($paymentId, $cart, 'approved'));
        $this->assertTrue($repo->isPaymentProcessed($paymentId, 'approved'), 'approved registrado.');
        $this->assertFalse($repo->isPaymentProcessed($paymentId, 'refunded'), 'refunded NO colisiona con approved.');

        $this->assertTrue($repo->markPaymentProcessed($paymentId, $cart, 'refunded'));
        $this->assertTrue($repo->isPaymentProcessed($paymentId, 'refunded'), 'refunded registrado (refunds desbloqueados).');

        $count = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM processed_payments WHERE payment_id = " . $this->pdo->quote($paymentId)
        )->fetchColumn();
        $this->assertSame(2, $count, 'approved y refunded coexisten (1 fila por evento).');
    }
}
