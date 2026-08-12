<?php
declare(strict_types=1);

namespace App\Features\Auth\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Features\Auth\User;
use App\Features\Auth\SessionService;

/**
 * Accion ADR: POST /api/auth/login-email
 * Inicio de sesion tradicional con email y contrasena.
 */
class AuthLoginEmailAction {
    /** Limite de fuerza bruta dirigida: 5 intentos / 15 min por email+IP (P1-5). */
    private const LOGIN_MAX_ATTEMPTS = 5;
    private const LOGIN_WINDOW_SECONDS = 900;

    public function __invoke(Request $request): void {
        $data = $request->getBody() ?? [];
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $isHtml = $request->isHtml();

        if (empty($email) || empty($password)) {
            Response::errorWithHtmlFallback($isHtml, 'Email and password are required.', 400);
            return;
        }

        // Rate limit por email+IP (el global 300/600s no frena fuerza bruta
        // dirigida a una cuenta): la clave compuesta se hashea en RateLimiter.
        $ip = $request->getIp();
        if (!RateLimiter::check(strtolower($email) . '|' . $ip, self::LOGIN_MAX_ATTEMPTS, self::LOGIN_WINDOW_SECONDS)) {
            Response::errorWithHtmlFallback($isHtml, 'Too many login attempts. Try again in 15 minutes.', 429);
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
