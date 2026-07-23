<?php
declare(strict_types=1);

namespace App\Features\Auth\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\Logger;
use App\Services\AuthService;
use Throwable;

/**
 * Acción ADR: GET /api/auth/login
 * Inicia la autenticación con el proveedor OAuth indicado.
 */
class AuthLoginAction {
    public function __invoke(Request $request): void {
        $provider = $request->getQuery('provider', 'Google');
        $redirect = $request->getQuery('redirect', '/');

        setcookie('usgar_auth_redirect', $redirect, [
            'expires'  => time() + 300,
            'path'     => '/',
            'secure'   => Config::isProduction(),
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);

        $config = AuthService::getConfig();

        if (!isset($config['providers'][$provider])) {
            Response::error("El proveedor '{$provider}' no está configurado o sus credenciales están ausentes.", 400);
            return;
        }

        try {
            if (!AuthService::ensureHybridauthLoaded()) {
                throw HttpException::internal("Hybridauth library not found in vendor directory.");
            }

            $hybridauth = new \Hybridauth\Hybridauth($config);
            $hybridauth->authenticate($provider);
            exit(0);

        } catch (Throwable $e) {
            Logger::error("OAuth login failed for provider {$provider}: " . $e->getMessage());
            Response::error("Error al iniciar sesión con {$provider}: " . $e->getMessage(), 500);
        }
    }
}
