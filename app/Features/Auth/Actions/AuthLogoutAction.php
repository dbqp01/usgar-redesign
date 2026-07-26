<?php
declare(strict_types=1);

namespace App\Features\Auth\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Features\Auth\SessionService;

/**
 * Accion ADR: POST /api/auth/logout
 * Cierra la sesion activa expirando la cookie JWT.
 */
class AuthLogoutAction {
    public function __invoke(Request $request): void {
        SessionService::clearAuthCookie();

        $accept = $request->getHeader('accept') ?? '';
        $requestedWith = $request->getHeader('x-requested-with') ?? '';

        if (str_contains($accept, 'application/json') || strtolower($requestedWith) === 'xmlhttprequest') {
            Response::json([
                'success' => true,
                'message' => 'Sesión cerrada correctamente.',
            ]);
            return;
        }

        header('Location: /login');
        exit(0);
    }
}
