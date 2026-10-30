<?php
declare(strict_types=1);

namespace App\Features\Newsletter\Domain;

use App\Core\Config;
use App\Core\HttpException;

/**
 * Tokens HMAC de la newsletter. El proposito forma parte del mensaje firmado:
 * el token de confirmacion no sirve para darse de baja (ni al reves), y el
 * email queda ligado al token, asi que un tercero no puede operar sobre otra
 * direccion. Se reutiliza BOOKING_TOKEN_SECRET con dominio propio para no
 * sumar otra variable de entorno.
 */
class NewsletterToken {
    public const CONFIRM = 'confirm';
    public const UNSUBSCRIBE = 'unsubscribe';

    public static function normalize(string $email): string {
        return strtolower(trim($email));
    }

    /**
     * @throws HttpException 503 si el servidor no tiene secreto configurado.
     */
    public static function derive(string $purpose, string $email): string {
        $secret = Config::get('BOOKING_TOKEN_SECRET') ?: Config::get('CRON_SECRET');
        if ($secret === null || $secret === '') {
            throw HttpException::missingCredentials('Falta el secreto de tokens de la newsletter.');
        }

        return hash_hmac('sha256', 'newsletter:' . $purpose . ':' . self::normalize($email), $secret);
    }

    public static function verify(string $purpose, string $email, string $token): bool {
        if (preg_match('/\A[0-9a-f]{64}\z/', $token) !== 1) {
            return false;
        }

        try {
            return hash_equals(self::derive($purpose, $email), $token);
        } catch (HttpException) {
            return false;
        }
    }
}
