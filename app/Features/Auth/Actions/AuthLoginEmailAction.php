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
        $isHtml = $this->isHtmlRequest($request);

        if (empty($email) || empty($password)) {
            if ($isHtml) {
                header('Location: /login?error=' . urlencode('Correo y contraseña son requeridos.'));
                exit(0);
            }
            Response::error("Correo y contraseña son requeridos.", 400);
            return;
        }

        $pdo = Database::getInstance()->getConnection();
        if ($pdo === null) {
            if ($isHtml) {
                header('Location: /login?error=' . urlencode('Error interno de conexión.'));
                exit(0);
            }
            Response::error("Error interno de conexión.", 500);
            return;
        }

        $userModel = new User($pdo);
        $user = $userModel->verifyPassword($email, $password);

        if ($user === null) {
            if ($isHtml) {
                header('Location: /login?error=' . urlencode('Correo o contraseña incorrectos.'));
                exit(0);
            }
            Response::error("Correo o contraseña incorrectos.", 401);
            return;
        }

        if (isset($user['error']) && $user['error'] === 'oauth_only') {
            $provider = ucfirst($user['provider'] ?? 'Google');
            $msg = "Esta cuenta fue registrada usando {$provider}. Por favor presiona 'Continuar con {$provider}' para iniciar sesión.";
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
            'message' => 'Sesión iniciada correctamente.',
            'user'    => [
                'sub'      => $user['id'],
                'name'     => trim($user['first_name'] . ' ' . $user['last_name']),
                'email'    => $user['email'],
                'photo'    => $user['photo_url'] ?? null,
                'provider' => $user['provider'],
            ],
        ]);
    }

    private function isHtmlRequest(Request $request): bool {
        $accept = $request->getHeader('accept') ?? '';
        $requestedWith = $request->getHeader('x-requested-with') ?? '';
        $contentType = $request->getHeader('content-type') ?? '';

        if (str_contains($accept, 'application/json') || strtolower($requestedWith) === 'xmlhttprequest') {
            return false;
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded') || str_contains($contentType, 'multipart/form-data')) {
            return true;
        }

        return str_contains($accept, 'text/html');
    }
}
