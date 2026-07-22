<?php
declare(strict_types=1);

namespace App\Features\Auth\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Services\SessionService;

/**
 * Acción ADR: POST /api/auth/logout
 * Cierra la sesión activa expirando la cookie JWT.
 */
class AuthLogoutAction {
    public function __invoke(Request $request): void {
        SessionService::clearAuthCookie();
        Response::json([
            'success' => true,
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }
}
