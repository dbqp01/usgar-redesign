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
        PanelAuth::clearCookie();
        Response::json(['success' => true]);
    }
}
