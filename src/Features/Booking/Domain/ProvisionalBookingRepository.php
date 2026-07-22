<?php
declare(strict_types=1);

namespace App\Features\Booking\Domain;

use PDO;
use PDOException;
use App\Core\Logger;
use App\Core\Database;

/**
 * Repositorio de reservas provisionales (Holds temporales de 15 minutos).
 */
class ProvisionalBookingRepository {
    private PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    public function create(array $data): bool {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO provisional_bookings (
                    cart_id, user_id, id_hotel, id_room_type, guest_data, room_data,
                    price_snapshot, checkin, checkout, status, preference_id, expires_at
                ) VALUES (
                    :cart_id, :user_id, :id_hotel, :id_room_type, :guest_data, :room_data,
                    :price_snapshot, :checkin, :checkout, :status, :preference_id, :expires_at
                )
            ");

            return $stmt->execute([
                ':cart_id'       => $data['cart_id'],
                ':user_id'       => $data['user_id'] ?? null,
                ':id_hotel'      => $data['id_hotel'] ?? 1,
                ':id_room_type'  => $data['id_room_type'],
                ':guest_data'    => json_encode($data['guest_data'] ?? [], JSON_THROW_ON_ERROR),
                ':room_data'     => json_encode($data['room_data'] ?? [], JSON_THROW_ON_ERROR),
                ':price_snapshot'=> $data['price_snapshot'],
                ':checkin'       => $data['checkin'],
                ':checkout'      => $data['checkout'],
                ':status'        => $data['status'] ?? 'pending',
                ':preference_id' => $data['preference_id'] ?? null,
                ':expires_at'    => $data['expires_at'],
            ]);
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::create Error: ' . $e->getMessage());
            return false;
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

    public function updatePreferenceId(string $cartId, string $preferenceId): bool {
        try {
            $stmt = $this->pdo->prepare("UPDATE provisional_bookings SET preference_id = :pref WHERE cart_id = :cartId");
            return $stmt->execute([':pref' => $preferenceId, ':cartId' => $cartId]);
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::updatePreferenceId Error: ' . $e->getMessage());
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
}
