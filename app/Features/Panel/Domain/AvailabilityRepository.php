<?php
declare(strict_types=1);

namespace App\Features\Panel\Domain;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use PDO;
use PDOException;

/**
 * Datos del panel de disponibilidad del dueno.
 *
 * SQL READ-ONLY contra tablas qlo_* (mismo patron que QloAppAdapter) + tabla
 * propia manual_blocks (bloqueos importados por el dueno).
 *
 * Schema verificado contra la BD real (2026-08-11):
 *  - qlo_htl_room_information: id (PK), id_product, id_hotel, room_num,
 *    id_status, floor. El room type se resuelve por id_product (rt.id).
 *  - qlo_htl_booking_detail: id_room, room_num, id_customer,
 *    total_paid_amount, date_from/date_to, is_cancelled, is_refunded.
 *  - qlo_htl_room_disable_dates: id_room, date_from, date_to (maint).
 * Fail-safe: cualquier columna/tabla ausente degrada sin romper.
 */
class AvailabilityRepository {
    private ?PDO $pdo;
    private bool $schemaChecked = false;
    /** @var array<string, bool> Cached per repository instance. */
    private array $tablePresence = [];

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    public function ensureTablesExist(): void {
        if (!$this->pdo || $this->schemaChecked) {
            return;
        }
        // MySQL DDL implicitly commits. Never migrate while a booking
        // transaction holds the room serialization lock; callers must prepare
        // the panel schema before opening their business transaction.
        if ($this->pdo->inTransaction()) {
            Logger::error('AvailabilityRepository::ensureTablesExist: migracion diferida porque hay una transaccion activa.');
            return;
        }
        // Hot path (panel month grid / room wizard): probe before DDL so the
        // steady-state request never issues CREATE TABLE statements.
        try {
            $probe = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME IN ('manual_blocks', 'provisional_booking_rooms')"
            );
            $probe->execute();
            if ((int)$probe->fetchColumn() === 2) {
                $this->schemaChecked = true;
                return;
            }
        } catch (PDOException $e) {
            Logger::error('AvailabilityRepository::ensureTablesExist probe Error: ' . $e->getMessage());
        }
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS manual_blocks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    id_hotel INT DEFAULT 1,
                    room_id INT NULL,
                    room_num VARCHAR(32) NULL,
                    checkin DATE NOT NULL,
                    checkout DATE NOT NULL,
                    guest_name VARCHAR(128) NOT NULL,
                    channel VARCHAR(16) NOT NULL DEFAULT 'walkin',
                    status VARCHAR(16) NOT NULL DEFAULT 'confirmed',
                    price DECIMAL(10,2) NULL,
                    notes VARCHAR(255) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_manual_blocks_room_dates (room_id, checkin, checkout)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS provisional_booking_rooms (
                    cart_id VARCHAR(64) NOT NULL,
                    id_room_type INT NOT NULL,
                    quantity INT NOT NULL DEFAULT 1,
                    PRIMARY KEY (cart_id, id_room_type),
                    INDEX idx_room_type (id_room_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            $this->schemaChecked = true;
        } catch (PDOException $e) {
            Logger::error('AvailabilityRepository::ensureTablesExist Error: ' . $e->getMessage());
        }
    }

