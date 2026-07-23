<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;

/**
 * Servicio de autenticación OAuth usando HybridAuth 3.x.
 *
 * Proveedores soportados (todos gratuitos):
 * - Google (OAuth2)
 * - MicrosoftGraph (OAuth2)
 * - Facebook (OAuth2)
 *
 * HybridAuth se carga desde vendor/hybridauth/ (sin Composer).
 * El autoload se registra en public/index.php.
 *
 * Documentación verificada con Context7 /hybridauth/hybridauth (Score: High, 70 snippets).
 */
class AuthService {

    /**
     * Retorna la configuración de HybridAuth con los proveedores activos.
     * Solo habilita proveedores cuyas credenciales estén configuradas en .env.
     */
    public static function getConfig(): array {
        $siteUrl = Config::get('SITE_URL', 'http://localhost:8000');

        $config = [
            'callback' => $siteUrl . '/api/auth/callback',
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
        if ($msId && $msSecret) {
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
     * Normaliza el perfil de HybridAuth a un array estándar para User::createFromOAuth().
     *
     * @param \Hybridauth\User\Profile $profile Perfil de HybridAuth
     * @param string $provider Nombre del proveedor (Google, MicrosoftGraph, Facebook)
     * @return array{email: string, first_name: ?string, last_name: ?string, photo_url: ?string, phone: ?string, provider: string, provider_id: string}
     */
    public static function normalizeProfile(object $profile, string $provider): array {
        return [
            'email'       => $profile->email ?? '',
            'first_name'  => $profile->firstName ?? null,
            'last_name'   => $profile->lastName ?? null,
            'photo_url'   => $profile->photoURL ?? null,
            'phone'       => $profile->phone ?? null,
            'provider'    => $provider,
            'provider_id' => $profile->identifier ?? '',
        ];
    }

    /**
     * Verifica si un proveedor es válido y está habilitado.
     */
    public static function isValidProvider(string $provider): bool {
        return in_array($provider, self::getEnabledProviders(), true);
    }

    /**
     * Asegura la carga de la librería Hybridauth buscando autoloader en Composer o en subdirectorios vendor nativos.
     */
    public static function ensureHybridauthLoaded(): bool {
        if (class_exists(\Hybridauth\Hybridauth::class)) {
            return true;
        }

        $rootDir = dirname(__DIR__, 2);

        $possibleAutoloaders = [
            $rootDir . '/vendor/autoload.php',
            $rootDir . '/vendor/hybridauth/hybridauth/src/autoload.php',
            $rootDir . '/vendor/hybridauth/src/autoload.php',
            $rootDir . '/vendor/hybridauth/autoload.php',
        ];

        foreach ($possibleAutoloaders as $file) {
            if (file_exists($file)) {
                require_once $file;
                if (class_exists(\Hybridauth\Hybridauth::class)) {
                    return true;
                }
            }
        }

        // Fallback: Registro explícito del namespace PSR-4 si falta autoloader externo
        $srcDirs = [
            $rootDir . '/vendor/hybridauth/hybridauth/src/',
            $rootDir . '/vendor/hybridauth/src/',
        ];

        foreach ($srcDirs as $srcDir) {
            if (is_dir($srcDir)) {
                spl_autoload_register(function (string $class) use ($srcDir) {
                    $prefix = 'Hybridauth\\';
                    if (str_starts_with($class, $prefix)) {
                        $relativeClass = substr($class, strlen($prefix));
                        $file = $srcDir . str_replace('\\', '/', $relativeClass) . '.php';
                        if (file_exists($file)) {
                            require_once $file;
                        }
                    }
                });
                break;
            }
        }

        return class_exists(\Hybridauth\Hybridauth::class);
    }
}

