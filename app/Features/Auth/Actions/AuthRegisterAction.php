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

        // Split fullName into first_name + last_name if provided as single field
        $rawName = trim($data['first_name'] ?? $data['fullName'] ?? '');
        $lastName = trim($data['last_name'] ?? '');
        if (!empty($rawName) && empty($lastName)) {
            $nameParts = explode(' ', $rawName, 2);
            $rawName = $nameParts[0];
            $lastName = $nameParts[1] ?? '';
        }
        $firstName = $rawName;
        $isHtml = $request->isHtml();

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::errorWithHtmlFallback($isHtml, 'Invalid email address.', 400);
            return;
        }

        if (strlen($password) < 8) {
            Response::errorWithHtmlFallback($isHtml, 'Password must be at least 8 characters.', 400);
            return;
        }

        if (empty($firstName)) {
            Response::errorWithHtmlFallback($isHtml, 'Full name is required.', 400);
            return;
        }

        $pdo = Database::getInstance()->getConnection();
        if ($pdo === null) {
            Response::errorWithHtmlFallback($isHtml, 'Internal database connection error.', 500);
            return;
        }

        $userModel = new User($pdo);
        $userId = $userModel->createFromEmail($email, $password, $firstName, $lastName);

        if ($userId === null) {
            Response::errorWithHtmlFallback($isHtml, 'An account with this email already exists.', 409);
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
            'message' => 'Account created successfully.',
            'user'    => SessionService::toPublicUser($user),
        ], 201);
    }
}
