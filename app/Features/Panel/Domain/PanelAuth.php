<?php
declare(strict_types=1);

namespace App\Features\Panel\Domain;

use App\Core\Config;
use App\Core\HttpException;

/**
 * Autenticacion del panel de disponibilidad del dueno (cookie propia,
 * independiente del login de huespedes). JWT HMAC-SHA256 firmado con el mismo
 * secret que SessionService pero con claim role=panel y cookie separada
 * (usgar_panel). TTL 12h. No toca la zona de Auth del sitio.
 */
class PanelAuth {
    private const COOKIE_NAME = 'usgar_panel';
    private const TTL = 12 * 3600;
    private const ALG = 'HS256';

    public static function issueToken(): string {
        $header = self::b64(json_encode(['alg' => self::ALG, 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $now = time();
        $payload = self::b64(json_encode([
            'role' => 'panel',
            'iat'  => $now,
            'exp'  => $now + self::TTL,
        ], JSON_THROW_ON_ERROR));
        $sig = self::b64(hash_hmac('sha256', "{$header}.{$payload}", self::secret(), true));
        return "{$header}.{$payload}.{$sig}";
    }

    public static function isAuthenticated(): bool {
        $jwt = $_COOKIE[self::COOKIE_NAME] ?? '';
        if ($jwt === '') {
            return false;
        }
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return false;
        }
        [$header, $payload, $signature] = $parts;

        $decodedHeader = json_decode(self::unb64($header), true);
        if (!is_array($decodedHeader) || ($decodedHeader['alg'] ?? null) !== self::ALG) {
            return false;
        }

        $expected = self::b64(hash_hmac('sha256', "{$header}.{$payload}", self::secret(), true));
        if (!hash_equals($expected, $signature)) {
            return false;
        }

        $decoded = json_decode(self::unb64($payload), true);
        if (!is_array($decoded) || ($decoded['role'] ?? null) !== 'panel') {
            return false;
        }

        return isset($decoded['exp']) && $decoded['exp'] >= time();
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

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'), true) ?: '';
    }
}
