<?php
declare(strict_types=1);

namespace App\Features\Auth\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Features\Auth\User;
use App\Features\Auth\SessionService;

/**
 * Accion ADR: POST /api/auth/login-email
 * Inicio de sesion tradicional con email y contrasena.
 */
class AuthLoginEmailAction {
    public function __invoke(Request $request): void {
        $data = $request->getBody() ?? [];
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $isHtml = $request->isHtml();

        if (empty($email) || empty($password)) {
            Response::errorWithHtmlFallback($isHtml, 'Email and password are required.', 400);
            return;
        }

        $pdo = Database::getInstance()->getConnection();
        if ($pdo === null) {
            Response::errorWithHtmlFallback($isHtml, 'Internal connection error.', 500);
            return;
        }

        $userModel = new User($pdo);
        $user = $userModel->verifyPassword($email, $password);

        if ($user === null) {
            Response::errorWithHtmlFallback($isHtml, 'Invalid email or password.', 401);
            return;
        }

        if (isset($user['error']) && $user['error'] === 'oauth_only') {
            $provider = ucfirst($user['provider'] ?? 'Google');
            $msg = "This account was registered with {$provider}. Please use 'Continue with {$provider}' to sign in.";
            if ($isHtml) {
                header('Location: /login?error=' . urlencode($msg));
                exit(0);
            }
            Response::json([
                'success' => false,
                'isOAuth' => true,
                'provider' => $provider,
                'message' => $msg,
            ], 400);
            return;
        }

        $jwt = SessionService::createToken($user);
        SessionService::setAuthCookie($jwt);

        if ($isHtml) {
            $redirect = trim((string)($data['redirect'] ?? '/my-bookings'));
            if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
                $redirect = '/my-bookings';
            }
            header('Location: ' . $redirect);
            exit(0);
        }

        Response::json([
            'success' => true,
            'message' => 'Signed in successfully.',
            'user'    => SessionService::toPublicUser($user),
        ]);
    }
}
