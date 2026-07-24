<?php
declare(strict_types=1);

namespace App\Features\Auth\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Features\Auth\User;
use App\Features\Auth\SessionService;

/**
 * Acción ADR: POST /api/auth/register
 * Registro de usuario mediante Email y Contraseña.
 */
class AuthRegisterAction {
    public function __invoke(Request $request): void {
        $data = $request->getBody() ?? [];
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $firstName = trim($data['first_name'] ?? $data['fullName'] ?? '');
        $lastName = trim($data['last_name'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error("Dirección de correo electrónico no válida.", 400);
            return;
        }

        if (strlen($password) < 8) {
            Response::error("La contraseña debe tener al menos 8 caracteres.", 400);
            return;
        }

        if (empty($firstName)) {
            Response::error("El nombre es requerido.", 400);
            return;
        }

        $pdo = Database::getInstance()->getConnection();
        if ($pdo === null) {
            Response::error("Error interno de conexión a la base de datos.", 500);
            return;
        }

        $userModel = new User($pdo);
        $userId = $userModel->createFromEmail($email, $password, $firstName, $lastName);

        if ($userId === null) {
            Response::error("Ya existe una cuenta registrada con este correo electrónico.", 409);
            return;
        }

        $user = $userModel->findById($userId);
        $jwt = SessionService::createToken($user);
        SessionService::setAuthCookie($jwt);

        Response::json([
            'success' => true,
            'message' => 'Cuenta creada exitosamente.',
            'user'    => [
                'sub'      => $user['id'],
                'name'     => trim($user['first_name'] . ' ' . $user['last_name']),
                'email'    => $user['email'],
                'photo'    => $user['photo_url'] ?? null,
                'provider' => $user['provider'],
            ],
        ], 201);
    }
}
