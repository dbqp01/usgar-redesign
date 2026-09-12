<?php
declare(strict_types=1);

namespace App\Features\Auth\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Features\Auth\AuthRedirect;
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
        $emailInput = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $isHtml = $request->isHtml();

        if (!is_string($emailInput) || !is_string($password)) {
            Response::errorWithHtmlFallback($isHtml, 'Email and password are required.', 400);
            return;
        }

        $email = trim($emailInput);

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

        if (isset($user['error'])) {
            $provider = ucfirst($user['provider']);
            $msg = "This account was registered with {$provider}. Please use 'Continue with {$provider}' to sign in.";
            if ($isHtml) {
                header('Location: /login?error=' . urlencode($msg));
                exit(0);
            }
            Response::json(self::oauthAccountPayload($provider, $msg), 400);
            return;
        }

        $jwt = SessionService::createToken($user);
        SessionService::setAuthCookie($jwt);

        if ($isHtml) {
            $redirect = AuthRedirect::safePath($data['redirect'] ?? '/my-bookings', '/my-bookings');
            header('Location: ' . $redirect);
            exit(0);
        }

        Response::json([
            'success' => true,
            'message' => 'Signed in successfully.',
            'user'    => SessionService::toPublicUser($user),
        ]);
    }

    /** @return array<string, mixed> */
    protected static function oauthAccountPayload(string $provider, string $message): array {
        return [
            'success' => false,
            'error' => [
                'code' => 'OAUTH_ACCOUNT',
                'message' => $message,
            ],
            // Kept at the top level for existing frontend clients.
            'isOAuth' => true,
            'provider' => $provider,
            'message' => $message,
        ];
    }
}
