<?php
declare(strict_types=1);

namespace App\Features\Health\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Config;

/**
 * ENDPOINT TEMPORAL DE DIAGNOSTICO - ELIMINAR DESPUES DE DEPURAR
 * GET /api/webhook-debug
 * Muestra el estado del entorno de webhooks en produccion.
 */
class WebhookDebugAction {
    public function __invoke(Request $request): void {
        $diagnostics = [];

        // 1. Verificar que el SDK esta cargado
        $diagnostics['sdk_loaded'] = class_exists('MercadoPago\Webhook\WebhookSignatureValidator');
        $diagnostics['sdk_config_loaded'] = class_exists('MercadoPago\MercadoPagoConfig');

        // 2. Verificar webhook secret (mostrar solo primeros/ultimos 8 chars)
        $secret = Config::get('MERCADO_PAGO_WEBHOOK_SECRET');
        if ($secret) {
            $diagnostics['webhook_secret'] = substr($secret, 0, 8) . '...' . substr($secret, -8);
            $diagnostics['webhook_secret_length'] = strlen($secret);
        } else {
            $diagnostics['webhook_secret'] = 'NOT_SET';
            $diagnostics['webhook_secret_length'] = 0;
        }

        // 3. Verificar Environment
        $diagnostics['environment_raw'] = Config::get('ENVIRONMENT') ?? 'NOT_SET_ANYWHERE';
        $diagnostics['is_production'] = Config::isProduction();
        $diagnostics['production_source'] = Config::get('ENVIRONMENT') ? 'explicit' : 'auto-detected (public_html)';
        $diagnostics['site_url'] = Config::get('SITE_URL', 'NOT_SET');

        // 4. Verificar tokens (solo tipo, no valor)
        $prodToken = Config::get('MP_PROD_ACCESS_TOKEN');
        $testToken = Config::get('MP_TEST_ACCESS_TOKEN');
        $diagnostics['mp_prod_token_type'] = $prodToken ? (str_starts_with($prodToken, 'APP_USR') ? 'APP_USR (prod)' : 'unknown') : 'NOT_SET';
        $diagnostics['mp_test_token_type'] = $testToken ? (str_starts_with($testToken, 'TEST-') ? 'TEST (test)' : 'unknown') : 'NOT_SET';
        $diagnostics['active_token_type'] = Config::isProduction() ? 'prod' : 'test';

        // 5. Mostrar TODOS los headers HTTP que PHP recibe (para verificar si x-signature llega)
        $httpHeaders = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $httpHeaders[$key] = $value;
            }
        }
        $diagnostics['php_http_headers'] = $httpHeaders;
        $diagnostics['has_http_x_signature'] = isset($_SERVER['HTTP_X_SIGNATURE']) ? 'YES' : 'NO';

        // 6. Verificar rutas del sistema de archivos
        $diagnostics['document_root'] = $_SERVER['DOCUMENT_ROOT'] ?? 'NOT_SET';
        $diagnostics['script_filename'] = $_SERVER['SCRIPT_FILENAME'] ?? 'NOT_SET';
        $diagnostics['dir'] = __DIR__;

        // 7. Verificar vendor/autoload.php paths
        $vendorPaths = [
            'dirname2' => dirname(__DIR__, 4) . '/vendor/autoload.php',
            'docroot' => ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/vendor/autoload.php',
            'docroot_parent' => dirname($_SERVER['DOCUMENT_ROOT'] ?? '') . '/vendor/autoload.php',
        ];
        $diagnostics['vendor_paths'] = [];
        foreach ($vendorPaths as $label => $path) {
            $diagnostics['vendor_paths'][$label] = [
                'path' => $path,
                'exists' => file_exists($path),
            ];
        }

        // 8. Verificar .env paths
        $envPaths = [
            'app_parent2' => dirname(__DIR__, 4) . '/.env',
            'docroot' => ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/.env',
            'docroot_parent' => dirname($_SERVER['DOCUMENT_ROOT'] ?? '') . '/.env',
        ];
        $diagnostics['env_paths'] = [];
        foreach ($envPaths as $label => $path) {
            $diagnostics['env_paths'][$label] = [
                'path' => $path,
                'exists' => file_exists($path),
            ];
        }

        // 9. Self-test HMAC computation
        if ($diagnostics['sdk_loaded'] && $secret) {
            $testDataId = 'test123';
            $testRequestId = 'req456';
            $testTs = '1700000000';
            $manifest = "id:{$testDataId};request-id:{$testRequestId};ts:{$testTs};";
            $hash = hash_hmac('sha256', $manifest, $secret);
            $testSignature = "ts={$testTs},v1={$hash}";

            try {
                \MercadoPago\Webhook\WebhookSignatureValidator::validate(
                    $testSignature,
                    $testRequestId,
                    $testDataId,
                    $secret
                );
                $diagnostics['hmac_self_test'] = 'PASSED';
            } catch (\Throwable $e) {
                $diagnostics['hmac_self_test'] = 'FAILED: ' . $e->getMessage();
            }
        } else {
            $diagnostics['hmac_self_test'] = 'SKIPPED (SDK not loaded or secret missing)';
        }

        // 10. Verificar logs directory
        $logsDir = dirname(__DIR__, 4) . '/logs';
        $diagnostics['logs_dir'] = $logsDir;
        $diagnostics['logs_dir_exists'] = is_dir($logsDir);
        $diagnostics['logs_dir_writable'] = is_writable($logsDir);

        // 11. Check DB connection
        try {
            $db = \App\Core\Database::getInstance();
            $conn = $db->getConnection();
            $diagnostics['db_connected'] = $conn !== null;
        } catch (\Throwable $e) {
            $diagnostics['db_connected'] = false;
            $diagnostics['db_error'] = $e->getMessage();
        }

        // 12. Verificar PHP version
        $diagnostics['php_version'] = PHP_VERSION;
        $diagnostics['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? 'unknown';

        // 13. Query params recibidos
        $diagnostics['query_params'] = $_GET;

        Response::json([
            'success' => true,
            'diagnostics' => $diagnostics,
            'timestamp' => date('Y-m-d H:i:s T'),
            'note' => 'TEMPORAL - Eliminar este endpoint despues de depurar',
        ]);
    }
}
