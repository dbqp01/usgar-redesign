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
        $isHtml = $this->isHtmlRequest($request);

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if ($isHtml) {
                header('Location: /login?error=' . urlencode('Invalid email address.'));
                exit(0);
            }
            Response::error("Invalid email address.", 400);
            return;
        }

        if (strlen($password) < 8) {
            if ($isHtml) {
                header('Location: /login?error=' . urlencode('Password must be at least 8 characters.'));
                exit(0);
            }
            Response::error("Password must be at least 8 characters.", 400);
            return;
        }

        if (empty($firstName)) {
            if ($isHtml) {
                header('Location: /login?error=' . urlencode('Full name is required.'));
                exit(0);
            }
            Response::error("Full name is required.", 400);
            return;
        }

        $pdo = Database::getInstance()->getConnection();
        if ($pdo === null) {
            if ($isHtml) {
                header('Location: /login?error=' . urlencode('Internal database connection error.'));
                exit(0);
            }
            Response::error("Internal database connection error.", 500);
            return;
        }

        $userModel = new User($pdo);
        $userId = $userModel->createFromEmail($email, $password, $firstName, $lastName);

        if ($userId === null) {
            if ($isHtml) {
                header('Location: /login?error=' . urlencode('An account with this email already exists.'));
                exit(0);
            }
            Response::error("An account with this email already exists.", 409);
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
