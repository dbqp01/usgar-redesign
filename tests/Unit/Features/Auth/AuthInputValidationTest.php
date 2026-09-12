<?php
declare(strict_types=1);

namespace Tests\Unit\Features\Auth;

use App\Core\Request;
use App\Features\Auth\Actions\AuthLoginEmailAction;
use App\Features\Auth\Actions\AuthRegisterAction;
use App\Features\Auth\AuthRedirect;
use App\Features\Auth\SessionService;
use App\Core\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthInputValidationTest extends TestCase {
    public function testLoginRejectsNonStringEmailWithoutDatabaseAccess(): void {
        ob_start();
        (new AuthLoginEmailAction())(new Request(
            method: 'POST',
            path: '/api/auth/login-email',
            headers: ['accept' => 'application/json'],
            body: ['email' => ['attacker@example.com'], 'password' => 'password123'],
        ));
        $payload = json_decode((string)ob_get_clean(), true);

        self::assertSame(400, http_response_code());
        self::assertSame('BAD_REQUEST', $payload['error']['code']);
        self::assertSame('Email and password are required.', $payload['error']['message']);
    }

    public function testLoginRejectsNonStringPasswordWithoutDatabaseAccess(): void {
        ob_start();
        (new AuthLoginEmailAction())(new Request(
            method: 'POST',
            path: '/api/auth/login-email',
            headers: ['accept' => 'application/json'],
            body: ['email' => 'user@example.com', 'password' => ['password123']],
        ));
        $payload = json_decode((string)ob_get_clean(), true);

        self::assertSame(400, http_response_code());
        self::assertSame('Email and password are required.', $payload['error']['message']);
    }

    public function testRegisterRejectsNonStringNameWithoutDatabaseAccess(): void {
        ob_start();
        (new AuthRegisterAction())(new Request(
            method: 'POST',
            path: '/api/auth/register',
            headers: ['accept' => 'application/json'],
            body: [
                'email' => 'user@example.com',
                'password' => 'password123',
                'fullName' => ['User'],
            ],
        ));
        $payload = json_decode((string)ob_get_clean(), true);

        self::assertSame(400, http_response_code());
        self::assertSame('Invalid registration data.', $payload['error']['message']);
    }

    public function testRegisterRejectsNonStringEmailWithoutDatabaseAccess(): void {
        ob_start();
        (new AuthRegisterAction())(new Request(
            method: 'POST',
            path: '/api/auth/register',
            headers: ['accept' => 'application/json'],
            body: [
                'email' => ['user@example.com'],
                'password' => 'password123',
                'fullName' => 'User',
            ],
        ));
        $payload = json_decode((string)ob_get_clean(), true);

        self::assertSame(400, http_response_code());
        self::assertSame('Invalid registration data.', $payload['error']['message']);
    }

    public function testRegisterRejectsNonStringPasswordWithoutDatabaseAccess(): void {
        ob_start();
        (new AuthRegisterAction())(new Request(
            method: 'POST',
            path: '/api/auth/register',
            headers: ['accept' => 'application/json'],
            body: [
                'email' => 'user@example.com',
                'password' => ['password123'],
                'fullName' => 'User',
            ],
        ));
        $payload = json_decode((string)ob_get_clean(), true);

        self::assertSame(400, http_response_code());
        self::assertSame('Invalid registration data.', $payload['error']['message']);
    }

    public function testProfileRejectsNonStringFieldsBeforeDatabaseAccess(): void {
        Config::set('AUTH_JWT_SECRET', str_repeat('s', 32));
        Config::set('SITE_URL', 'https://usgarhoteles.com');
        $_COOKIE['usgar_session'] = SessionService::createToken([
            'id' => 7,
            'first_name' => 'QA',
            'last_name' => 'Guest',
            'email' => 'qa@example.com',
            'photo_url' => null,
            'provider' => 'email',
        ]);
        $_COOKIE['usgar_csrf'] = 'csrf-test';
        ob_start();
        $action = new \App\Features\Auth\Actions\UpdateUserProfileAction();
        try {
            $action(new Request(
                method: 'POST',
                path: '/api/user/profile',
                headers: ['x-csrf-token' => 'csrf-test'],
                body: ['fullName' => ['invalid']],
            ));
        } finally {
            $payload = json_decode((string)ob_get_clean(), true);
            unset($_COOKIE['usgar_csrf']);
            unset($_COOKIE['usgar_session']);
        }

        self::assertSame(400, http_response_code());
        self::assertSame('BAD_REQUEST', $payload['error']['code']);
        self::assertSame('Profile fields must be strings.', $payload['error']['message']);
    }

    #[DataProvider('invalidRedirectProvider')]
    public function testRedirectMustRemainAnInternalPath(mixed $redirect): void {
        self::assertSame('/fallback', AuthRedirect::safePath($redirect, '/fallback'));
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function invalidRedirectProvider(): iterable {
        yield 'external URL' => ['https://evil.example/'];
        yield 'protocol relative URL' => ['//evil.example/'];
        yield 'backslash path' => ['/\\evil.example'];
        yield 'newline header injection' => ["/profile\nX-Injected: yes"];
        yield 'array input' => [['/profile']];
    }

    public function testRedirectAcceptsInternalPath(): void {
        self::assertSame('/profile?tab=bookings', AuthRedirect::safePath(' /profile?tab=bookings ', '/fallback'));
    }
}
