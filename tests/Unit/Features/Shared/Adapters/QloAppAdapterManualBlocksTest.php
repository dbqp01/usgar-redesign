<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Shared\Adapters;

use App\Core\Config;
use App\Features\Shared\Adapters\QloAppAdapter;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Regresion: los bloqueos importados a manual_blocks (POST /api/panel/import)
 * deben descontar la disponibilidad WEB. Contrato en docs/API.md: "Cada fila
 * valida crea un bloqueo en manual_blocks (la habitacion deja de venderse en
 * la web)". Antes de este test el adapter solo contaba qlo_htl_booking_detail,
 * provisional_bookings y qlo_htl_room_disable_dates: una reserva importada
 * dejaba la habitacion vendible en el widget (riesgo de overbooking).
 *
 * El import puede repetirse o traer filas solapadas del mismo cuarto: cuenta
 * UNA vez por habitacion fisica. Si el cuarto ya esta en disable_dates, no se
 * suma dos veces. Sin tabla manual_blocks la web sigue vendiendo (fail-open,
 * no 503): el panel la auto-crea al usarse.
 */
final class QloAppAdapterManualBlocksTest extends TestCase
{
    private PDO $pdo;

    public function testAvailableRoomsCountsManualBlockOncePerRoom(): void
    {
        $this->seed();
        // Mismo cuarto (id 3) dos veces con fechas solapadas: re-import idempotente.
        $this->manualBlock(3, '2026-09-02', '2026-09-04');
        $this->manualBlock(3, '2026-09-02', '2026-09-03');

        $result = (new QloAppAdapter($this->pdo))->getAvailableRooms('2026-09-01', '2026-09-05');

        self::assertCount(1, $result);
        // 3 habitaciones - 1 cuarto bloqueado = 2.
        self::assertSame(2, $result[0]['available_qty']);
    }

    public function testAvailableRoomsDoesNotDoubleCountManualBlockAndDisableDate(): void
    {
        $this->seed();
        $this->manualBlock(3, '2026-09-02', '2026-09-04');
        $this->disableDate(3, '2026-09-01', '2026-09-05');

        $result = (new QloAppAdapter($this->pdo))->getAvailableRooms('2026-09-01', '2026-09-05');

        // El cuarto 3 esta bloqueado por ambas fuentes: sigue siendo 1 unidad.
        self::assertSame(2, $result[0]['available_qty']);
    }

    public function testAvailableRoomsKeepsSellingWhenManualBlocksTableIsMissing(): void
    {
        $this->seed(withManualBlocksTable: false);

        $result = (new QloAppAdapter($this->pdo))->getAvailableRooms('2026-09-01', '2026-09-05');

        // Fail-open: un esquema sin panel no puede tumbar la venta web.
        self::assertSame(3, $result[0]['available_qty']);
    }

    public function testCalendarCountsManualBlocksPerNight(): void
    {
        $this->seed();
        $this->manualBlock(3, '2026-09-02', '2026-09-04');
        $this->manualBlock(3, '2026-09-02', '2026-09-04');

        $result = (new QloAppAdapter($this->pdo))->getAvailabilityCalendar('2026-09-01', '2026-09-04');

        // Noches 2 y 3 ocupadas; el dia de checkout (4) se libera.
        self::assertSame(3, $result['2026-09-01'][1]);
        self::assertSame(2, $result['2026-09-02'][1]);
        self::assertSame(2, $result['2026-09-03'][1]);
        self::assertSame(3, $result['2026-09-04'][1]);
    }

    public function testCalendarKeepsManualBlockOutsideTheDisabledWindow(): void
    {
        $this->seed();
        // El disable cubre otra parte del rango: el bloqueo importado del
        // dia 3 no puede desaparecer del calendario.
        $this->disableDate(3, '2026-09-01', '2026-09-02');
        $this->manualBlock(3, '2026-09-03', '2026-09-04');

        $result = (new QloAppAdapter($this->pdo))->getAvailabilityCalendar('2026-09-01', '2026-09-04');

        self::assertSame(2, $result['2026-09-01'][1]);
        self::assertSame(3, $result['2026-09-02'][1]);
        self::assertSame(2, $result['2026-09-03'][1]);
        self::assertSame(3, $result['2026-09-04'][1]);
    }