    /**
     * Grid completo del mes: habitaciones + reservas + holds + bloqueos + mantenimiento.
     *
     * @return array{month: string, today: string, rooms: list<array<string,mixed>>, bookings: list<array<string,mixed>>}
     */
    public function getMonth(string $month, int $hotelId = 1): array {
        if (!$this->pdo) {
            return ['month' => $month, 'today' => date('Y-m-d'), 'rooms' => [], 'bookings' => []];
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return ['month' => $month, 'today' => date('Y-m-d'), 'rooms' => [], 'bookings' => []];
        }

        [$y, $m] = array_map('intval', explode('-', $month));
        $first = sprintf('%04d-%02d-01', $y, $m);
        // All availability queries use an exclusive checkout boundary. The
        // first day of the following month keeps bookings beginning on the
        // final day visible in the monthly grid.
        $monthEnd = (new \DateTimeImmutable($first))->modify('+1 month')->format('Y-m-d');
        $monthStart = $first;

        try {
            $types = $this->fetchRoomTypes($hotelId);
            // Reuse the type rows already loaded for this response.  The
            // previous implementation made fetchRooms() query the same
            // qlo_htl_room_type/product_lang join a second time on every
            // month request.
            $rooms = $this->fetchRooms($hotelId, $types);
            $bookings = $this->fetchQloBookings($monthStart, $monthEnd, $types, $hotelId);
            $bookings = array_merge($bookings, $this->fetchHolds($monthStart, $monthEnd, $hotelId, $types));
            $bookings = array_merge($bookings, $this->fetchManualBlocks($monthStart, $monthEnd, $hotelId));
            $bookings = array_merge($bookings, $this->fetchDisableDates($monthStart, $monthEnd, $hotelId));

            return [
                'month'    => $month,
                'today'    => date('Y-m-d'),
                'rooms'    => $rooms,
                'bookings' => $bookings,
            ];
        } catch (PDOException $e) {
            Logger::error('AvailabilityRepository::getMonth Error: ' . $e->getMessage());
            return ['month' => $month, 'today' => date('Y-m-d'), 'rooms' => [], 'bookings' => []];
        }
    }

