<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

/**
 * Clase de conexión y gestión de Base de Datos.
 * Implementa el patrón Singleton para la conexión PDO y provee utilidades de entorno.
 */
class Database {
    private static ?Database $instance = null;
    private ?PDO $pdo = null;
    private array $envCache = [];

    private function __construct() {
        $this->loadEnv();

        $host = $this->getEnv('DB_HOST', 'localhost');
        $port = $this->getEnv('DB_PORT', '3306');
        $user = $this->getEnv('DB_USER');
        $pass = $this->getEnv('DB_PASS');
        $name = $this->getEnv('DB_NAME');

        if (empty($user) || empty($name)) {
            // Sin credenciales configuradas (útil para pruebas o mocks)
            return;
        }

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false // Deshabilitar emulación para tipado nativo
            ]);
        } catch (PDOException $e) {
            error_log("[Database Connection Error] " . $e->getMessage());
            $this->pdo = null;
        }
    }

    /**
     * Retorna la instancia única del gestor de base de datos.
     */
    public static function getInstance(): Database {
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
     * Obtiene una variable de entorno cargada desde el archivo .env.
     */
    public function getEnv(string $key, ?string $default = null): ?string {
        return $this->envCache[$key] ?? (getenv($key) ?: $default);
    }

    /**
     * Parsea de manera segura el archivo .env en la raíz del proyecto.
     * Compatible con las restricciones de seguridad de Hostinger.
     */
    private function loadEnv(): void {
        // Buscar el archivo .env en el directorio raíz del proyecto (usgar-redesign/)
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
        
        if (file_exists($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                
                // Ignorar comentarios y líneas vacías
                if (empty($line) || str_starts_with($line, '#')) {
                    continue;
                }

                // Separar por el primer carácter '='
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    // Quitar comillas si están presentes
                    $value = trim($parts[1], " '\"");
                    $this->envCache[$key] = $value;
                    
                    // Opcionalmente guardar en getenv si está permitido
                    putenv("{$key}={$value}");
                }
            }
        }
    }
}