    public function testManualBlockAndPmsBookingForSameRoomCountOnce(): void
    {
        $this->seed();
        $this->manualBlock(3, '2026-09-02', '2026-09-04');
        $this->pdo->exec("INSERT INTO qlo_htl_booking_detail VALUES (3,10,1,99,'2026-09-02 12:00:00','2026-09-04 10:30:00',0,0)");
        $adapter = new QloAppAdapter($this->pdo);
        self::assertSame(2, $adapter->getAvailableRooms('2026-09-01', '2026-09-05')[0]['available_qty']);
        self::assertSame(2, $adapter->getAvailabilityCalendar('2026-09-01', '2026-09-04')['2026-09-02'][1]);
    }

    public function testManualBlockStillCountsWhenBookingIsExcludedFromMainCount(): void
    {
        $this->seed();
        $this->manualBlock(3, '2026-09-02', '2026-09-04');
        $this->pdo->exec("INSERT INTO qlo_htl_booking_detail VALUES (3,10,1,99,'2026-09-02 12:00:00','2026-09-04 10:30:00',0,0)");
        $this->pdo->exec("INSERT INTO provisional_bookings (cart_id,id_hotel,id_room_type,checkin,checkout,status,expires_at) VALUES ('99',1,1,'2026-09-02','2026-09-04','paid','2099-01-01')");
        $this->pdo->exec("INSERT INTO qlo_orders (source,current_state) VALUES ('99',2)");
        $result = (new QloAppAdapter($this->pdo))->getAvailableRooms('2026-09-01', '2026-09-05');
        self::assertSame(2, $result[0]['available_qty']);
    }

    public function testCalendarCountsBlockStartingOnLastRequestedDay(): void
    {
        $this->seed();
        $this->manualBlock(3, '2026-09-04', '2026-09-05');
        $result = (new QloAppAdapter($this->pdo))->getAvailabilityCalendar('2026-09-01', '2026-09-04');
        self::assertSame(2, $result['2026-09-04'][1]);
    }

    public function testCalendarCountsDisableStartingOnLastRequestedDay(): void
    {
        $this->seed();
        $this->disableDate(3, '2026-09-04', '2026-09-05');
        $result = (new QloAppAdapter($this->pdo))->getAvailabilityCalendar('2026-09-01', '2026-09-04');
        self::assertSame(2, $result['2026-09-04'][1]);
    }

    public function testCalendarCountsHoldStartingOnLastRequestedDay(): void
    {
        $this->seed();
        $this->pdo->exec("INSERT INTO provisional_bookings (cart_id, id_hotel, id_room_type, checkin, checkout, status, expires_at) VALUES ('test',1,1,'2026-09-04','2026-09-05','pending','2099-01-01')");
        $result = (new QloAppAdapter($this->pdo))->getAvailabilityCalendar('2026-09-01', '2026-09-04');
        self::assertSame(2, $result['2026-09-04'][1]);
    }

    public function testAvailabilityFailsClosedWhenManualBlocksCannotBeRead(): void
    {
        $this->seed();
        $this->pdo->exec('DROP TABLE manual_blocks');
        $this->pdo->exec('CREATE TABLE manual_blocks (id INTEGER PRIMARY KEY)');
        $this->expectException(\App\Features\Shared\PmsUnavailableException::class);
        (new QloAppAdapter($this->pdo))->getAvailableRooms('2026-09-01', '2026-09-05');
    }

    public function testOrphanedManualBlockFailsClosedForAvailability(): void
    {
        $this->seed();
        $this->manualBlock(99, '2026-09-02', '2026-09-04');
        $this->expectException(\App\Features\Shared\PmsUnavailableException::class);
        (new QloAppAdapter($this->pdo))->getAvailableRooms('2026-09-01', '2026-09-05');
    }

    public function testOrphanedManualBlockFailsClosedForCalendar(): void
    {
        $this->seed();
        $this->manualBlock(99, '2026-09-02', '2026-09-04');
        $this->expectException(\App\Features\Shared\PmsUnavailableException::class);
        (new QloAppAdapter($this->pdo))->getAvailabilityCalendar('2026-09-01', '2026-09-05');
    }

    public function testManualBlockWithoutRoomIdFailsClosed(): void
    {
        $this->seed();
        $this->pdo->exec("INSERT INTO manual_blocks (id_hotel, room_id, room_num, checkin, checkout, status) VALUES (1,NULL,'103','2026-09-02','2026-09-04','confirmed')");
        $this->expectException(\App\Features\Shared\PmsUnavailableException::class);
        (new QloAppAdapter($this->pdo))->getAvailableRooms('2026-09-01', '2026-09-05');
    }

