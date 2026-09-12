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
 * Retorna el estado de salud del sistema y la conexion a BD.
 *
 * 2026-08-15: eliminado el bloque env_diag (document_root, ruta del .env,
 * prefijos de tokens) — dependia de APP_ENV, que se elimino de raiz. El
 * default seguro es NO exponer informacion de entorno por HTTP publico.
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

        // This endpoint is a liveness probe: PHP/router are alive even when
        // the optional database dependency is unavailable. Keep HTTP 200 so
        // Hostinger monitors do not restart a live process, but expose the
        // dependency state accurately for operators/readiness checks.
        $payload = [
            'success'   => true,
            'status'    => $dbStatus === 'online' ? 'healthy' : 'degraded',
            'database'  => $dbStatus,
            'timestamp' => date('c'),
        ];

        Response::json($payload);
    }
}
