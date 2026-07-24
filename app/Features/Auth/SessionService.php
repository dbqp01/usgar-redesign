<?php
declare(strict_types=1);

namespace App\Features\Auth;

use App\Core\Config;

/**
 * Servicio de sesiones basado en JWT (JSON Web Tokens).
 * Implementacion nativa en PHP 8 sin dependencias externas.
 *
 * Seguridad:
 * - Firma HMAC-SHA256 con secret del .env (AUTH_JWT_SECRET)
 * - Cookie HttpOnly (no accesible desde JS del cliente)
 * - SameSite=Lax (proteccion CSRF)
 * - Secure=true en produccion (solo HTTPS)
 *
 * Persistencia:
 * - Cookie de 30 dias = sesion sobrevive cierre del navegador
 */
class SessionService {
    private const COOKIE_NAME = 'usgar_session';
    private const COOKIE_TTL_DAYS = 30;
    private const ALG = 'HS256';

    // ──────────────────────────────────────
    // Token Generation
    // ──────────────────────────────────────

    /**
     * Genera un JWT firmado con los datos del usuario.
     *
     * @param array{id: int, first_name: ?string, last_name: ?string, email: string, photo_url: ?string, provider: string} $user
     */
    public static function createToken(array $user): string {
        $secret = self::getSecret();

        $header = self::base64UrlEncode(json_encode([
            'alg' => self::ALG,
            'typ' => 'JWT',
        ], JSON_THROW_ON_ERROR));

        $now = time();
        $payload = self::base64UrlEncode(json_encode([
            'sub'      => $user['id'],
            'name'     => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'email'    => $user['email'],
            'photo'    => $user['photo_url'] ?? null,
            'provider' => $user['provider'] ?? 'email',
            'iat'      => $now,
            'exp'      => $now + (self::COOKIE_TTL_DAYS * 86400),
        ], JSON_THROW_ON_ERROR));

        $signature = self::base64UrlEncode(
            hash_hmac('sha256', "{$header}.{$payload}", $secret, true)
        );

        return "{$header}.{$payload}.{$signature}";
    }

    // ──────────────────────────────────────
    // Token Validation
    // ──────────────────────────────────────

    /**
     * Valida un JWT y retorna el payload decodificado.
     * Retorna null si el token es invalido, expirado o la firma no coincide.
     */
    public static function validateToken(string $jwt): ?array {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;

        // Verificar firma
        $secret = self::getSecret();
        $expectedSig = self::base64UrlEncode(
            hash_hmac('sha256', "{$header}.{$payload}", $secret, true)
        );

        if (!hash_equals($expectedSig, $signature)) {
            return null;
        }

        // Decodificar payload
        $decoded = json_decode(self::base64UrlDecode($payload), true);
        if (!is_array($decoded)) {
            return null;
        }

        // Verificar expiracion
        if (isset($decoded['exp']) && $decoded['exp'] < time()) {
            return null;
        }

        return $decoded;
    }

    // ──────────────────────────────────────
    // Cookie Management
    // ──────────────────────────────────────

    /**
     * Setea la cookie de sesion con el JWT.
     */
    public static function setAuthCookie(string $jwt): void {
        setcookie(self::COOKIE_NAME, $jwt, [
            'expires'  => time() + (self::COOKIE_TTL_DAYS * 86400),
            'path'     => '/',
            'secure'   => Config::isProduction(),
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Elimina la cookie de sesion.
     */
    public static function clearAuthCookie(): void {
        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => Config::isProduction(),
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Obtiene los datos del usuario de la cookie JWT actual.
     * Retorna null si no hay cookie o el token es invalido.
     */
    public static function getUserFromRequest(): ?array {
        $jwt = $_COOKIE[self::COOKIE_NAME] ?? null;

        if ($jwt === null || $jwt === '') {
            return null;
        }

        return self::validateToken($jwt);
    }

    // ──────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────

    private static function getSecret(): string {
        $secret = Config::get('AUTH_JWT_SECRET');
        if ($secret === null || strlen($secret) < 32) {
            throw new \RuntimeException('AUTH_JWT_SECRET must be configured and at least 32 characters.');
        }
        return $secret;
    }

    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/'), true) ?: '';
    }
}
