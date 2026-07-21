<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Models\User;
use App\Services\AuthService;
use App\Services\SessionService;

/**
 * Controlador de Autenticación.
 * Gestiona el flujo de login social (Google, Microsoft, Facebook),
 * registro e inicio de sesión tradicional (Email/Password),
 * consulta de perfil activo, cierre de sesión e historial de reservas del usuario.
 */
class AuthController {

    private function getPdo() {
        return Database::getInstance()->getConnection();
    }

    /**
     * GET /api/auth/login?provider=Google&redirect=/book
     * Inicia la autenticación con el proveedor OAuth indicado.
     */
    public function login(Request $request): void {
        $provider = $request->getQuery('provider', 'Google');
        $redirect = $request->getQuery('redirect', '/');

        // Guardar redirect en sesión o cookie temporal para recuperarlo tras el callback
        setcookie('usgar_auth_redirect', $redirect, [
            'expires'  => time() + 300, // 5 minutos
            'path'     => '/',
            'secure'   => Config::isProduction(),
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);

        $config = AuthService::getConfig();

        if (!isset($config['providers'][$provider])) {
            Response::error("El proveedor '{$provider}' no está configurado o sus credenciales están ausentes.", 400);
            return;
        }

        try {
            // Requerir autoloader de HybridAuth si existe
            $hybridAuthAutoload = dirname(__DIR__, 2) . '/vendor/hybridauth/autoload.php';
            if (file_exists($hybridAuthAutoload)) {
                require_once $hybridAuthAutoload;
            }

            if (!class_exists(\Hybridauth\Hybridauth::class)) {
                throw HttpException::internal("Hybridauth library not found in vendor directory.");
            }

            $hybridauth = new \Hybridauth\Hybridauth($config);
            $adapter = $hybridauth->authenticate($provider);

            // Redirección manejada internamente por HybridAuth
            exit(0);
        } catch (\Throwable $e) {
            Logger::error("OAuth login failed for provider {$provider}: " . $e->getMessage());
            Response::error("Error al iniciar sesión con {$provider}: " . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/auth/callback
     * Procesa la respuesta de retorno del proveedor OAuth.
     */
    public function callback(Request $request): void {
        $config = AuthService::getConfig();

        try {
            $hybridAuthAutoload = dirname(__DIR__, 2) . '/vendor/hybridauth/autoload.php';
            if (file_exists($hybridAuthAutoload)) {
                require_once $hybridAuthAutoload;
            }

            if (!class_exists(\Hybridauth\Hybridauth::class)) {
                throw HttpException::internal("Hybridauth library not found.");
            }

            $hybridauth = new \Hybridauth\Hybridauth($config);
            $storage = new \Hybridauth\Storage\Session();

            // Detectar cuál proveedor completó el callback
            $provider = $storage->get('provider') ?? 'Google';
            $adapter = $hybridauth->getAdapter($provider);
            $profile = $adapter->getUserProfile();

            if (empty($profile->email)) {
                throw HttpException::badRequest("El proveedor no retornó un correo electrónico válido.");
            }

            $pdo = $this->getPdo();
            if ($pdo === null) {
                throw HttpException::internal("Database connection failed.");
            }

            $userModel = new User($pdo);
            $normalized = AuthService::normalizeProfile($profile, $provider);
            $userId = $userModel->createFromOAuth($normalized);

            if ($userId === null) {
                throw HttpException::internal("No se pudo crear o actualizar la cuenta de usuario.");
            }

            $user = $userModel->findById($userId);
            $jwt = SessionService::createToken($user);
            SessionService::setAuthCookie($jwt);

            // Obtener redirect guardado o por defecto /
            $redirect = $_COOKIE['usgar_auth_redirect'] ?? '/';
            setcookie('usgar_auth_redirect', '', time() - 3600, '/');

            header('Location: ' . $redirect);
            exit(0);

        } catch (\Throwable $e) {
            Logger::error("OAuth callback failed: " . $e->getMessage());
            header('Location: /login?error=' . urlencode("Error de autenticación: " . $e->getMessage()));
            exit(0);
        }
    }

    /**
     * POST /api/auth/register
     * Registro con email y contraseña.
     */
    public function register(Request $request): void {
        $data = $request->getBody() ?? [];
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $firstName = trim($data['first_name'] ?? $data['fullName'] ?? '');
        $lastName = trim($data['last_name'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error("Dirección de correo electrónico no válida.", 400);
            return;
        }

        if (strlen($password) < 8) {
            Response::error("La contraseña debe tener al menos 8 caracteres.", 400);
            return;
        }

        if (empty($firstName)) {
            Response::error("El nombre es requerido.", 400);
            return;
        }

        $pdo = $this->getPdo();
        if ($pdo === null) {
            Response::error("Error interno de conexión a la base de datos.", 500);
            return;
        }

        $userModel = new User($pdo);
        $userId = $userModel->createFromEmail($email, $password, $firstName, $lastName);

        if ($userId === null) {
            Response::error("Ya existe una cuenta registrada con este correo electrónico.", 409);
            return;
        }

        $user = $userModel->findById($userId);
        $jwt = SessionService::createToken($user);
        SessionService::setAuthCookie($jwt);

        Response::json([
            'success' => true,
            'message' => 'Cuenta creada exitosamente.',
            'user'    => [
                'sub'      => $user['id'],
                'name'     => trim($user['first_name'] . ' ' . $user['last_name']),
                'email'    => $user['email'],
                'photo'    => $user['photo_url'] ?? null,
                'provider' => $user['provider'],
            ],
        ], 201);
    }

    /**
     * POST /api/auth/login-email
     * Inicio de sesión tradicional con email y contraseña.
     */
    public function loginEmail(Request $request): void {
        $data = $request->getBody() ?? [];
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            Response::error("Correo y contraseña son requeridos.", 400);
            return;
        }

        $pdo = $this->getPdo();
        if ($pdo === null) {
            Response::error("Error interno de conexión.", 500);
            return;
        }

        $userModel = new User($pdo);
        $user = $userModel->verifyPassword($email, $password);

        if ($user === null) {
            Response::error("Correo o contraseña incorrectos.", 401);
            return;
        }

        $jwt = SessionService::createToken($user);
        SessionService::setAuthCookie($jwt);

        Response::json([
            'success' => true,
            'message' => 'Sesión iniciada correctamente.',
            'user'    => [
                'sub'      => $user['id'],
                'name'     => trim($user['first_name'] . ' ' . $user['last_name']),
                'email'    => $user['email'],
                'photo'    => $user['photo_url'] ?? null,
                'provider' => $user['provider'],
            ],
        ]);
    }

    /**
     * GET /api/auth/me
     * Retorna los datos del usuario conectado desde la cookie JWT.
     */
    public function me(Request $request): void {
        $user = SessionService::getUserFromRequest();

        if ($user === null) {
            Response::json([
                'success' => false,
                'user'    => null,
            ], 200);
            return;
        }

        Response::json([
            'success' => true,
            'user'    => $user,
        ]);
    }

    /**
     * POST /api/auth/logout
     * Cierra la sesión activa expirando la cookie JWT.
     */
    public function logout(Request $request): void {
        SessionService::clearAuthCookie();
        Response::json([
            'success' => true,
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    /**
     * GET /api/user/bookings
     * Retorna la lista de reservas asociadas al usuario autenticado.
     */
    public function bookings(Request $request): void {
        $user = SessionService::getUserFromRequest();

        if ($user === null) {
            Response::error("No hay una sesión activa.", 401);
            return;
        }

        $pdo = $this->getPdo();
        if ($pdo === null) {
            Response::error("Error de base de datos.", 500);
            return;
        }

        $userModel = new User($pdo);
        $bookings = $userModel->getBookings((int)$user['sub']);

        Response::json([
            'success'  => true,
            'user_id'  => $user['sub'],
            'bookings' => $bookings,
        ]);
    }
}
