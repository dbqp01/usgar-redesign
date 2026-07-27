<?php
declare(strict_types=1);

namespace App\Features\Auth\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Features\Auth\User;
use App\Features\Auth\SessionService;

/**
 * Accion ADR: POST /api/auth/register
 * Registro de usuario mediante Email y Contrasena.
 */
class AuthRegisterAction {
    public function __invoke(Request $request): void {
        $data = $request->getBody() ?? [];
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $firstName = trim($data['first_name'] ?? $data['fullName'] ?? '');
        $lastName = trim($data['last_name'] ?? '');
        $isHtml = $this->isHtmlRequest($request);

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if ($isHtml) {
                header('Location: /login?error=' . urlencode('Dirección de correo electrónico no válida.'));
                exit(0);
            }
            Response::error("Dirección de correo electrónico no válida.", 400);
            return;
        }

        if (strlen($password) < 8) {
            if ($isHtml) {
                header('Location: /login?error=' . urlencode('La contraseña debe tener al menos 8 caracteres.'));
                exit(0);
            }
            Response::error("La contraseña debe tener al menos 8 caracteres.", 400);
            return;
        }

        if (empty($firstName)) {
            if ($isHtml) {
                header('Location: /login?error=' . urlencode('El nombre es requerido.'));
                exit(0);
            }
            Response::error("El nombre es requerido.", 400);
            return;
        }

        $pdo = Database::getInstance()->getConnection();
        if ($pdo === null) {
            if ($isHtml) {
                header('Location: /login?error=' . urlencode('Error interno de conexión a la base de datos.'));
                exit(0);
            }
            Response::error("Error interno de conexión a la base de datos.", 500);
            return;
        }

        $userModel = new User($pdo);
        $userId = $userModel->createFromEmail($email, $password, $firstName, $lastName);

        if ($userId === null) {
            if ($isHtml) {
                header('Location: /login?error=' . urlencode('Ya existe una cuenta registrada con este correo electrónico.'));
                exit(0);
            }
            Response::error("Ya existe una cuenta registrada con este correo electrónico.", 409);
            return;
        }

        $user = $userModel->findById($userId);
        $jwt = SessionService::createToken($user);
        SessionService::setAuthCookie($jwt);

        if ($isHtml) {
            $redirect = trim((string)($data['redirect'] ?? '/profile'));
            if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
                $redirect = '/profile';
            }
            header('Location: ' . $redirect);
            exit(0);
        }

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
