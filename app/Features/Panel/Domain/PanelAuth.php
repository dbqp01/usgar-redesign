<?php
declare(strict_types=1);

namespace App\Features\Panel\Domain;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\TokenRevocationStore;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

/**
 * Autenticacion del panel de disponibilidad del dueno (cookie propia,
 * independiente del login de huespedes). JWT HMAC-SHA256 firmado con el mismo
 * secret que SessionService, con claims role/iss/aud/jti y cookie separada
 * (usgar_panel). TTL 12h. No toca la zona de Auth del sitio.
 *
 * Migrado a firebase/php-jwt (2026-08-10) — misma libreria que SessionService;
 * la implementacion casera anterior (b64/unb64 + validacion manual) queda
 * eliminada. Los tokens emitidos antes siguen siendo validos (mismo
 * secret/alg HS256).
 */
class PanelAuth {
    private const COOKIE_NAME = 'usgar_panel';
    private const TTL = 12 * 3600;
    private const ALG = 'HS256';
    private const AUDIENCE = 'usgar-panel';
    private const CSRF_COOKIE = 'usgar_panel_csrf';

    public static function issueToken(): string {
        $now = time();
        return JWT::encode([
            'role' => 'panel',
            'iat'  => $now,
            'exp'  => $now + self::TTL,
            'iss'  => Config::get('SITE_URL', 'https://usgarhoteles.com'),
            'aud'  => self::AUDIENCE,
            'jti'  => bin2hex(random_bytes(16)),
        ], self::secret(), self::ALG);
    }

    public static function csrfToken(): string {
        return bin2hex(random_bytes(16));
    }

    public static function assertCsrf(Request $request): void {
        // Panel mutations are JSON/AJAX endpoints. Do not infer an HTML
        // exemption from Accept or Content-Type: an attacker can forge those
        // headers while the panel cookie is sent automatically.
        $cookie = (string)($_COOKIE[self::CSRF_COOKIE] ?? '');
        $header = (string)($request->getHeader('x-csrf-token') ?? '');
        if ($cookie === '' || $header === '' || !hash_equals($cookie, $header)) {
            throw HttpException::forbidden('CSRF token mismatch.');
        }
    }

    public static function isAuthenticated(): bool {
        $jwt = $_COOKIE[self::COOKIE_NAME] ?? '';
        if ($jwt === '') {
            return false;
        }
        try {
            $decoded = JWT::decode($jwt, new Key(self::secret(), self::ALG));
            return ($decoded->role ?? null) === 'panel'
                && ($decoded->iss ?? null) === Config::get('SITE_URL', 'https://usgarhoteles.com')
                && ($decoded->aud ?? null) === self::AUDIENCE
                && is_string($decoded->jti ?? null)
                && $decoded->jti !== ''
                && !TokenRevocationStore::isRevoked($decoded->jti, 'panel');
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function requireAuth(): void {
        if (!self::isAuthenticated()) {
            throw HttpException::unauthorized('Acceso restringido al panel.');
        }
    }

    public static function setCookie(): void {
        $isSecure = Request::isHttps();
        setcookie(self::COOKIE_NAME, self::issueToken(), [
            'expires'  => time() + self::TTL,
            'path'     => '/',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        setcookie(self::CSRF_COOKIE, self::csrfToken(), [
            'expires' => time() + self::TTL,
            'path' => '/', 'secure' => $isSecure,
            'httponly' => false, 'samesite' => 'Lax',
        ]);
    }

    public static function clearCookie(): void {
        $isSecure = Request::isHttps();
        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        setcookie(self::CSRF_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/', 'secure' => $isSecure,
            'httponly' => false, 'samesite' => 'Lax',
        ]);
    }

    public static function revokeCurrentToken(): bool
    {
        $jwt = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (!is_string($jwt) || $jwt === '') {
            return true;
        }

        try {
            $decoded = JWT::decode($jwt, new Key(self::secret(), self::ALG));
            $jti = $decoded->jti ?? null;
            $exp = $decoded->exp ?? null;
            if (is_string($jti) && is_numeric($exp)) {
                return TokenRevocationStore::revoke($jti, (int)$exp, 'panel');
            }
            return false;
        } catch (Throwable) {
            // Invalid/expired tokens are already unusable.
            return true;
        }
    }

    private static function secret(): string {
        $secret = Config::get('AUTH_JWT_SECRET');
        if ($secret === null || strlen($secret) < 32) {
            throw HttpException::unauthorized('AUTH_JWT_SECRET must be configured and at least 32 characters.');
        }
        return $secret;
    }
}
