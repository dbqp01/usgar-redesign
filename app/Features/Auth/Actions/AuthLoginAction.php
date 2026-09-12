<?php
declare(strict_types=1);

namespace App\Features\Auth\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\Logger;
use App\Features\Auth\AuthService;
use App\Features\Auth\AuthRedirect;
use Throwable;

/**
 * Accion ADR: GET /api/auth/login
 * Inicia la autenticacion con el proveedor OAuth indicado.
 */
class AuthLoginAction {
    public function __invoke(Request $request): void {
        $isSecure = Request::isHttps();
        Request::startSession($isSecure);

        $provider = $request->getQuery('provider', 'Google');
        $redirect = AuthRedirect::safePath($request->getQuery('redirect', '/profile'), '/profile');

        $_SESSION['usgar_oauth_provider'] = $provider;

        setcookie('usgar_auth_provider', $provider, [
            'expires'  => time() + 300,
            'path'     => '/',
            'secure'   => $isSecure,
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);

        setcookie('usgar_auth_redirect', $redirect, [
            'expires'  => time() + 300,
            'path'     => '/',
            'secure'   => $isSecure,
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
            // Keep SDK/configuration details in server logs; OAuth endpoints are public.
            Response::error('No se pudo iniciar sesión. Intenta de nuevo.', 500);
        }
    }
}
