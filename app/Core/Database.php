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

        $hostsToTry = array_unique(array_filter([
            $host,
            '127.0.0.1',
            'localhost'
        ]));

        foreach ($hostsToTry as $currentHost) {
            try {
                $dsn = "mysql:host={$currentHost};port={$port};dbname={$name};charset=utf8mb4";
                $this->pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    // Timeout de conexión: sin esto, con la BD caída cada intento
                    // espera el timeout TCP del OS (~21s+) x 3 hosts => requests
                    // colgados y suites de test que parecen no terminar (P3-2).
                    PDO::ATTR_TIMEOUT            => 3,
                ]);
                return; // Conexion exitosa
            } catch (PDOException $e) {
                Logger::warning("[Database Connection Attempt Failed on host {$currentHost}]: " . $e->getMessage());
                $this->pdo = null;
            }
        }

        Logger::error('[Database Connection Error] Could not connect to any DB host. Running in offline mode.');
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
}
