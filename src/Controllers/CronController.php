<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Models\ProvisionalBooking;
use PDO;
use Exception;

/**
 * Controlador de tareas programadas (Cron Jobs).
 * Ejecuta mantenimiento periódico como liberar habitaciones expiradas.
 * Fix: Rechaza cron secret por defecto en producción.
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

        // Si no es CLI, validar la clave de seguridad
        if (!$isCli) {
            $cronSecret = Config::get('CRON_SECRET', '');
            $providedSecret = $request->getQuery('secret', $request->get('secret', ''));

            // En producción, rechazar si no hay secret o si es el valor por defecto
            if (empty($cronSecret)) {
                if (Config::isProduction()) {
                    throw HttpException::forbidden('CRON_SECRET no configurado en producción.');
                }
                // En desarrollo, permitir sin secret
            } else {
                if (empty($providedSecret) || !hash_equals($cronSecret, $providedSecret)) {
                    throw HttpException::forbidden('Acceso denegado. Clave cron inválida.');
                }
            }
        }

        if (!$this->pdo || !$this->bookingModel) {
            $errorMsg = 'Cron Cleanup: Error de conexión a Base de Datos.';
            Logger::error($errorMsg);
            if ($isCli) {
                fwrite(STDERR, $errorMsg . PHP_EOL);
                exit(1);
            }
            throw HttpException::internal($errorMsg);
        }

        try {
            // Ejecutar limpieza de bloqueos expirados (UPDATE a 'expired', no DELETE)
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
                Logger::info("Cron Cleanup: Se expiraron {$releasedCount} bloqueos.");
            }

            $responsePayload = [
                'success'        => true,
                'message'        => 'Limpieza completada con éxito.',
                'released_count' => $releasedCount,
            ];

            if ($isCli) {
                echo json_encode($responsePayload, JSON_PRETTY_PRINT) . PHP_EOL;
                exit(0);
            }

            Response::json($responsePayload);

        } catch (HttpException $e) {
            throw $e;
        } catch (Exception $e) {
            Logger::error('Cron Cleanup Exception: ' . $e->getMessage());
            if ($isCli) {
                fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
                exit(1);
            }
            throw HttpException::internal('Fallo al ejecutar el proceso de limpieza.');
        }
    }
}