    public function testBookingWithOutdatedProductDoesNotHideManualBlock(): void
    {
        $this->seed();
        $this->manualBlock(3, '2026-09-02', '2026-09-04');
        $this->pdo->exec("INSERT INTO qlo_htl_booking_detail VALUES (3,11,1,99,'2026-09-02 12:00:00','2026-09-04 10:30:00',0,0)");
        $result = (new QloAppAdapter($this->pdo))->getAvailableRooms('2026-09-01', '2026-09-05');
        self::assertSame(2, $result[0]['available_qty']);
    }

    private function seed(bool $withManualBlocksTable = true): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite no disponible en este entorno.');
        }
        Config::set('QLOAPPS_DEFAULT_LANG_ID', '1');

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // NOW() existe en MySQL; SQLite lo necesita registrado para el
        // subquery de holds web de getAvailableRooms().
        $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));

        $pdo->exec('CREATE TABLE qlo_product (id_product INTEGER PRIMARY KEY, price REAL, active INTEGER)');
        $pdo->exec('CREATE TABLE qlo_product_lang (id_product INTEGER, id_lang INTEGER, name TEXT)');
        $pdo->exec('CREATE TABLE qlo_htl_room_type (id INTEGER PRIMARY KEY, id_product INTEGER, id_hotel INTEGER, max_guests INTEGER)');
        $pdo->exec('CREATE TABLE qlo_htl_room_information (id INTEGER PRIMARY KEY, id_product INTEGER, id_hotel INTEGER, room_num TEXT)');
        $pdo->exec('CREATE TABLE qlo_htl_booking_detail (id_room INTEGER, id_product INTEGER, id_hotel INTEGER, id_order INTEGER, date_from TEXT, date_to TEXT, is_cancelled INTEGER, is_refunded INTEGER)');
        $pdo->exec('CREATE TABLE provisional_bookings (cart_id TEXT, id_hotel INTEGER, id_room_type INTEGER, checkin TEXT, checkout TEXT, status TEXT, expires_at TEXT)');
        $pdo->exec('CREATE TABLE provisional_booking_rooms (cart_id TEXT, id_room_type INTEGER, quantity INTEGER)');
        $pdo->exec('CREATE TABLE qlo_orders (source TEXT, current_state INTEGER)');
        $pdo->exec('CREATE TABLE qlo_htl_room_disable_dates (id_room_type INTEGER, id_room INTEGER, date_from TEXT, date_to TEXT)');
        $pdo->exec('CREATE TABLE qlo_htl_room_type_feature_pricing (id_feature_price INTEGER, id_product INTEGER, impact_way TEXT, impact_type TEXT, impact_value REAL, id_cart INTEGER, id_guest INTEGER, id_room INTEGER, active INTEGER)');
        $pdo->exec('CREATE TABLE qlo_htl_room_type_feature_pricing_restriction (id_feature_price INTEGER, date_selection_type TEXT, special_days TEXT, date_from TEXT, date_to TEXT)');
        if ($withManualBlocksTable) {
            $pdo->exec('CREATE TABLE manual_blocks (id INTEGER PRIMARY KEY AUTOINCREMENT, id_hotel INTEGER, room_id INTEGER, room_num TEXT, checkin TEXT, checkout TEXT, guest_name TEXT, channel TEXT, status TEXT, price REAL, notes TEXT)');
        }

        $pdo->exec("INSERT INTO qlo_product VALUES (10, 100.0, 1)");
        $pdo->exec("INSERT INTO qlo_product_lang VALUES (10, 1, 'Doble Superior')");
        $pdo->exec("INSERT INTO qlo_htl_room_type VALUES (1, 10, 1, 3)");
        $pdo->exec("INSERT INTO qlo_htl_room_information VALUES (1, 10, 1, '101'), (2, 10, 1, '102'), (3, 10, 1, '103')");

        $this->pdo = $pdo;
    }

    private function manualBlock(int $roomId, string $checkin, string $checkout): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO manual_blocks (id_hotel, room_id, room_num, checkin, checkout, guest_name, channel, status)
             VALUES (1, :room_id, '103', :checkin, :checkout, 'Huesped', 'ota', 'confirmed')"
        );
        $stmt->execute([':room_id' => $roomId, ':checkin' => $checkin, ':checkout' => $checkout]);
    }

    private function disableDate(int $roomId, string $from, string $to): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO qlo_htl_room_disable_dates (id_room_type, id_room, date_from, date_to) VALUES (1, :room_id, :from, :to)'
        );
        $stmt->execute([':room_id' => $roomId, ':from' => $from, ':to' => $to]);
    }
}
