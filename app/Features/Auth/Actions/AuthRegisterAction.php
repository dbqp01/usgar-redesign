<?php
declare(strict_types=1);

namespace App\Features\Auth\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Features\Auth\AuthRedirect;
use App\Features\Auth\User;
use App\Features\Auth\SessionService;

/**
 * Accion ADR: POST /api/auth/register
 * Registro de usuario mediante Email y Contrasena.
 */
class AuthRegisterAction {
    public function __invoke(Request $request): void {
        $data = $request->getBody() ?? [];
        $emailInput = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $rawNameInput = $data['first_name'] ?? $data['fullName'] ?? '';
        $lastNameInput = $data['last_name'] ?? '';
        $isHtml = $request->isHtml();

        if (!is_string($emailInput) || !is_string($password)
            || !is_string($rawNameInput) || !is_string($lastNameInput)
        ) {
            Response::errorWithHtmlFallback($isHtml, 'Invalid registration data.', 400);
            return;
        }

        $email = trim($emailInput);

        // Split fullName into first_name + last_name if provided as single field
        $rawName = trim($rawNameInput);
        $lastName = trim($lastNameInput);
        if (!empty($rawName) && empty($lastName)) {
            $nameParts = explode(' ', $rawName, 2);
            $rawName = $nameParts[0];
            $lastName = $nameParts[1] ?? '';
        }
        $firstName = $rawName;
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
        if ($user === null) {
            Response::error('No se pudo recuperar la cuenta de usuario.', 500);
            return;
        }
        $jwt = SessionService::createToken($user);
        SessionService::setAuthCookie($jwt);

        if ($isHtml) {
            $redirect = AuthRedirect::safePath($data['redirect'] ?? '/profile', '/profile');
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
