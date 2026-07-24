<?php
declare(strict_types=1);

namespace App\Features\Auth\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Features\Auth\SessionService;

/**
 * Acción ADR: GET /api/auth/me
 * Retorna la información del usuario autenticado actualmente.
 */
class AuthMeAction {
    public function __invoke(Request $request): void {
        $user = SessionService::getUserFromRequest();

        if ($user === null) {
            Response::json([
                'success' => false,
                'user'    => null,
            ], 200);
            return;
        }

        Response::json([
            'success' => true,
            'user'    => $user,
        ]);
    }
}
