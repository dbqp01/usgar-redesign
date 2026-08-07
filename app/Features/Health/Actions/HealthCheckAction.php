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

        // Diagnostico de config: SOLO prefijos, nunca secretos completos.
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '(empty)';
        $accessToken = Config::get('MERCADO_PAGO_ACCESS_TOKEN', '');
        $publicKey = Config::get('PUBLIC_MERCADO_PAGO_PUBLIC_KEY', '');
        $tokenPrefix = $accessToken ? substr($accessToken, 0, 8) . '...' : '(not set)';
        $keyPrefix = $publicKey ? substr($publicKey, 0, 8) . '...' : '(not set)';

        // Reproducir la logica de Config::loadEnv para mostrar la ruta exacta
        $envPath = '(unknown)';
        if (str_contains($docRoot, 'public_html')) {
            $p = dirname($docRoot);
            while (str_contains($p, 'public_html') && dirname($p) !== $p) {
                $p = dirname($p);
            }
            $envPath = $p . DIRECTORY_SEPARATOR . '.env';
        } else {
            $envPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.env';
        }

        Response::json([
            'success'   => true,
            'status'    => 'healthy',
            'database'  => $dbStatus,
            'timestamp' => date('c'),
            'env_diag'  => [
                'document_root'     => $docRoot,
                'env_path_resolved' => $envPath,
                'env_file_exists'   => file_exists($envPath),
                'token_prefix'      => $tokenPrefix,
                'key_prefix'        => $keyPrefix,
                'is_test_mode'      => str_starts_with($accessToken, 'TEST-'),
            ],
        ]);
    }
}

