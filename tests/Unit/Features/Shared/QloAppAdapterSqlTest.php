<?php
declare(strict_types=1);

namespace Tests\Unit\Features\Shared;

use App\Features\Shared\Adapters\QloAppAdapter;
use PHPUnit\Framework\TestCase;
use PDO;
use PDOStatement;

/**
 * verifica que las consultas SQL de confirmOrder() se
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

    /**
     * PrestaShop calcula "Total pagado" y "Monto adeudado" del pedido sumando
     * qlo_order_payment (NO orders.total_paid). Una orden creada solo por SQL
     * queda "Total pagado S/. 0 / adeudado S/. <total>" aunque current_state = 2
     * (Pago completo recibido) — el hotel ve una reserva pagada como pendiente.
     */
    public function testConfirmOrderRecordsPaymentTransaction(): void {
        $pdo = new FakePdoForSqlTest();
        $pdo->fetchQueue = [[
            'id_hotel' => '1', 'id_room_type' => '3', 'checkin' => '2026-09-01',
            'checkout' => '2026-09-03', 'guest_data' => '{"guests": 2}',
        ], false, false, false];
        $pdo->fetchColumnQueue = [0, false];

        $adapter = new QloAppAdapter($pdo);
        self::assertSame('1', $adapter->confirmOrder('USGAR-pay-cart', 380.0, 'Ana Perez', 'ana@example.com'));

        $paymentInserts = array_values(array_filter(
            $pdo->preparedQueries,
            static fn(string $q): bool => str_contains($q, 'INSERT INTO qlo_order_payment')
        ));
        self::assertCount(1, $paymentInserts);

        // Params: [referencia de la orden, monto cobrado, metodo de pago].
        $paymentParams = null;
        foreach ($pdo->executedParams as $params) {
            if (is_array($params) && count($params) === 3 && ($params[2] ?? null) === 'Mercado Pago (Online)') {
                $paymentParams = $params;
                break;
            }
        }
        self::assertNotNull($paymentParams, 'No se registro la transaccion de pago en qlo_order_payment');
        self::assertMatchesRegularExpression('/^[A-F0-9]{9}$/', (string) $paymentParams[0]);
        self::assertEqualsWithDelta(380.0, (float) $paymentParams[1], 0.001);

        // total_paid_real se escribe en la orden: PrestaShop lo recalcula solo
        // cuando se anaden pagos desde el back-office.
        self::assertStringContainsString('total_paid_real', implode("\n", $pdo->preparedQueries));
    }

    /**
     * El POST /api/bookings del webservice deja un hold (reserva pendiente) en el
     * PMS. Cuando el pago se confirma, esa orden pagada lo sustituye: si no se
     * libera, la reserva queda duplicada en el PMS y la habitacion bloqueada dos
     * veces. Ademas la reserva pagada hereda la habitacion fisica (id_room) que
     * QloApps asigno al hold: sin ella QloApps no la bloquea en su calendario
     * (su disponibilidad une booking_detail con room_information por id_room).
     */
    public function testConfirmOrderSupersedesWebserviceHold(): void {
        $pdo = new FakePdoForSqlTest();
        $pdo->fetchQueue = [[
            'id_hotel' => '1', 'id_room_type' => '3', 'checkin' => '2026-09-01',
            'checkout' => '2026-09-03',
            'guest_data' => '{"guests": 2, "phone": "123456789"}',
            'room_data' => json_encode([
                ['id_room_type' => 3, 'id_product' => 2, 'room_name' => 'Doble Superior', 'guests' => 2, 'room_total' => 195.0],
            ], JSON_THROW_ON_ERROR),
        ], false, false, false];
        // [0] = cliente inexistente, [1] = PS_OS_CANCELED de la tienda.
        $pdo->fetchColumnQueue = [0, 4];
        // Hold del webservice: QloApps asigno la habitacion fisica 3.
        $pdo->fetchAllQueue = [['id_product' => 2, 'id_room' => 3]];

        $adapter = new QloAppAdapter($pdo);
        self::assertSame('1', $adapter->confirmOrder('286', 380.0, 'Ana Perez', 'ana@example.com'));

        $queries = implode("\n", $pdo->preparedQueries);
        $params = array_map(
            static fn($p): string => is_array($p) ? implode('|', array_map('strval', array_values($p))) : '',
            $pdo->executedParams
        );

        // La reserva pagada hereda la habitacion fisica del hold (id_room = 3).
        self::assertStringContainsString('?, ?, 1, ?, 3, ?, ?', $queries);

        // El hold queda liberado: QloApps libera la habitacion con is_refunded = 1
        // y marca la reserva cancelada con is_cancelled = 1.
        self::assertStringContainsString('UPDATE qlo_htl_booking_detail', $queries);
        self::assertStringContainsString('SET is_refunded = 1, is_cancelled = 1', $queries);
        self::assertStringContainsString('WHERE id_order = ? AND is_refunded = 0 AND is_cancelled = 0', $queries);

        // Su orden pasa a "Cancelado" (PS_OS_CANCELED = 4 en la tienda).
        self::assertStringContainsString('UPDATE qlo_orders SET current_state = ? WHERE id_order = ? AND current_state = 1', $queries);
        self::assertContains('4|286', $params);
    }

    /**
     * Una reserva local caducada (expirada/cancelada/fallida) deja su hold en
     * QloApps bloqueando la habitacion: sin liberarlo, la habitacion no vuelve
     * a estar disponible hasta que el Cleaner del PMS purgue el carrito.
     */
    public function testReleaseStaleWebserviceHoldsFreesThePmsRooms(): void {
        $pdo = new FakePdoForSqlTest();
        // Holds caducados que siguen bloqueando en el PMS.
        $pdo->fetchAllQueue = ['284', '285'];
        // PS_OS_CANCELED de la tienda (una lectura por hold liberado).
        $pdo->fetchColumnQueue = [4, 4];

        $adapter = new QloAppAdapter($pdo);
        self::assertSame(2, $adapter->releaseStaleWebserviceHolds());

        $queries = implode("\n", $pdo->preparedQueries);

        // Solo reservas locales ya no activas, y solo las que siguen bloqueando.
        self::assertStringContainsString("WHERE pb.status IN ('expired','cancelled','failed')", $queries);
        self::assertStringContainsString('bd.is_refunded = 0 AND bd.is_cancelled = 0', $queries);

        // Libera la habitacion (is_refunded) y cancela la orden del hold.
        self::assertStringContainsString('SET is_refunded = 1, is_cancelled = 1', $queries);
        self::assertStringContainsString('UPDATE qlo_orders SET current_state = ? WHERE id_order = ? AND current_state = 1', $queries);
    }

    public function testMultiRoomDetailsAllocateExactGatewayCurrencyTotal(): void {        $pdo = new FakePdoForSqlTest();
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
    /** @var list<list<array<string,mixed>>> */
    public array $fetchAllQueue = [];
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

    /** @return list<array<string,mixed>> */
    public function nextFetchAllResult(): array {
        return $this->fetchAllQueue;
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

    /** @return list<array<string,mixed>> */
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        return $this->pdo->nextFetchAllResult();
    }

    public function rowCount(): int {
        return 1;
    }
}
