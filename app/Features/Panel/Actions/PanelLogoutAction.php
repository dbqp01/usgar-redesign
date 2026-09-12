<?php
declare(strict_types=1);

namespace App\Features\Panel\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Features\Panel\Domain\PanelAuth;

/**
 * Accion ADR: POST /api/panel/logout
 * Invalida la cookie del panel.
 */
class PanelLogoutAction {
    public function __invoke(Request $request): void {
        PanelAuth::requireAuth();
        PanelAuth::assertCsrf($request);
        $revoked = PanelAuth::revokeCurrentToken();
        if (!$revoked) {
            throw \App\Core\HttpException::internal('No se pudo invalidar la sesión del panel de forma segura.');
        }
        PanelAuth::clearCookie();
        Response::json(['success' => true]);
    }
}
