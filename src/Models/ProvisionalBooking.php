<?php
declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use App\Core\Logger;

/**
 * Modelo para interactuar con la tabla de bloqueos temporales (provisional_bookings).
 * Sigue el patrón Repository/Data Mapper para no mezclar lógica SQL con controladores.
 */
class ProvisionalBooking {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

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
                ':cart_id' => $data['cart_id'],
                ':id_hotel' => $data['id_hotel'],
                ':id_room_type' => $data['id_room_type'],
                ':guest_data' => json_encode($data['guest_data']),
                ':room_data' => json_encode($data['room_data']),
                ':price_snapshot' => $data['price_snapshot'],
                ':checkin' => $data['checkin'],
                ':checkout' => $data['checkout'],
                ':status' => $data['status'] ?? 'pending',
                ':expires_at' => $data['expires_at']
            ]);
        } catch (PDOException $e) {
            Logger::error("ProvisionalBooking::create failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Busca un hold por su cart_id.
     */
    public function getByCartId(string $cartId): ?array {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM provisional_bookings WHERE cart_id = :cart_id");
            $stmt->execute([':cart_id' => $cartId]);
            $result = $stmt->fetch();
            
            if ($result) {
                // Decodificar JSON de datos guardados
                $result['guest_data'] = json_decode($result['guest_data'], true);
                $result['room_data'] = json_decode($result['room_data'], true);
                return $result;
            }
            return null;
        } catch (PDOException $e) {
            Logger::error("ProvisionalBooking::getByCartId failed: " . $e->getMessage());
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
                ':cart_id' => $cartId
            ]);
        } catch (PDOException $e) {
            Logger::error("ProvisionalBooking::updatePreferenceId failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Actualiza el estado de la reserva.
     */
    public function updateStatus(string $cartId, string $status): bool {
        try {
            $stmt = $this->pdo->prepare("UPDATE provisional_bookings SET status = :status WHERE cart_id = :cart_id");
            return $stmt->execute([
                ':status' => $status,
                ':cart_id' => $cartId
            ]);
        } catch (PDOException $e) {
            Logger::error("ProvisionalBooking::updateStatus failed: " . $e->getMessage());
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
                WHERE cart_id = :cart_id AND status = 'pending'
            ");
            return $stmt->execute([
                ':expires_at' => $newExpiration,
                ':cart_id' => $cartId
            ]);
        } catch (PDOException $e) {
            Logger::error("ProvisionalBooking::extend failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Elimina o expira los bloqueos que hayan superado su tiempo límite.
     * Diseñado para ejecutarse periódicamente mediante Cron Job.
     */
    public function cleanupExpiredHolds(): int {
        try {
            // Eliminar registros de provisional_bookings que expiraron y siguen en pending
            $stmt = $this->pdo->prepare("
                DELETE FROM provisional_bookings 
                WHERE status = 'pending' AND expires_at < NOW()
            ");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            Logger::error("ProvisionalBooking::cleanupExpiredHolds failed: " . $e->getMessage());
            return 0;
        }
    }
}
