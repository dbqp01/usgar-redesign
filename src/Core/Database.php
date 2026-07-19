<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

/**
 * Gestión de conexión a Base de Datos (Singleton PDO).
 * Principio de Responsabilidad Única: solo maneja la conexión PDO.
 * La carga de .env fue extraída a Config.php.
 */
class Database {
    private static ?Database $instance = null;
    private ?PDO $pdo = null;

    private function __construct() {
        $host = Config::get('DB_HOST', 'localhost');
        $port = Config::get('DB_PORT', '3306');
        $user = Config::get('DB_USER');
        $pass = Config::get('DB_PASS');
        $name = Config::get('DB_NAME');

        if (empty($user) || empty($name)) {
            throw new PDOException('Database credentials or database name are not configured.');
        }

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            Logger::error('[Database Connection Error] ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Retorna la instancia única del gestor de base de datos.
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Retorna el objeto de conexión PDO o null si no se pudo conectar.
     */
    public function getConnection(): ?PDO {
        return $this->pdo;
    }

    /**
     * Verifica si la conexión a la base de datos está activa.
     */
    public function isConnected(): bool {
        return $this->pdo !== null;
    }

    /**
     * Proxy de compatibilidad — delega a Config::get().
     * @deprecated Usar Config::get() directamente.
     */
    public function getEnv(string $key, ?string $default = null): ?string {
        return Config::get($key, $default);
    }
}
