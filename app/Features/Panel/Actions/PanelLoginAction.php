<?php
declare(strict_types=1);

namespace App\Features\Panel\Actions;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\RateLimiter;
use App\Features\Panel\Domain\PanelAuth;

/**
 * Accion ADR: POST /api/panel/login
 * Valida la password del dueno (PANEL_PASSWORD, .env) y emite la cookie del
 * panel. Fail-closed: si PANEL_PASSWORD no esta configurada, nadie entra.
 */
class PanelLoginAction {
    private const LOGIN_MAX_ATTEMPTS = 5;
    private const LOGIN_WINDOW_SECONDS = 900;

    public function __invoke(Request $request): void {
        $body = $request->getBody() ?? [];
        $password = (string)($body['password'] ?? '');

        if (!RateLimiter::check(
            'panel-login|' . $request->getIp(),
            self::LOGIN_MAX_ATTEMPTS,
            self::LOGIN_WINDOW_SECONDS
        )) {
            throw HttpException::tooManyRequests('Too many panel login attempts. Try again in 15 minutes.');
        }

        $expected = Config::get('PANEL_PASSWORD');
        if ($expected === null || $expected === '' || $password === '' || !hash_equals($expected, $password)) {
            throw HttpException::unauthorized('Password invalida.');
        }

        PanelAuth::setCookie();
        Response::json(['success' => true, 'expires_in' => 12 * 3600]);
    }
}
