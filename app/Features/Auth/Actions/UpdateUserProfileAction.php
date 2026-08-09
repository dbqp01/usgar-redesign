<?php
declare(strict_types=1);

namespace App\Features\Auth\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Features\Auth\User;
use App\Features\Auth\SessionService;

/**
 * Accion ADR: POST /api/user/profile
 * Actualiza la informacion personal del usuario autenticado.
 */
class UpdateUserProfileAction {
    public function __invoke(Request $request): void {
        $sessionUser = SessionService::getUserFromRequest();

        if ($sessionUser === null) {
            Response::unauthorized("No active session found.");
            return;
        }

        $data = $request->getBody() ?? [];
        $rawName = trim($data['fullName'] ?? $data['first_name'] ?? '');
        $lastName = trim($data['last_name'] ?? '');
        $phone = trim($data['phone'] ?? '');

        if (!empty($rawName) && empty($lastName)) {
            $nameParts = explode(' ', $rawName, 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? '';
        } else {
            $firstName = $rawName;
        }

        if (empty($firstName)) {
            Response::badRequest("First name is required.");
            return;
        }

        $pdo = Database::getInstance()->getConnection();
        if ($pdo === null) {
            Response::error("Database connection failed.", 500);
            return;
        }

        $userModel = new User($pdo);
        $userId = (int) $sessionUser['sub'];

        $updated = $userModel->updateProfile($userId, $firstName, $lastName, !empty($phone) ? $phone : null);

        if (!$updated) {
            Response::error("Failed to update profile.", 500);
            return;
        }

        $freshUser = $userModel->findById($userId);
        
        if (!$freshUser) {
            Response::error("User not found after update.", 404);
            return;
        }

        $jwt = SessionService::createToken($freshUser);
        SessionService::setAuthCookie($jwt);

        Response::json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'user'    => SessionService::toPublicUser($freshUser, true),
        ]);
    }
}
