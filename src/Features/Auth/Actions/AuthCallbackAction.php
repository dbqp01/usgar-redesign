<?php
declare(strict_types=1);

namespace App\Features\Auth\Actions;

use App\Core\Request;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Models\User;
use App\Services\AuthService;
use App\Services\SessionService;
use Throwable;

/**
 * Acción ADR: GET /api/auth/callback
 * Procesa el retorno del proveedor OAuth y crea la sesión del usuario.
 */
class AuthCallbackAction {
    public function __invoke(Request $request): void {
        $config = AuthService::getConfig();

        try {
            $vendorAutoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
            if (file_exists($vendorAutoload)) {
                require_once $vendorAutoload;
            }
            $hybridAuthAutoload = dirname(__DIR__, 4) . '/vendor/hybridauth/autoload.php';
            if (file_exists($hybridAuthAutoload)) {
                require_once $hybridAuthAutoload;
            }

            if (!class_exists(\Hybridauth\Hybridauth::class)) {
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
