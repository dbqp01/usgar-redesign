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

        // Diagnostico de config SOLO fuera de produccion (fix P1-3 2026-08-12):
        // en APP_ENV=production no se exponen document_root, la ruta del .env
        // ni prefijos de tokens — informacion util para el reconocimiento.
        $isProduction = Config::get('APP_ENV', 'production') === 'production';

        $payload = [
            'success'   => true,
            'status'    => 'healthy',
            'database'  => $dbStatus,
            'timestamp' => date('c'),
        ];

        if (!$isProduction) {
            $accessToken = Config::get('MERCADO_PAGO_ACCESS_TOKEN', '');
            $publicKey = Config::get('PUBLIC_MERCADO_PAGO_PUBLIC_KEY', '');

            $payload['env_diag'] = [
                'document_root'   => $_SERVER['DOCUMENT_ROOT'] ?? '(empty)',
                'env_loaded_path' => Config::loadedEnvPath() ?? '(none)',
                'token_prefix'    => $accessToken ? substr($accessToken, 0, 8) . '...' : '(not set)',
                'google_client_prefix' => substr(Config::get('GOOGLE_CLIENT_ID', ''), 0, 24) ?: '(not set)',
                'key_prefix'      => $publicKey ? substr($publicKey, 0, 8) . '...' : '(not set)',
                'is_test_mode'    => str_starts_with($accessToken, 'TEST-'),
            ];
        }

        Response::json($payload);
    }
}

