<?php
declare(strict_types=1);

namespace App\Features\Auth;

use App\Core\Config;
use App\Core\Logger;

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
     * Normaliza el perfil de HybridAuth a un array estandar para User::createFromOAuth().
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
     * Verifica si un proveedor es valido y esta habilitado.
     */
    public static function isValidProvider(string $provider): bool {
        return in_array($provider, self::getEnabledProviders(), true);
    }

    /**
     * Asegura la carga de la libreria Hybridauth buscando autoloader en Composer o en subdirectorios vendor nativos.
     * Escanea dinamicamente multiples ubicaciones de vendor (raiz, DocumentRoot, carpetas superiores)
     * para garantizar compatibilidad total en entornos nativos y Hostinger.
     */
    public static function ensureHybridauthLoaded(): bool {
        if (class_exists(\Hybridauth\Hybridauth::class)) {
            return true;
        }

        // Determinar carpetas base candidatas para ubicar /vendor
        $candidateBases = array_unique(array_filter([
            dirname(__DIR__, 2),
            dirname(__DIR__, 3),
            dirname(__DIR__, 1),
            $_SERVER['DOCUMENT_ROOT'] ?? null,
            isset($_SERVER['DOCUMENT_ROOT']) ? dirname($_SERVER['DOCUMENT_ROOT']) : null,
            isset($_SERVER['SCRIPT_FILENAME']) ? dirname($_SERVER['SCRIPT_FILENAME']) : null,
            isset($_SERVER['SCRIPT_FILENAME']) ? dirname($_SERVER['SCRIPT_FILENAME']) . '/..' : null,
            realpath(__DIR__ . '/../../../'), // Project root explicitly
        ]));

        // 1. Prioridad: Cargar autoloader nativo de Hybridauth (registra spl_autoload_register para Hybridauth\)
        $relativeAutoloaders = [
            '/vendor/hybridauth/hybridauth/src/autoload.php',
            '/vendor/hybridauth/src/autoload.php',
            '/vendor/hybridauth/autoload.php',
        ];

        foreach ($candidateBases as $base) {
            foreach ($relativeAutoloaders as $rel) {
                $file = $base . $rel;
                if (file_exists($file)) {
                    require_once $file;
                    if (class_exists(\Hybridauth\Hybridauth::class)) {
                        return true;
                    }
                }
            }
        }

        // 2. Segunda opcion: Cargar vendor/autoload.php general de Composer
        foreach ($candidateBases as $base) {
            $file = $base . '/vendor/autoload.php';
            if (file_exists($file)) {
                require_once $file;
                if (class_exists(\Hybridauth\Hybridauth::class)) {
                    return true;
                }
            }
        }

        // 3. Fallback PSR-4: Registrar manualmente spl_autoload_register si se localiza la carpeta src/ de Hybridauth
        foreach ($candidateBases as $base) {
            $srcDirs = [
                $base . '/vendor/hybridauth/hybridauth/src/',
                $base . '/vendor/hybridauth/src/',
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
                    if (class_exists(\Hybridauth\Hybridauth::class)) {
                        return true;
                    }
                }
            }
        }

        return class_exists(\Hybridauth\Hybridauth::class);
    }
}

