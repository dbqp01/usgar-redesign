<?php
declare(strict_types=1);

namespace App\Features\Health\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Config;
use App\Core\Database;
use Exception;

/**
 * Accion ADR: GET /api/health
 * Retorna el estado de salud del sistema, conexion a BD y entorno.
 */
class HealthCheckAction {
    public function __invoke(Request $request): void {
        $dbStatus = 'offline';
        try {
            $pdo = Database::getInstance()->getConnection();
            if ($pdo) {
                $dbStatus = 'online';
            }
        } catch (Exception $e) {
            $dbStatus = 'error';
        }

        Response::json([
            'success'     => true,
            'status'      => 'healthy',
            'environment' => Config::isProduction() ? 'production' : 'development',
            'database'    => $dbStatus,
            'timestamp'   => date('c'),
        ]);
    }
}
