<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

/**
 * Gestion de conexion a Base de Datos (Singleton PDO).
 * Principio de Responsabilidad Unica: solo maneja la conexion PDO.
 * La carga de .env fue extraida a Config.php.
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
            Logger::warning('Database credentials or database name are not configured. Running in offline/mock/test mode.');
            return;
        }

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            Logger::error('[Database Connection Error] Running in offline/mock/test mode: ' . $e->getMessage());
            $this->pdo = null;
        }
    }

    /**
     * Retorna la instancia unica del gestor de base de datos.
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Retorna el objeto de conexion PDO o null si no se pudo conectar.
     */
    public function getConnection(): ?PDO {
        return $this->pdo;
    }

    /**
     * Verifica si la conexion a la base de datos esta activa.
     */
    public function isConnected(): bool {
        return $this->pdo !== null;
    }
}
