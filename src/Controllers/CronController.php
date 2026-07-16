<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Database;
use App\Models\ProvisionalBooking;
use PDO;
use Exception;

/**
 * Controlador de tareas programadas (Cron Jobs).
 * Ejecuta mantenimiento periódico del sistema como liberar habitaciones expiradas.
 */
class CronController {
    private ?PDO $pdo;
    private ?ProvisionalBooking $bookingModel = null;

    public function __construct(?PDO $pdo = null) {
        $db = Database::getInstance();
        $this->pdo = $pdo ?? $db->getConnection();
        
        if ($this->pdo) {
            $this->bookingModel = new ProvisionalBooking($this->pdo);
        }
    }

    /**
     * Endpoint: GET/POST /api/cron/cleanup
     * Libera los bloqueos temporales de habitaciones que han expirado.
     */
    public function cleanup(Request $request): void {
        $isCli = PHP_SAPI === 'cli';
        
        // Si no es CLI, validar la clave de seguridad para evitar accesos no autorizados por URL
        if (!$isCli) {
            $db = Database::getInstance();
            $cronSecret = $db->getEnv('CRON_SECRET', 'usgar_cron_default_secret');
            $providedSecret = $request->getQuery('secret', $request->get('secret', ''));

            if (empty($providedSecret) || $providedSecret !== $cronSecret) {
                Response::forbidden('Acceso denegado. Clave cron inválida.');
            }
        }

        if (!$this->pdo || !$this->bookingModel) {
            $errorMsg = 'Cron Cleanup: Error de conexión a Base de Datos.';
            Logger::error($errorMsg);
            if ($isCli) {
                fwrite(STDERR, $errorMsg . PHP_EOL);
                exit(1);
            }
            Response::error($errorMsg, 500);
        }

        try {
            // Ejecutar limpieza de bloqueos expirados
            $releasedCount = $this->bookingModel->cleanupExpiredHolds();
            
            // Borrar archivos de caché de disponibilidad si existieran
            $cacheDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cache';
            if (is_dir($cacheDir)) {
                $files = glob($cacheDir . DIRECTORY_SEPARATOR . 'avail_*.json');
                if (is_array($files)) {
                    foreach ($files as $file) {
                        @unlink($file);
                    }
                }
            }

            if ($releasedCount > 0) {
                Logger::info("Cron Cleanup: Se liberaron {$releasedCount} bloqueos expirados.");
            }

            $responsePayload = [
                'success' => true,
                'message' => "Limpieza completada con éxito.",
                'released_count' => $releasedCount
            ];

            if ($isCli) {
                echo json_encode($responsePayload, JSON_PRETTY_PRINT) . PHP_EOL;
                exit(0);
            }

            Response::json($responsePayload);

        } catch (Exception $e) {
            Logger::error("Cron Cleanup Exception: " . $e->getMessage());
            if ($isCli) {
                fwrite(STDERR, "Error: " . $e->getMessage() . PHP_EOL);
                exit(1);
            }
            Response::error("Fallo al ejecutar el proceso de limpieza.", 500);
        }
    }
}