    /**
     * @return list<array{id_room_type: int, id_product: int, name: string}>
     */
    private function fetchRoomTypes(int $hotelId): array {
        $stmt = $this->pdo->prepare("
            SELECT rt.id AS id_room_type, rt.id_product, pl.name
            FROM qlo_htl_room_type rt
            INNER JOIN qlo_product_lang pl
                ON pl.id_product = rt.id_product AND pl.id_lang = 1
            WHERE rt.id_hotel = :id_hotel
        ");
        $stmt->execute([':id_hotel' => $hotelId]);
        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Todas las habitaciones fisicas del hotel (sin filtro de id_status: el
     * panel es una vista; la venta real la controla el PMS).
     *
     * @param list<array<string,mixed>>|null $types Types loaded by the caller.
     *        When omitted, fetches them for the private helper's standalone use.
     * @return list<array<string,mixed>>
     */
    private function fetchRooms(int $hotelId, ?array $types = null): array {
        $stmt = $this->pdo->prepare("
            SELECT ri.id AS room_id, ri.room_num, ri.floor, ri.id_product, ri.id_status,
                   rt.id AS id_room_type, p.price
            FROM qlo_htl_room_information ri
            INNER JOIN qlo_htl_room_type rt ON rt.id_product = ri.id_product AND rt.id_hotel = ri.id_hotel
            INNER JOIN qlo_product p ON p.id_product = ri.id_product
            WHERE ri.id_hotel = :id_hotel
            ORDER BY rt.id, CAST(ri.room_num AS UNSIGNED), ri.room_num
        ");
        $stmt->execute([':id_hotel' => $hotelId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $typeNames = [];
        foreach ($types ?? $this->fetchRoomTypes($hotelId) as $t) {
            $typeNames[(int)$t['id_room_type']] = (string)$t['name'];
        }

        $rooms = [];
        foreach ($rows as $r) {
            $rooms[] = [
                'room_id'      => (int)$r['room_id'],
                'room_num'     => (string)$r['room_num'],
                'floor'        => $r['floor'] !== null ? (string)$r['floor'] : null,
                'id_room_type' => (int)$r['id_room_type'],
                'id_product'   => (int)$r['id_product'],
                'status'       => (int)$r['id_status'],
                'type'         => $typeNames[(int)$r['id_room_type']] ?? 'Habitacion',
                'price'        => (float)$r['price'],
            ];
        }
        return $rooms;
    }

    /**
     * Reservas confirmadas de QloApps en el rango (rama rica con id_room;
     * degrada a nivel de room type si el join de cliente falla).
     *
     * @param list<array<string,mixed>> $types
     * @return list<array<string,mixed>>
     */
    private function fetchQloBookings(string $from, string $to, array $types, int $hotelId): array {
        try {
            return $this->fetchQloBookingsRich($from, $to, $hotelId);
        } catch (PDOException $e) {
            Logger::error('AvailabilityRepository: rama rica fallo, degradando: ' . $e->getMessage());
        }
        return $this->fetchQloBookingsSimple($from, $to, $types, $hotelId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchQloBookingsRich(string $from, string $to, int $hotelId): array {
        $stmt = $this->pdo->prepare("
            SELECT bd.id_room, bd.room_num, bd.id_product, rt.id AS id_room_type,
                   bd.date_from, bd.date_to, bd.total_paid_amount,
                   c.firstname, c.lastname, qo.module
            FROM qlo_htl_booking_detail bd
            LEFT JOIN qlo_customer c ON c.id_customer = bd.id_customer
            LEFT JOIN qlo_orders qo ON qo.id_order = bd.id_order
            LEFT JOIN qlo_htl_room_type rt
              ON rt.id_product = bd.id_product AND rt.id_hotel = bd.id_hotel
            WHERE bd.is_cancelled = 0 AND bd.is_refunded = 0
              AND bd.date_from < :range_to
              AND bd.date_to > :range_from
              AND bd.id_hotel = :id_hotel
        ");
        $stmt->execute([
            ':range_from' => $from . ' 00:00:00',
            ':range_to'   => $to . ' 00:00:00',
            ':id_hotel'   => $hotelId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $guest = trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? ''));
            // QloApps stores unassigned booking rows with id_room=0. Treat
            // non-positive identifiers as type-level bookings; exposing 0 as
            // a physical room would hide the reservation from the panel's
            // FIFO type-hold accounting and permit overbooking.
            $roomId = (int)($r['id_room'] ?? 0);
            $out[] = [
                'room_id'  => $roomId > 0 ? $roomId : null,
                'id_room_type' => $r['id_room_type'] !== null ? (int)$r['id_room_type'] : null,
                'room'     => $r['room_num'] !== null && $r['room_num'] !== '' ? (string)$r['room_num'] : null,
                'checkin'  => substr((string)$r['date_from'], 0, 10),
                'checkout' => substr((string)$r['date_to'], 0, 10),
                'guest'    => $guest !== '' ? $guest : 'Reserva PMS',
                'channel'  => $this->channelFromPmsModule((string)($r['module'] ?? '')),
                'status'   => 'confirmed',
                'price'    => $r['total_paid_amount'] !== null ? (float)$r['total_paid_amount'] : null,
                'source'   => 'qlo',
            ];
        }
        return $out;
    }

    private function channelFromPmsModule(string $module): string {
        return match (strtolower(trim($module))) {
            'panel-phone' => 'phone',
            'panel-ota' => 'ota',
            'panel-owner' => 'owner',
            'panel-walkin' => 'walkin',
            default => 'qlo',
        };
    }

    /**
     * Fallback: booking_detail sin detalle de cuarto -> reserva a nivel de tipo.
     *
     * @param list<array<string,mixed>> $types
     * @return list<array<string,mixed>>
     */
    private function fetchQloBookingsSimple(string $from, string $to, array $types, int $hotelId): array {
        $stmt = $this->pdo->prepare("
            SELECT bd.id_product, rt.id AS id_room_type, bd.date_from, bd.date_to
            FROM qlo_htl_booking_detail bd
            LEFT JOIN qlo_htl_room_type rt
              ON rt.id_product = bd.id_product AND rt.id_hotel = bd.id_hotel
            WHERE bd.is_cancelled = 0 AND bd.is_refunded = 0
              AND bd.date_from < :range_to
              AND bd.date_to > :range_from
              AND bd.id_hotel = :id_hotel
        ");
        $stmt->execute([
            ':range_from' => $from . ' 00:00:00',
            ':range_to'   => $to . ' 00:00:00',
            ':id_hotel'   => $hotelId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $typeByProduct = [];
        foreach ($types as $t) {
            $typeByProduct[(int)$t['id_product']] = $t['name'];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'room_id'  => null,
                'id_room_type' => $r['id_room_type'] !== null ? (int)$r['id_room_type'] : null,
                'room'     => $typeByProduct[(int)$r['id_product']] ?? 'Habitacion',
                'checkin'  => substr((string)$r['date_from'], 0, 10),
                'checkout' => substr((string)$r['date_to'], 0, 10),
                'guest'    => 'Reserva PMS',
                'channel'  => 'qlo',
                'status'   => 'confirmed',
                'price'    => null,
                'source'   => 'qlo',
            ];
        }
        return $out;
    }

    /**
     * Holds de la web (provisional_bookings) activos en el rango.
     *
     * @param list<array<string,mixed>> $types
     * @return list<array<string,mixed>>
     */
    private function fetchHolds(string $from, string $to, int $hotelId, array $types): array {
        $this->ensureTablesExist();
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(pbr.id_room_type, pb.id_room_type) AS id_room_type,
                   COALESCE(pbr.quantity, 1) AS quantity,
                   pb.checkin, pb.checkout, pb.guest_data, pb.price_snapshot
            FROM provisional_bookings pb
            LEFT JOIN provisional_booking_rooms pbr ON pbr.cart_id = pb.cart_id
            WHERE (pb.status = 'paid' OR (pb.status = 'pending' AND pb.expires_at > NOW()))
              AND pb.checkin < :range_to
              AND pb.checkout > :range_from
              AND pb.id_hotel = :id_hotel
              AND NOT EXISTS (
                  SELECT 1 FROM qlo_orders qo
                  WHERE qo.current_state = 2
                    AND (qo.source = pb.cart_id OR qo.source = CONCAT('USGAR-', pb.cart_id))
              )
        ");
        $stmt->execute([
            ':range_from' => $from,
            ':range_to'   => $to,
            ':id_hotel'   => $hotelId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $typeNameByRoomType = [];
        foreach ($types as $t) {
            $typeNameByRoomType[(int)$t['id_room_type']] = $t['name'];
        }

        $out = [];
        foreach ($rows as $r) {
            $guest = 'Huesped web';
            $gd = json_decode((string)($r['guest_data'] ?? ''), true);
            if (is_array($gd)) {
                $name = trim((string)($gd['name'] ?? ''));
                if ($name !== '') {
                    $guest = $name;
                }
            }
            $quantity = max(1, (int)($r['quantity'] ?? 1));
            $out[] = [
                'room_id'  => null,
                'room'     => $typeNameByRoomType[(int)$r['id_room_type']] ?? 'Habitacion',
                'checkin'  => substr((string)$r['checkin'], 0, 10),
                'checkout' => substr((string)$r['checkout'], 0, 10),
                'guest'    => $guest,
                'channel'  => 'web',
                'status'   => 'hold',
                'price'    => $r['price_snapshot'] !== null ? (float)$r['price_snapshot'] : null,
                'source'   => 'hold',
                'quantity' => $quantity,
            ];
        }
        return $out;
    }

    /**
     * Bloqueos manuales importados (tabla propia).
     *
     * @return list<array<string,mixed>>
     */
    private function fetchManualBlocks(string $from, string $to, int $hotelId): array {
        $this->ensureTablesExist();
        $stmt = $this->pdo->prepare("
            SELECT mb.room_id, mb.room_num, mb.checkin, mb.checkout, mb.guest_name,
                   mb.channel, mb.status, mb.price
            FROM manual_blocks mb
            WHERE mb.checkin < :range_to
              AND mb.checkout > :range_from
              AND mb.id_hotel = :id_hotel
        ");
        $stmt->execute([
            ':range_from' => $from,
            ':range_to'   => $to,
            ':id_hotel'   => $hotelId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'room_id'  => $r['room_id'] !== null ? (int)$r['room_id'] : null,
                'room'     => (string)$r['room_num'],
                'checkin'  => substr((string)$r['checkin'], 0, 10),
                'checkout' => substr((string)$r['checkout'], 0, 10),
                'guest'    => (string)$r['guest_name'],
                'channel'  => in_array($r['channel'], ['web', 'walkin', 'ota', 'phone'], true) ? $r['channel'] : 'walkin',
                'status'   => $r['status'] === 'hold' ? 'hold' : 'confirmed',
                'price'    => $r['price'] !== null ? (float)$r['price'] : null,
                'source'   => 'manual',
            ];
        }
        return $out;
    }

    /**
     * Fuera de servicio (qlo_htl_room_disable_dates).
     *
     * @return list<array<string,mixed>>
     */
    private function fetchDisableDates(string $from, string $to, int $hotelId): array {
        if (!$this->hasTable('qlo_htl_room_disable_dates')) {
            return [];
        }
        try {
            $stmt = $this->pdo->prepare("
                SELECT rd.id_room, rd.date_from, rd.date_to
                FROM qlo_htl_room_disable_dates rd
                INNER JOIN qlo_htl_room_information ri ON ri.id = rd.id_room
                WHERE rd.date_from < :range_to
                  AND rd.date_to > :range_from
                  AND ri.id_hotel = :id_hotel
            ");
            $stmt->execute([
                ':range_from' => $from,
                ':range_to'   => $to,
                ':id_hotel'   => $hotelId,
            ]);
        } catch (PDOException $e) {
            Logger::error('AvailabilityRepository: disable dates no disponible: ' . $e->getMessage());
            return [];
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'room_id'  => (int)$r['id_room'],
                'room'     => null,
                'checkin'  => substr((string)$r['date_from'], 0, 10),
                'checkout' => substr((string)$r['date_to'], 0, 10),
                'guest'    => 'Fuera de servicio',
                'channel'  => 'maint',
                'status'   => 'maint',
                'price'    => null,
                'source'   => 'maint',
            ];
        }
        return $out;
    }

    /**
     * Inserta un bloqueo manual (import). Devuelve el id o null si la
     * habitacion no existe en qlo_htl_room_information.
     *
     * @param array<string,mixed> $row
     */
    public function insertManualBlock(array $row): ?int {
        if (!$this->pdo) {
            return null;
        }
        $this->ensureTablesExist();

        $roomNum = trim((string)($row['room_num'] ?? ''));
        $hotelId = (int)($row['id_hotel'] ?? Config::get('DEFAULT_HOTEL_ID', '1'));
        $roomId  = isset($row['room_id']) ? (int)$row['room_id'] : null;
        if ($roomId === null && $roomNum !== '') {
            $stmt = $this->pdo->prepare(
                "SELECT id FROM qlo_htl_room_information WHERE room_num = :room_num AND id_hotel = :id_hotel LIMIT 1"
            );
            $stmt->execute([':room_num' => $roomNum, ':id_hotel' => $hotelId]);
            $found = $stmt->fetchColumn();
            if ($found === false) {
                return null; // habitacion desconocida -> skip
            }
            $roomId = (int)$found;
        }
        if ($roomId === null) {
            return null;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO manual_blocks
                (id_hotel, room_id, room_num, checkin, checkout, guest_name, channel, status, price, notes)
            VALUES
                (:id_hotel, :room_id, :room_num, :checkin, :checkout, :guest_name, :channel, :status, :price, :notes)
        ");
        $ok = $stmt->execute([
            ':id_hotel'   => $hotelId,
            ':room_id'    => $roomId,
            ':room_num'   => $roomNum,
            ':checkin'    => (string)$row['checkin'],
            ':checkout'   => (string)$row['checkout'],
            ':guest_name' => substr(trim((string)($row['guest_name'] ?? 'Huesped')), 0, 128),
            ':channel'    => (string)($row['channel'] ?? 'walkin'),
            ':status'     => (string)($row['status'] ?? 'confirmed'),
            ':price'      => isset($row['price']) && $row['price'] !== '' ? (float)$row['price'] : null,
            ':notes'      => isset($row['notes']) ? substr((string)$row['notes'], 0, 255) : null,
        ]);
        return $ok ? (int)$this->pdo->lastInsertId() : null;
    }

    /**
     * Disponibilidad por HABITACIÓN FÍSICA en un rango (para el wizard del
     * panel). Devuelve por tipo: habitaciones con sus bloques ocupados
     * (reservas confirmadas qlo + holds web + bloqueos manuales + disable
     * dates). El frontend calcula la continuidad: una habitación es
     * "disponible continua" si no tiene NINGÚN bloque en TODO el rango — así
     * el wizard detecta los huecos (habitaciones del tipo libres por días
     * sueltos pero ninguna libre todas las noches = cambio de cuarto
     * obligatorio a mitad de estadía).
     *
     * @return array{from: string, to: string, types: list<array{id_room_type: int, name: string, price: float, rooms: list<array{room_id: int, room_num: string, floor: string|null, occupied: list<array{from: string, to: string, guest: string, channel: string, status: string, source: string}>}>, type_holds: list<array{from: string, to: string, guest: string, quantity: int, id_room_type: int}>}>}
     */
    public function getRoomAvailability(string $from, string $to, int $hotelId = 1): array {
        if (!$this->pdo) {
            return ['from' => $from, 'to' => $to, 'types' => []];
        }
        try {
            $types = $this->fetchRoomTypes($hotelId);
            // Reuse the type rows already loaded above; this avoids a second
            // metadata query in the same availability response.
            $rooms = $this->fetchRooms($hotelId, $types);

            // Bloqueos por habitación física (reservas qlo + manuales + disable).
            $occupiedByRoom = [];
            $unassignedQloByType = [];
            foreach ($this->fetchQloBookings($from, $to, $types, $hotelId) as $b) {
                if ($b['room_id'] !== null) {
                    $occupiedByRoom['id:' . $b['room_id']][] = $b;
                } elseif (!empty($b['id_room_type'])) {
                    // A PMS booking without a physical room still consumes
                    // one unit of its type. Feed it through the same FIFO
                    // type-hold assignment used by provisional web holds.
                    $unassignedQloByType[(int)$b['id_room_type']][] = $b;
                }
            }
            foreach ($this->fetchManualBlocks($from, $to, $hotelId) as $b) {
                $key = $b['room_id'] !== null ? 'id:' . $b['room_id'] : 'num:' . ($b['room'] ?? '');
                $occupiedByRoom[$key][] = $b;
            }
            foreach ($this->fetchDisableDates($from, $to, $hotelId) as $b) {
                $key = $b['room_id'] !== null ? 'id:' . $b['room_id'] : '';
                $occupiedByRoom[$key][] = $b;
            }
            // Holds web: a nivel de tipo (no tienen habitación física asignada).
            $holdByType = [];
            foreach ($this->fetchHolds($from, $to, $hotelId, $types) as $b) {
                $holdByType[(int)($b['id_room_type'] ?? 0)][] = $b;
            }
            foreach ($unassignedQloByType as $typeId => $blocks) {
                foreach ($blocks as $block) {
                    $holdByType[$typeId][] = [
                        'id_room_type' => $typeId,
                        'checkin' => $block['checkin'],
                        'checkout' => $block['checkout'],
                        'guest' => $block['guest'],
                        'quantity' => 1,
                        'channel' => $block['channel'] ?? 'qlo',
                        'status' => 'confirmed',
                        'source' => 'qlo',
                    ];
                }
            }

            $typeOut = [];
            foreach ($types as $t) {
                $tId = (int)$t['id_room_type'];
                $roomOut = [];
                foreach ($rooms as $r) {
                    if ((int)$r['id_room_type'] !== $tId) {
                        continue;
                    }
                    $occupied = [];
                    foreach ($occupiedByRoom['id:' . $r['room_id']] ?? [] as $b) {
                        $occupied[] = [
                            'from'    => substr((string)$b['checkin'], 0, 10),
                            'to'      => substr((string)$b['checkout'], 0, 10),
                            'guest'   => (string)$b['guest'],
                            'channel' => (string)$b['channel'],
                            'status'  => (string)$b['status'],
                            'source'  => (string)($b['source'] ?? ''),
                        ];
                    }
                    $roomOut[] = [
                        'room_id'  => $r['room_id'],
                        'room_num' => $r['room_num'],
                        'floor'    => $r['floor'],
                        'occupied' => $occupied,
                    ];
                }
                // Holds web del tipo: se exponen aparte (consumen inventario
                // del tipo, no una habitación concreta).
                $typeOut[] = [
                    'id_room_type' => $tId,
                    'name'         => (string)$t['name'],
                    'price'        => 0.0, // se rellena abajo desde la primera habitación
                    'rooms'        => $roomOut,
                    'type_holds'   => array_map(static fn(array $b): array => [
                        'from'  => substr((string)$b['checkin'], 0, 10),
                        'to'    => substr((string)$b['checkout'], 0, 10),
                        'guest' => (string)$b['guest'],
                        'quantity' => max(1, (int)($b['quantity'] ?? 1)),
                        'id_room_type' => (int)($b['id_room_type'] ?? 0),
                    ], $holdByType[$tId] ?? []),
                ];
            }
            // Precio por tipo desde la primera habitación del tipo.
            $priceByType = [];
            foreach ($rooms as $r) {
                $priceByType[(int)$r['id_room_type']] = $r['price'];
            }
            foreach ($typeOut as &$t) {
                $t['price'] = $priceByType[(int)$t['id_room_type']] ?? 0.0;
            }
            unset($t);

            return ['from' => $from, 'to' => $to, 'types' => $typeOut];
        } catch (PDOException $e) {
            Logger::error('AvailabilityRepository::getRoomAvailability Error: ' . $e->getMessage());
            return ['from' => $from, 'to' => $to, 'types' => []];
        }
    }

    /** @return array{room_id:int,id_room_type:int,id_hotel:int,id_product:int,room_num:string}|null */
    public function findRoomForBooking(int $roomId, int $roomTypeId, int $hotelId): ?array {
        if (!$this->pdo || $roomId < 1 || $roomTypeId < 1 || $hotelId < 1) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT ri.id AS room_id, ri.room_num, ri.id_hotel, ri.id_product, rt.id AS id_room_type
             FROM qlo_htl_room_information ri
             INNER JOIN qlo_htl_room_type rt
               ON rt.id_product = ri.id_product AND rt.id_hotel = ri.id_hotel
             WHERE ri.id = :room_id AND rt.id = :room_type_id AND ri.id_hotel = :id_hotel
             LIMIT 1'
        );
        $stmt->execute([':room_id' => $roomId, ':room_type_id' => $roomTypeId, ':id_hotel' => $hotelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? [
            'room_id' => (int)$row['room_id'],
            'id_room_type' => (int)$row['id_room_type'],
            'id_hotel' => (int)$row['id_hotel'],
            'id_product' => (int)$row['id_product'],
            'room_num' => (string)$row['room_num'],
        ] : null;
    }

    public function isRoomAvailable(int $roomId, string $from, string $to, int $hotelId): bool {
        if (!$this->pdo || $roomId < 1 || $hotelId < 1) {
            return false;
        }
        $checks = [
            ['SELECT 1 FROM qlo_htl_booking_detail bd WHERE bd.id_room = :room_id AND bd.id_hotel = :id_hotel AND bd.is_cancelled = 0 AND bd.is_refunded = 0 AND bd.date_from < :range_to AND bd.date_to > :range_from LIMIT 1', [':range_from' => $from . ' 00:00:00', ':range_to' => $to . ' 00:00:00']],
            ['SELECT 1 FROM manual_blocks mb WHERE mb.room_id = :room_id AND mb.id_hotel = :id_hotel AND mb.checkin < :range_to AND mb.checkout > :range_from LIMIT 1', [':range_from' => $from, ':range_to' => $to]],
            ['SELECT 1 FROM qlo_htl_room_disable_dates rd INNER JOIN qlo_htl_room_information ri ON ri.id = rd.id_room WHERE rd.id_room = :room_id AND ri.id_hotel = :id_hotel AND rd.date_from < :range_to AND rd.date_to > :range_from LIMIT 1', [':range_from' => $from, ':range_to' => $to]],
        ];
        foreach ($checks as $index => [$sql, $range]) {
            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([':room_id' => $roomId, ':id_hotel' => $hotelId] + $range);
                if ($stmt->fetchColumn() !== false) return false;
            } catch (PDOException $e) {
                $missingTable = str_contains($e->getMessage(), '1146') || str_contains($e->getMessage(), '42S02');
                if ($index === 0 || !$missingTable) {
                    Logger::error('AvailabilityRepository::isRoomAvailable Error: ' . $e->getMessage());
                    return false;
                }
            }
        }

        // A web hold has no physical room yet, so the three checks above are
        // insufficient when the selected room is the last free unit of its
        // type. Reuse the same FIFO assignment used by the panel UI to make
        // type-level holds authoritative during the transaction re-check.
        $availability = $this->getRoomAvailability($from, $to, $hotelId);
        foreach ($availability['types'] as $type) {
            $selected = false;
            foreach ($type['rooms'] as $room) {
                if ((int)$room['room_id'] !== $roomId) {
                    continue;
                }
                $selected = true;
                $holdsLeft = 0;
                foreach ($type['type_holds'] as $hold) {
                    if ((string)$hold['from'] < $to && (string)$hold['to'] > $from) {
                        $holdsLeft += max(1, (int)$hold['quantity']);
                    }
                }
                foreach ($type['rooms'] as $candidate) {
                    $occupied = false;
                    foreach ($candidate['occupied'] as $block) {
                        if ((string)$block['from'] < $to && (string)$block['to'] > $from) {
                            $occupied = true;
                            break;
                        }
                    }
                    $free = !$occupied && !($holdsLeft-- > 0);
                    if ((int)$candidate['room_id'] === $roomId) {
                        return $free;
                    }
                }
            }
            if ($selected) {
                return false;
            }
        }

        return false;
    }

    /**
     * Inserta un bloqueo del dueño / mantenimiento en qlo_htl_room_disable_dates
     * (tabla NATIVA de QloApps para "no vendible"). Descuenta disponibilidad
     * web (QloAppAdapter suma disable_dates desde 2026-08-15) y se ve en el
     * CMS. Devuelve el id o null si la habitación no existe.
     *
     * @param array{room_id: int, id_hotel?: int, date_from: string, date_to: string, reason?: string} $data
     */
    public function insertDisableDate(array $data): ?int {
        if (!$this->pdo) {
            return null;
        }
        $roomId = (int)$data['room_id'];
        $hotelId = (int)($data['id_hotel'] ?? Config::get('DEFAULT_HOTEL_ID', '1'));
        if ($hotelId < 1) {
            return null;
        }
        if ($roomId < 1) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            "SELECT ri.id_product, rt.id AS id_room_type
             FROM qlo_htl_room_information ri
             INNER JOIN qlo_htl_room_type rt ON rt.id_product = ri.id_product AND rt.id_hotel = ri.id_hotel
            WHERE ri.id = :id AND ri.id_hotel = :id_hotel LIMIT 1"
        );
         $stmt->execute([':id' => $roomId, ':id_hotel' => $hotelId]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$found) {
            return null;
        }
        $ins = $this->pdo->prepare("
            INSERT INTO qlo_htl_room_disable_dates (id_room_type, id_room, date_from, date_to, reason, date_add, date_upd)
            VALUES (:id_room_type, :id_room, :date_from, :date_to, :reason, NOW(), NOW())
        ");
        $ok = $ins->execute([
            ':id_room_type' => (int)$found['id_room_type'],
            ':id_room'      => $roomId,
            ':date_from'    => (string)$data['date_from'],
            ':date_to'      => (string)$data['date_to'],
            ':reason'       => substr(trim((string)($data['reason'] ?? '')), 0, 255) ?: null,
        ]);
        return $ok ? (int)$this->pdo->lastInsertId() : null;
    }

    /**
     * Elimina un bloqueo (disable_date) por id. Devuelve true si borró algo.
     */
    public function deleteDisableDate(int $id, int $hotelId = 1): bool {
        if (!$this->pdo || $id < 1 || $hotelId < 1) {
            return false;
        }
        // qlo_htl_room_disable_dates has no hotel column. Scope the mutation
        // through the owning room so one hotel's panel cannot remove another
        // hotel's maintenance block by guessing its numeric id.
        $stmt = $this->pdo->prepare("DELETE rd FROM qlo_htl_room_disable_dates rd
            INNER JOIN qlo_htl_room_information ri ON ri.id = rd.id_room
            WHERE rd.id = :id AND ri.id_hotel = :id_hotel");
        $stmt->execute([':id' => $id, ':id_hotel' => $hotelId]);
        return $stmt->rowCount() > 0;
    }

    private function hasTable(string $table): bool {
        if (array_key_exists($table, $this->tablePresence)) {
            return $this->tablePresence[$table];
        }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t"
            );
            $stmt->execute([':t' => $table]);
            return $this->tablePresence[$table] = (int)$stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            return $this->tablePresence[$table] = false;
        }
    }
}
