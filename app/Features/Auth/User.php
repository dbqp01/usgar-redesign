<?php
declare(strict_types=1);

namespace App\Features\Auth;

use PDO;
use PDOException;
use App\Core\Logger;

/**
 * Modelo para la tabla `users`.
 * Patrón Repository/Data Mapper — SQL separado de controllers.
 *
 * Soporta autenticación social (Google, Microsoft, Facebook)
 * y autenticación tradicional (email + contraseña con bcrypt).
 *
 * Vinculación por email: si un usuario se registra con Google
 * y después intenta con email+password (o viceversa), se vincula
 * por email para evitar duplicados.
 */
class User {
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Busca un usuario por email.
     */
    public function findByEmail(string $email): ?array {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            Logger::error('User::findByEmail failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Busca un usuario por proveedor OAuth + ID del proveedor.
     */
    public function findByProvider(string $provider, string $providerId): ?array {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM users WHERE provider = :provider AND provider_id = :provider_id LIMIT 1'
            );
            $stmt->execute([':provider' => $provider, ':provider_id' => $providerId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            Logger::error('User::findByProvider failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Busca un usuario por su ID.
     */
    public function findById(int $id): ?array {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            Logger::error('User::findById failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Crea un usuario desde un perfil OAuth.
     * Si ya existe un usuario con el mismo email, actualiza el proveedor.
     *
     * @param array{email: string, first_name: ?string, last_name: ?string, photo_url: ?string, phone: ?string, provider: string, provider_id: string} $profile
     * @return int|null ID del usuario creado/actualizado, o null en caso de error
     */
    public function createFromOAuth(array $profile): ?int {
        try {
            // Primero verificar si ya existe por email
            $existing = $this->findByEmail($profile['email']);

            if ($existing !== null) {
                // Si el usuario ya tiene un provider asignado y no coincide con el actual, no sobrescribir a ciegas
                $newProvider = $existing['provider'] === 'email' ? $profile['provider'] : $existing['provider'];
                $newProviderId = $existing['provider'] === 'email' ? $profile['provider_id'] : $existing['provider_id'];

                $stmt = $this->pdo->prepare('
                    UPDATE users SET
                        provider = :provider,
                        provider_id = :provider_id,
                        photo_url = COALESCE(:photo_url, photo_url),
                        first_name = COALESCE(:first_name, first_name),
                        last_name = COALESCE(:last_name, last_name),
                        updated_at = NOW()
                    WHERE id = :id
                ');
                $stmt->execute([
                    ':provider'    => $newProvider,
                    ':provider_id' => $newProviderId,
                    ':photo_url'   => $profile['photo_url'] ?? null,
                    ':first_name'  => $profile['first_name'] ?? null,
                    ':last_name'   => $profile['last_name'] ?? null,
                    ':id'          => $existing['id'],
                ]);
                return (int) $existing['id'];
            }

            // Crear nuevo usuario
            $stmt = $this->pdo->prepare('
                INSERT INTO users (email, first_name, last_name, phone, photo_url, provider, provider_id)
                VALUES (:email, :first_name, :last_name, :phone, :photo_url, :provider, :provider_id)
            ');
            $stmt->execute([
                ':email'       => $profile['email'],
                ':first_name'  => $profile['first_name'] ?? null,
                ':last_name'   => $profile['last_name'] ?? null,
                ':phone'       => $profile['phone'] ?? null,
                ':photo_url'   => $profile['photo_url'] ?? null,
                ':provider'    => $profile['provider'],
                ':provider_id' => $profile['provider_id'],
            ]);

            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            Logger::error('User::createFromOAuth failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Crea un usuario con email y contraseña.
     *
     * @return int|null ID del usuario creado, o null si el email ya existe o hubo error
     */
    public function createFromEmail(string $email, string $password, string $firstName, string $lastName = ''): ?int {
        try {
            // Verificar duplicado
            if ($this->findByEmail($email) !== null) {
                return null; // Email ya registrado
            }

            $stmt = $this->pdo->prepare('
                INSERT INTO users (email, first_name, last_name, password_hash, provider)
                VALUES (:email, :first_name, :last_name, :password_hash, :provider)
            ');
            $stmt->execute([
                ':email'         => $email,
                ':first_name'    => $firstName,
                ':last_name'     => $lastName,
                ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
                ':provider'      => 'email',
            ]);

            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            Logger::error('User::createFromEmail failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Verifica email + contraseña y retorna el usuario.
     */
    public function verifyPassword(string $email, string $password): ?array {
        $user = $this->findByEmail($email);

        if ($user === null) {
            return null;
        }

        if (empty($user['password_hash'])) {
            return null; // Usuario OAuth sin contraseña
        }

        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }

        return $user;
    }

    /**
     * Obtiene las reservas de un usuario.
     */
    public function getBookings(int $userId): array {
        try {
            $stmt = $this->pdo->prepare('
                SELECT cart_id, id_room_type, room_data, guest_data, price_snapshot,
                       checkin, checkout, status, preference_id, qlo_order_id, created_at
                FROM provisional_bookings
                WHERE user_id = :user_id
                ORDER BY created_at DESC
            ');
            $stmt->execute([':user_id' => $userId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return array_map(function (array $row): array {
                $row['room_data'] = json_decode($row['room_data'] ?? '{}', true);
                $row['guest_data'] = json_decode($row['guest_data'] ?? '{}', true);
                return $row;
            }, $results);
        } catch (PDOException $e) {
            Logger::error('User::getBookings failed: ' . $e->getMessage());
            return [];
        }
    }
}
