<?php
declare(strict_types=1);

namespace App\Features\Auth\Actions;

use App\Core\Request;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Features\Auth\User;
use App\Features\Auth\AuthService;
use App\Features\Auth\SessionService;
use Throwable;

/**
 * Accion ADR: GET /api/auth/callback
 * Procesa el retorno del proveedor OAuth y crea la sesion del usuario.
 */
class AuthCallbackAction {
    public function __invoke(Request $request): void {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => \App\Core\Config::isProduction(),
                'httponly'  => true,
                'samesite' => 'Lax'
            ]);
            @session_start();
        }

        $config = AuthService::getConfig();

        try {
            if (!AuthService::ensureHybridauthLoaded()) {
                throw HttpException::internal("Hybridauth library not found.");
            }

            $hybridauth = new \Hybridauth\Hybridauth($config);
            $storage = new \Hybridauth\Storage\Session();

            $provider = $storage->get('provider')
                ?? $_SESSION['usgar_oauth_provider']
                ?? $_COOKIE['usgar_auth_provider']
                ?? $request->getQuery('provider')
                ?? 'Google';

            // CRÍTICO: Ejecutar handshake de autenticación (intercambia ?code= por access_token)
            $adapter = $hybridauth->authenticate($provider);
            $profile = $adapter->getUserProfile();

            if (empty($profile->email)) {
                throw HttpException::badRequest("El proveedor no retornó un correo electrónico válido.");
            }

            $pdo = Database::getInstance()->getConnection();
            if ($pdo === null) {
                throw HttpException::internal("Database connection failed.");
            }

            $userModel = new User($pdo);
            $normalized = AuthService::normalizeProfile($profile, $provider);
            $userId = $userModel->createFromOAuth($normalized);

            if ($userId === null) {
                throw HttpException::internal("No se pudo crear o actualizar la cuenta de usuario.");
            }

            $user = $userModel->findById($userId);
            $jwt = SessionService::createToken($user);
            SessionService::setAuthCookie($jwt);

            $redirect = $_COOKIE['usgar_auth_redirect'] ?? '/profile';
            setcookie('usgar_auth_redirect', '', time() - 3600, '/');

            if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//') || $redirect === '/') {
                $redirect = '/profile';
            }

            header('Location: ' . $redirect);
            exit(0);

        } catch (Throwable $e) {
            Logger::error("OAuth callback failed: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            $detail = $e->getMessage();
            header('Location: /login?error=' . urlencode("Error en autenticación (" . $detail . ")"));
            exit(0);
        }
    }
}
