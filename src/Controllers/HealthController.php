<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Config;
use App\Core\Database;

/**
 * Controlador de Health Check.
 * Endpoint de monitoreo para verificar estado del sistema en producción.
 */
class HealthController {
    /**
     * Endpoint: GET /api/health
     * Retorna estado de base de datos, versión PHP, timestamp y environment.
     */
    public function index(Request $request): void {
        $db = Database::getInstance();
        $pdo = $db->getConnection();

        $dbStatus = 'disconnected';
        if ($pdo !== null) {
            try {
                $pdo->query('SELECT 1');
                $dbStatus = 'connected';
            } catch (\PDOException $e) {
                $dbStatus = 'error';
            }
        }

        Response::json([
            'success'     => true,
            'status'      => $dbStatus === 'connected' ? 'healthy' : 'degraded',
            'environment' => Config::get('ENVIRONMENT', 'development'),
            'php_version' => PHP_VERSION,
            'database'    => $dbStatus,
            'timestamp'   => date('c'),
        ]);
    }
}
