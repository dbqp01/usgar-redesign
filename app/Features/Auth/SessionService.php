<?php
declare(strict_types=1);

namespace App\Features\Auth;

use App\Core\Config;
use App\Core\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

/**
 * Servicio de sesiones basado en JWT (JSON Web Tokens).
 * Implementado con firebase/php-jwt (RFC 7519) — reemplaza la
 * implementacion casera anterior (2026-08-10): la libreria valida
 * algoritmo/exp/firma de forma estandar y resiste alg-confusion/alg=none.
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

        $now = time();
        $payload = [
            'sub'      => $user['id'],
            'name'     => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'email'    => $user['email'],
            'photo'    => $user['photo_url'] ?? null,
            'provider' => $user['provider'],
            'iat'      => $now,
            'exp'      => $now + (self::COOKIE_TTL_DAYS * 86400),
        ];

        return JWT::encode($payload, $secret, self::ALG);
    }

    // ──────────────────────────────────────
    // Token Validation
    // ──────────────────────────────────────

    /**
     * Valida un JWT y retorna el payload decodificado.
     * Retorna null si el token es invalido, expirado o la firma no coincide.
     * La libreria firebase/php-jwt valida de forma estandar: alg fijo HS256
     * (rechaza alg=none y alg-confusion), expiracion (exp) y firma HMAC.
     *
     * @return array<string, mixed>|null
     */
    public static function validateToken(string $jwt): ?array {
        try {
            $decoded = JWT::decode($jwt, new Key(self::getSecret(), self::ALG));
            return (array) $decoded;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ──────────────────────────────────────
    // Cookie Management
    // ──────────────────────────────────────

    /**
     * Setea la cookie de sesion con el JWT.
     */
    public static function setAuthCookie(string $jwt): void {
        $isSecure = Request::isHttps() || 
                    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        setcookie(self::COOKIE_NAME, $jwt, [
            'expires'  => time() + (self::COOKIE_TTL_DAYS * 86400),
            'path'     => '/',
            'secure'   => $isSecure,
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Elimina la cookie de sesion.
     */
    public static function clearAuthCookie(): void {
        $isSecure = Request::isHttps() || 
                    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => $isSecure,
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Obtiene los datos del usuario de la cookie JWT actual.
     * Retorna null si no hay cookie o el token es invalido.
     *
     * @return array<string, mixed>|null
     */
    public static function getUserFromRequest(): ?array {
        $jwt = $_COOKIE[self::COOKIE_NAME] ?? null;

        if ($jwt === null || $jwt === '') {
            return null;
        }

        return self::validateToken($jwt);
    }

    /**
     * Expone los datos publicos del usuario para las respuestas JSON de auth.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function toPublicUser(array $user, bool $includePhone = false): array {
        $payload = [
            'sub'   => $user['id'],
            'name'  => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'email' => $user['email'],
        ];

        if ($includePhone) {
            $payload['phone'] = $user['phone'] ?? '';
        }

        $payload['photo']    = $user['photo_url'] ?? null;
        $payload['provider'] = $user['provider'];

        return $payload;
    }

    // ──────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────

    private static function getSecret(): string {
        $secret = Config::get('AUTH_JWT_SECRET');
        if ($secret === null || strlen($secret) < 32) {
            throw \App\Core\HttpException::unauthorized('AUTH_JWT_SECRET must be configured and at least 32 characters.');
        }
        return $secret;
    }
}
