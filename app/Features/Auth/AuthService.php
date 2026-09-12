<?php
declare(strict_types=1);

namespace App\Features\Auth;

use App\Core\Config;

/**
 * Servicio de autenticacion OAuth usando HybridAuth 3.x.
 *
 * Proveedores soportados (todos gratuitos):
 * - Google (OAuth2)
 * - MicrosoftGraph (OAuth2)
 * - Facebook (OAuth2)
 *
 * HybridAuth se carga desde vendor/hybridauth/ (sin Composer).
 * El autoload se registra en public/index.php.
 *
 * Documentacion verificada con Context7 /hybridauth/hybridauth (Score: High, 70 snippets).
 */
class AuthService {

    /**
     * Retorna la configuracion de HybridAuth con los proveedores activos.
     * Solo habilita proveedores cuyas credenciales esten configuradas en .env.
     *
     * @return array{callback: string, providers: array<string, array<string, mixed>>}
     */
    public static function getConfig(): array {
        // OAuth redirect URIs must come from the configured canonical origin.
        // HTTP_HOST/X-Forwarded-Proto are request-controlled unless a trusted
        // proxy boundary is enforced, so never derive the callback from them.
        $siteUrl = Config::get('SITE_URL', 'https://usgarhoteles.com')
            ?? 'https://usgarhoteles.com';

        $config = [
            'callback' => rtrim($siteUrl, '/') . '/api/auth/callback',
            'providers' => [],
        ];

        // Google
        $googleId = Config::get('GOOGLE_CLIENT_ID');
        $googleSecret = Config::get('GOOGLE_CLIENT_SECRET');
        if ($googleId && $googleSecret) {
            $config['providers']['Google'] = [
                'enabled' => true,
                'keys' => ['id' => $googleId, 'secret' => $googleSecret],
                'scope' => 'https://www.googleapis.com/auth/userinfo.profile '
                         . 'https://www.googleapis.com/auth/userinfo.email',
            ];
        }

        // Microsoft (MicrosoftGraph en HybridAuth 3.x)
        $msId = Config::get('MICROSOFT_CLIENT_ID');
        $msSecret = Config::get('MICROSOFT_CLIENT_SECRET');
        // HybridAuth's MicrosoftGraph adapter does not expose a standardized
        // emailVerified field. Keep it hidden until the operator explicitly
        // accepts the tenant's email trust model.
        if ($msId && $msSecret && self::isTruthy(Config::get('MICROSOFT_TRUST_EMAIL', 'false'))) {
            $config['providers']['MicrosoftGraph'] = [
                'enabled' => true,
                'keys' => ['id' => $msId, 'secret' => $msSecret],
                'tenant' => 'common',
            ];
        }

        // Facebook
        $fbId = Config::get('FACEBOOK_APP_ID');
        $fbSecret = Config::get('FACEBOOK_APP_SECRET');
        if ($fbId && $fbSecret) {
            $config['providers']['Facebook'] = [
                'enabled' => true,
                'keys' => ['id' => $fbId, 'secret' => $fbSecret],
                'scope' => 'email, public_profile',
            ];
        }

        return $config;
    }

    /**
     * Retorna la lista de proveedores habilitados (para el frontend).
     *
     * @return array<string> e.g. ['Google', 'MicrosoftGraph', 'Facebook']
     */
    public static function getEnabledProviders(): array {
        $config = self::getConfig();
        return array_keys($config['providers']);
    }

    /**
     * Normaliza el perfil de HybridAuth a un array estandar para User::createFromOAuth().
     *
     * @param \Hybridauth\User\Profile $profile Perfil de HybridAuth
     * @param string $provider Nombre del proveedor (Google, MicrosoftGraph, Facebook)
     * @return array{email: string, email_verified: bool, first_name: ?string, last_name: ?string, photo_url: ?string, phone: ?string, provider: string, provider_id: string}
     */
    public static function normalizeProfile(object $profile, string $provider): array {
        $email = is_string($profile->email ?? null) ? trim($profile->email) : '';
        $verifiedField = $profile->emailVerified ?? null;
        $emailVerified = is_string($verifiedField)
            && $verifiedField !== ''
            && strcasecmp(trim($verifiedField), $email) === 0;

        if ($provider === 'MicrosoftGraph'
            && self::isTruthy(Config::get('MICROSOFT_TRUST_EMAIL', 'false'))
            && $email !== '') {
            $emailVerified = true;
        }

        return [
            'email'       => $email,
            'email_verified' => $emailVerified,
            'first_name'  => $profile->firstName ?? null,
            'last_name'   => $profile->lastName ?? null,
            'photo_url'   => $profile->photoURL ?? null,
            'phone'       => $profile->phone ?? null,
            'provider'    => $provider,
            'provider_id' => (string)($profile->identifier ?? ''),
        ];
    }

    private static function isTruthy(?string $value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Asegura la carga de la libreria Hybridauth.
     * Desde 2026-08-10 el autoloader PSR-4 de Composer (bootstrap.php) cubre
     * vendor/ completo; el escaneo manual de rutas anterior queda eliminado.
     * Solo queda el guard por si una accion se ejecuta sin bootstrap (tests).
     */
    public static function ensureHybridauthLoaded(): bool {
        if (class_exists(\Hybridauth\Hybridauth::class)) {
            return true;
        }

        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        return class_exists(\Hybridauth\Hybridauth::class);
    }
}
