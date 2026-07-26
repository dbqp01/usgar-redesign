<?php
declare(strict_types=1);

namespace App\Features\Auth\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Features\Auth\AuthService;

/**
 * Accion ADR: GET /api/auth/providers
 * Devuelve la lista de proveedores OAuth habilitados en el sistema (configurados en .env).
 */
class AuthProvidersAction {
    public function __invoke(Request $request): void {
        $providers = AuthService::getEnabledProviders();

        Response::json([
            'success' => true,
            'providers' => $providers
        ]);
    }
}
