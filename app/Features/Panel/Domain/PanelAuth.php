<?php
declare(strict_types=1);

namespace App\Features\Panel\Domain;

use App\Core\Config;
use App\Core\HttpException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

/**
 * Autenticacion del panel de disponibilidad del dueno (cookie propia,
 * independiente del login de huespedes). JWT HMAC-SHA256 firmado con el mismo
 * secret que SessionService pero con claim role=panel y cookie separada
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

    public static function issueToken(): string {
        $now = time();
        return JWT::encode([
            'role' => 'panel',
            'iat'  => $now,
            'exp'  => $now + self::TTL,
        ], self::secret(), self::ALG);
    }

    public static function isAuthenticated(): bool {
        $jwt = $_COOKIE[self::COOKIE_NAME] ?? '';
        if ($jwt === '') {
            return false;
        }
        try {
            $decoded = JWT::decode($jwt, new Key(self::secret(), self::ALG));
            return ($decoded->role ?? null) === 'panel';
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
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        setcookie(self::COOKIE_NAME, self::issueToken(), [
            'expires'  => time() + self::TTL,
            'path'     => '/',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function clearCookie(): void {
        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function secret(): string {
        $secret = Config::get('AUTH_JWT_SECRET');
        if ($secret === null || strlen($secret) < 32) {
            throw HttpException::unauthorized('AUTH_JWT_SECRET must be configured and at least 32 characters.');
        }
        return $secret;
    }
}
