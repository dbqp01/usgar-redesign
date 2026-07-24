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
            @session_start();
        }

        $config = AuthService::getConfig();

        try {
            if (!AuthService::ensureHybridauthLoaded()) {
                throw HttpException::internal("Hybridauth library not found.");
            }

            $hybridauth = new \Hybridauth\Hybridauth($config);
            $storage = new \Hybridauth\Storage\Session();

            $provider = $storage->get('provider') ?? 'Google';
            $adapter = $hybridauth->getAdapter($provider);
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

            $redirect = $_COOKIE['usgar_auth_redirect'] ?? '/';
            setcookie('usgar_auth_redirect', '', time() - 3600, '/');

            if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
                $redirect = '/';
            }

            header('Location: ' . $redirect);
            exit(0);

        } catch (Throwable $e) {
            Logger::error("OAuth callback failed: " . $e->getMessage());
            header('Location: /login?error=' . urlencode("No se pudo completar la autenticación. Intente nuevamente."));
            exit(0);
        }
    }
}
