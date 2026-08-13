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

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    public function ensureTablesExist(): void {
        if (!$this->pdo) {
            return;
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
            ");
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
        $last  = date('Y-m-t', strtotime($first) ?: 0);
        $monthEnd = date('Y-m-d', strtotime($last) ?: 0);
        $monthStart = $first;

        try {
            $types = $this->fetchRoomTypes($hotelId);
            $rooms = $this->fetchRooms($hotelId);
            $bookings = $this->fetchQloBookings($monthStart, $monthEnd, $types);
            $bookings = array_merge($bookings, $this->fetchHolds($monthStart, $monthEnd, $hotelId, $types));
            $bookings = array_merge($bookings, $this->fetchManualBlocks($monthStart, $monthEnd, $hotelId));
            $bookings = array_merge($bookings, $this->fetchDisableDates($monthStart, $monthEnd));

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
     * @return list<array<string,mixed>>
     */
    private function fetchRooms(int $hotelId): array {
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
        foreach ($this->fetchRoomTypes($hotelId) as $t) {
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
    private function fetchQloBookings(string $from, string $to, array $types): array {
        try {
            return $this->fetchQloBookingsRich($from, $to);
        } catch (PDOException $e) {
            Logger::error('AvailabilityRepository: rama rica fallo, degradando: ' . $e->getMessage());
        }
        return $this->fetchQloBookingsSimple($from, $to, $types);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchQloBookingsRich(string $from, string $to): array {
        $stmt = $this->pdo->prepare("
            SELECT bd.id_room, bd.room_num, bd.date_from, bd.date_to,
                   bd.total_paid_amount, c.firstname, c.lastname
            FROM qlo_htl_booking_detail bd
            LEFT JOIN qlo_customer c ON c.id_customer = bd.id_customer
            WHERE bd.is_cancelled = 0 AND bd.is_refunded = 0
              AND bd.date_from < :range_to
              AND bd.date_to > :range_from
        ");
        $stmt->execute([
            ':range_from' => $from . ' 00:00:00',
            ':range_to'   => $to . ' 23:59:59',
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $guest = trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? ''));
            $out[] = [
                'room_id'  => $r['id_room'] !== null ? (int)$r['id_room'] : null,
                'room'     => $r['room_num'] !== null && $r['room_num'] !== '' ? (string)$r['room_num'] : null,
                'checkin'  => substr((string)$r['date_from'], 0, 10),
                'checkout' => substr((string)$r['date_to'], 0, 10),
                'guest'    => $guest !== '' ? $guest : 'Reserva PMS',
                'channel'  => 'qlo',
                'status'   => 'confirmed',
                'price'    => $r['total_paid_amount'] !== null ? (float)$r['total_paid_amount'] : null,
                'source'   => 'qlo',
            ];
        }
        return $out;
    }

    /**
     * Fallback: booking_detail sin detalle de cuarto -> reserva a nivel de tipo.
     *
     * @param list<array<string,mixed>> $types
     * @return list<array<string,mixed>>
     */
    private function fetchQloBookingsSimple(string $from, string $to, array $types): array {
        $stmt = $this->pdo->prepare("
            SELECT bd.id_product, bd.date_from, bd.date_to
            FROM qlo_htl_booking_detail bd
            WHERE bd.is_cancelled = 0 AND bd.is_refunded = 0
              AND bd.date_from < :range_to
              AND bd.date_to > :range_from
        ");
        $stmt->execute([
            ':range_from' => $from . ' 00:00:00',
            ':range_to'   => $to . ' 23:59:59',
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
        $stmt = $this->pdo->prepare("
            SELECT pb.id_room_type, pb.checkin, pb.checkout, pb.guest_data, pb.price_snapshot
            FROM provisional_bookings pb
            WHERE (pb.status = 'paid' OR (pb.status = 'pending' AND pb.expires_at > NOW()))
              AND pb.checkin < :range_to
              AND pb.checkout > :range_from
              AND pb.id_hotel = :id_hotel
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
    private function fetchDisableDates(string $from, string $to): array {
        if (!$this->hasTable('qlo_htl_room_disable_dates')) {
            return [];
        }
        try {
            $stmt = $this->pdo->prepare("
                SELECT rd.id_room, rd.date_from, rd.date_to
                FROM qlo_htl_room_disable_dates rd
                WHERE rd.date_from < :range_to
                  AND rd.date_to > :range_from
            ");
            $stmt->execute([
                ':range_from' => $from,
                ':range_to'   => $to,
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
        $roomId  = isset($row['room_id']) ? (int)$row['room_id'] : null;
        if ($roomId === null && $roomNum !== '') {
            $stmt = $this->pdo->prepare(
                "SELECT id FROM qlo_htl_room_information WHERE room_num = :room_num LIMIT 1"
            );
            $stmt->execute([':room_num' => $roomNum]);
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
            ':id_hotel'   => (int)($row['id_hotel'] ?? (int)Config::get('DEFAULT_HOTEL_ID', '1')),
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

    private function hasTable(string $table): bool {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t"
            );
            $stmt->execute([':t' => $table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
}
