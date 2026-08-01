<?php
declare(strict_types=1);

namespace App\Features\Booking\Domain;

use PDO;
use PDOException;
use App\Core\Logger;
use App\Core\Database;
use App\Core\Config;

/**
 * Repositorio de reservas provisionales (Holds temporales de 15 minutos).
 */
class ProvisionalBookingRepository {
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    public function create(array $data): bool {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO provisional_bookings (
                    cart_id, user_id, id_hotel, id_room_type, guest_data, room_data,
                    price_snapshot, checkin, checkout, status, expires_at
                ) VALUES (
                    :cart_id, :user_id, :id_hotel, :id_room_type, :guest_data, :room_data,
                    :price_snapshot, :checkin, :checkout, :status, :expires_at
                )
            ");

            return $stmt->execute([
                ':cart_id'       => $data['cart_id'],
                ':user_id'       => $data['user_id'] ?? null,
                        ':id_hotel'      => $data['id_hotel'] ?? Config::get('DEFAULT_HOTEL_ID', '1'),
                ':id_room_type'  => $data['id_room_type'],
                ':guest_data'    => json_encode($data['guest_data'] ?? [], JSON_THROW_ON_ERROR),
                ':room_data'     => json_encode($data['room_data'] ?? [], JSON_THROW_ON_ERROR),
                ':price_snapshot'=> $data['price_snapshot'],
                ':checkin'       => $data['checkin'],
                ':checkout'      => $data['checkout'],
                ':status'        => $data['status'] ?? 'pending',
                ':expires_at'    => $data['expires_at'],
            ]);
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::create Error: ' . $e->getMessage());
            if ($this->pdo && (str_contains($e->getMessage(), '1146') || str_contains($e->getMessage(), '42S02') || str_contains($e->getMessage(), "doesn't exist"))) {
                Logger::info('ProvisionalBookingRepository: Creando tablas provisional_bookings y processed_payments automáticamente...');
                $this->ensureTablesExist();
                try {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO provisional_bookings (
                            cart_id, user_id, id_hotel, id_room_type, guest_data, room_data,
                            price_snapshot, checkin, checkout, status, expires_at
                        ) VALUES (
                            :cart_id, :user_id, :id_hotel, :id_room_type, :guest_data, :room_data,
                            :price_snapshot, :checkin, :checkout, :status, :expires_at
                        )
                    ");
                    return $stmt->execute([
                        ':cart_id'       => $data['cart_id'],
                        ':user_id'       => $data['user_id'] ?? null,
                ':id_hotel'      => $data['id_hotel'] ?? Config::get('DEFAULT_HOTEL_ID', '1'),
                        ':id_room_type'  => $data['id_room_type'],
                        ':guest_data'    => json_encode($data['guest_data'] ?? [], JSON_THROW_ON_ERROR),
                        ':room_data'     => json_encode($data['room_data'] ?? [], JSON_THROW_ON_ERROR),
                        ':price_snapshot'=> $data['price_snapshot'],
                        ':checkin'       => $data['checkin'],
                        ':checkout'      => $data['checkout'],
                        ':status'        => $data['status'] ?? 'pending',
                        ':expires_at'    => $data['expires_at'],
                    ]);
                } catch (PDOException $ex) {
                    Logger::error('ProvisionalBookingRepository::create Retry Error: ' . $ex->getMessage());
                    return false;
                }
            }
            return false;
        }
    }

    private function ensureTablesExist(): void {
        if (!$this->pdo) return;
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS provisional_bookings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    cart_id VARCHAR(64) UNIQUE NOT NULL,
                    user_id INT NULL,
                    id_hotel INT DEFAULT 1,
                    id_room_type INT NOT NULL,
                    guest_data TEXT,
                    room_data TEXT,
                    price_snapshot DECIMAL(10,2) NOT NULL,
                    checkin DATE NOT NULL,
                    checkout DATE NOT NULL,
                    status VARCHAR(32) DEFAULT 'pending',
                    expires_at DATETIME NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_hotel_room_status_dates (id_hotel, id_room_type, status, checkin, checkout)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS processed_payments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    payment_id VARCHAR(64) UNIQUE NOT NULL,
                    cart_id VARCHAR(64) NOT NULL,
                    status VARCHAR(32) NOT NULL,
                    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // Auto-heal: garantizar columna payment_id (necesaria para
            // attachPaymentId y la reconciliacion de pagos). Migracion
            // documentada en docs/refactoring/CRON.md.
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'provisional_bookings'
                  AND COLUMN_NAME = 'payment_id'
            ");
            $stmt->execute();
            if ((int)$stmt->fetchColumn() === 0) {
                $this->pdo->exec("ALTER TABLE provisional_bookings ADD COLUMN payment_id VARCHAR(64) NULL AFTER status");
                Logger::info('ProvisionalBookingRepository: Columna payment_id creada automaticamente en provisional_bookings.');
            }
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::ensureTablesExist Error: ' . $e->getMessage());
        }
    }

    public function getByCartId(string $cartId): ?array {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM provisional_bookings WHERE cart_id = :cart_id LIMIT 1");
            $stmt->execute([':cart_id' => $cartId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return null;
            }

            if (!empty($row['guest_data']) && is_string($row['guest_data'])) {
                $row['guest_data'] = json_decode($row['guest_data'], true) ?: [];
            }
            if (!empty($row['room_data']) && is_string($row['room_data'])) {
                $row['room_data'] = json_decode($row['room_data'], true) ?: [];
            }

            return $row;
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::getByCartId Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtiene una reserva provisional con bloqueo pesimista (FOR UPDATE) dentro de una transaccion activa.
     */
    public function getByCartIdForUpdate(string $cartId): ?array {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM provisional_bookings WHERE cart_id = :cart_id LIMIT 1 FOR UPDATE");
            $stmt->execute([':cart_id' => $cartId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return null;
            }

            if (!empty($row['guest_data']) && is_string($row['guest_data'])) {
                $row['guest_data'] = json_decode($row['guest_data'], true) ?: [];
            }
            if (!empty($row['room_data']) && is_string($row['room_data'])) {
                $row['room_data'] = json_decode($row['room_data'], true) ?: [];
            }

            return $row;
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::getByCartIdForUpdate Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Verifica si un payment_id ya fue procesado en la tabla de idempotencia.
     */
    public function isPaymentProcessed(string $paymentId): bool {
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM processed_payments WHERE payment_id = :payment_id FOR UPDATE");
            $stmt->execute([':payment_id' => $paymentId]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::isPaymentProcessed Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Registra un pago procesado en la tabla de idempotencia processed_payments.
     */
    public function markPaymentProcessed(string $paymentId, string $cartId, string $status = 'approved'): bool {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO processed_payments (payment_id, cart_id, status)
                VALUES (:payment_id, :cart_id, :status)
                ON DUPLICATE KEY UPDATE status = VALUES(status)
            ");
            return $stmt->execute([
                ':payment_id' => $paymentId,
                ':cart_id'    => $cartId,
                ':status'     => $status,
            ]);
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::markPaymentProcessed Error: ' . $e->getMessage());
            return false;
        }
    }

    public function updateStatus(string $cartId, string $status): bool {
        try {
            $stmt = $this->pdo->prepare("UPDATE provisional_bookings SET status = :status WHERE cart_id = :cartId");
            return $stmt->execute([':status' => $status, ':cartId' => $cartId]);
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::updateStatus Error: ' . $e->getMessage());
            return false;
        }
    }

    public function extend(string $cartId, string $newExpiration): bool {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE provisional_bookings 
                SET expires_at = :newExp 
                WHERE cart_id = :cartId AND status = 'pending'
            ");
            return $stmt->execute([':newExp' => $newExpiration, ':cartId' => $cartId]);
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::extend Error: ' . $e->getMessage());
            return false;
        }
    }

    public function cleanExpiredCarts(): int {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE provisional_bookings 
                SET status = 'expired' 
                WHERE status = 'pending' AND expires_at < NOW()
            ");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::cleanExpiredCarts Error: ' . $e->getMessage());
            return 0;
        }
    }

    public function getHoldCountForRoomForUpdate(int $idRoomType, string $checkIn, string $checkOut, int $idHotel): int {
        if (!$this->pdo) return 0;
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM provisional_bookings
                WHERE id_hotel = :idHotel
                  AND id_room_type = :idRoomType
                  AND (status = 'paid' OR (status = 'pending' AND expires_at > NOW()))
                  AND checkin < :checkout
                  AND checkout > :checkin
                FOR UPDATE
            ");
            $stmt->execute([
                ':idHotel'    => $idHotel,
                ':idRoomType' => $idRoomType,
                ':checkin'    => $checkIn,
                ':checkout'   => $checkOut,
            ]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::getHoldCountForRoomForUpdate Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Reservas provisionales pendientes con payment_id registrado (para reconciliacion).
     * Devuelve solo holds aun no expirados.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPendingHoldsWithPaymentId(): array {
        if (!$this->pdo) return [];
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM provisional_bookings
                WHERE status = 'pending'
                  AND payment_id IS NOT NULL
                  AND payment_id <> ''
                  AND expires_at > NOW()
                LIMIT 50
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                if (!empty($row['guest_data']) && is_string($row['guest_data'])) {
                    $row['guest_data'] = json_decode($row['guest_data'], true) ?: [];
                }
                if (!empty($row['room_data']) && is_string($row['room_data'])) {
                    $row['room_data'] = json_decode($row['room_data'], true) ?: [];
                }
            }
            return $rows;
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::getPendingHoldsWithPaymentId Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Registra el payment_id de MercadoPago sobre un hold pendiente.
     * Requiere la columna payment_id en provisional_bookings (migracion manual en produccion).
     */
    public function attachPaymentId(string $cartId, string $paymentId): bool {
        try {
            $stmt = $this->pdo->prepare("UPDATE provisional_bookings SET payment_id = :payment_id WHERE cart_id = :cartId");
            return $stmt->execute([':payment_id' => $paymentId, ':cartId' => $cartId]);
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::attachPaymentId Error: ' . $e->getMessage());
            return false;
        }
    }
}
