<?php
declare(strict_types=1);

namespace App\Features\Auth\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Features\Auth\User;
use App\Features\Auth\SessionService;

/**
 * Acción ADR: POST /api/auth/login-email
 * Inicio de sesión tradicional con email y contraseña.
 */
class AuthLoginEmailAction {
    public function __invoke(Request $request): void {
        $data = $request->getBody() ?? [];
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            Response::error("Correo y contraseña son requeridos.", 400);
            return;
        }

        $pdo = Database::getInstance()->getConnection();
        if ($pdo === null) {
            Response::error("Error interno de conexión.", 500);
            return;
        }

        $userModel = new User($pdo);
        $user = $userModel->verifyPassword($email, $password);

        if ($user === null) {
            Response::error("Correo o contraseña incorrectos.", 401);
            return;
        }

        $jwt = SessionService::createToken($user);
        SessionService::setAuthCookie($jwt);

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
}
