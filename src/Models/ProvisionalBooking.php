<?php
declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use App\Core\Logger;
use App\Core\BookingStatus;

/**
 * Modelo para la tabla provisional_bookings (bloqueos temporales).
 * Patrón Repository/Data Mapper — SQL separado de controllers.
 *
 * Fix: cleanupExpiredHolds usa UPDATE (audit trail) en vez de DELETE.
 * Mejora: usa BookingStatus enum para tipo-seguridad.
 */
class ProvisionalBooking {
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Guarda un bloqueo temporal (Hold) en la base de datos.
     */
    public function create(array $data): bool {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO provisional_bookings 
                (cart_id, id_hotel, id_room_type, guest_data, room_data, price_snapshot, checkin, checkout, status, expires_at)
                VALUES 
                (:cart_id, :id_hotel, :id_room_type, :guest_data, :room_data, :price_snapshot, :checkin, :checkout, :status, :expires_at)
            ");

            return $stmt->execute([
                ':cart_id'        => $data['cart_id'],
                ':id_hotel'       => $data['id_hotel'],
                ':id_room_type'   => $data['id_room_type'],
                ':guest_data'     => json_encode($data['guest_data'], JSON_THROW_ON_ERROR),
                ':room_data'      => json_encode($data['room_data'], JSON_THROW_ON_ERROR),
                ':price_snapshot' => $data['price_snapshot'],
                ':checkin'        => $data['checkin'],
                ':checkout'       => $data['checkout'],
                ':status'         => $data['status'] ?? BookingStatus::Pending->value,
                ':expires_at'     => $data['expires_at'],
            ]);
        } catch (PDOException | \JsonException $e) {
            Logger::error('ProvisionalBooking::create failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Busca un hold por su cart_id.
     */
    public function getByCartId(string $cartId): ?array {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM provisional_bookings WHERE cart_id = :cart_id');
            $stmt->execute([':cart_id' => $cartId]);
            $result = $stmt->fetch();

            if ($result) {
                $result['guest_data'] = json_decode($result['guest_data'], true, 512, JSON_THROW_ON_ERROR);
                $result['room_data'] = json_decode($result['room_data'], true, 512, JSON_THROW_ON_ERROR);
                return $result;
            }
            return null;
        } catch (PDOException | \JsonException $e) {
            Logger::error('ProvisionalBooking::getByCartId failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Busca un hold por su ID de preferencia de Mercado Pago.
     */
    public function findByPreferenceId(string $preferenceId): ?array {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM provisional_bookings WHERE mercado_pago_preference_id = :pref_id'
            );
            $stmt->execute([':pref_id' => $preferenceId]);
            $result = $stmt->fetch();

            if ($result) {
                $result['guest_data'] = json_decode($result['guest_data'], true, 512, JSON_THROW_ON_ERROR);
                $result['room_data'] = json_decode($result['room_data'], true, 512, JSON_THROW_ON_ERROR);
                return $result;
            }
            return null;
        } catch (PDOException | \JsonException $e) {
            Logger::error('ProvisionalBooking::findByPreferenceId failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Actualiza el ID de preferencia de Mercado Pago asignado al hold.
     */
    public function updatePreferenceId(string $cartId, string $preferenceId): bool {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE provisional_bookings 
                SET mercado_pago_preference_id = :pref_id 
                WHERE cart_id = :cart_id
            ");
            return $stmt->execute([
                ':pref_id' => $preferenceId,
                ':cart_id' => $cartId,
            ]);
        } catch (PDOException $e) {
            Logger::error('ProvisionalBooking::updatePreferenceId failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Actualiza el estado de la reserva.
     */
    public function updateStatus(string $cartId, string $status): bool {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE provisional_bookings SET status = :status WHERE cart_id = :cart_id'
            );
            return $stmt->execute([
                ':status'  => $status,
                ':cart_id' => $cartId,
            ]);
        } catch (PDOException $e) {
            Logger::error('ProvisionalBooking::updateStatus failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Extiende el tiempo de expiración del hold.
     */
    public function extend(string $cartId, string $newExpiration): bool {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE provisional_bookings 
                SET expires_at = :expires_at 
                WHERE cart_id = :cart_id AND status = :status
            ");
            return $stmt->execute([
                ':expires_at' => $newExpiration,
                ':cart_id'    => $cartId,
                ':status'     => BookingStatus::Pending->value,
            ]);
        } catch (PDOException $e) {
            Logger::error('ProvisionalBooking::extend failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Marca como expirados los bloqueos que superaron su tiempo límite.
     * Usa UPDATE en vez de DELETE para mantener audit trail.
     */
    public function cleanupExpiredHolds(): int {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE provisional_bookings 
                SET status = :expired_status 
                WHERE status = :pending_status AND expires_at < NOW()
            ");
            $stmt->execute([
                ':expired_status' => BookingStatus::Expired->value,
                ':pending_status' => BookingStatus::Pending->value,
            ]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            Logger::error('ProvisionalBooking::cleanupExpiredHolds failed: ' . $e->getMessage());
            return 0;
        }
    }
}
