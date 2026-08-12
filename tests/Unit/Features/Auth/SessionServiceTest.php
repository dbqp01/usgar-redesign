<?php
declare(strict_types=1);

namespace Tests\Unit\Features\Auth;

use App\Core\HttpException;
use App\Core\Request;
use App\Features\Auth\SessionService;
use PHPUnit\Framework\TestCase;

/**
 * P1-7/P1-8 (2026-08-12): JWT con iss/aud/jti + validacion, y
 * patron de doble cookie CSRF en mutaciones autenticadas.
 */
final class SessionServiceTest extends TestCase {
    protected function setUp(): void {
        // AUTH_JWT_SECRET exigido por SessionService::getSecret (>= 32 chars)
        if (getenv('AUTH_JWT_SECRET') === false) {
            putenv('AUTH_JWT_SECRET=unit-test-secret-0123456789abcdef');
        }
    }

    public function testCreateTokenIncludesIssAudJti(): void {
        $token = SessionService::createToken([
            'id' => 7, 'first_name' => 'Ana', 'last_name' => 'Perez',
            'email' => 'ana@example.com', 'photo_url' => null, 'provider' => 'email',
        ]);

        $payload = SessionService::validateToken($token);
        $this->assertNotNull($payload);
        $this->assertSame('usgar-web', $payload['aud']);
        $this->assertNotEmpty($payload['iss']);
        $this->assertNotEmpty($payload['jti']);
        $this->assertSame(7, $payload['sub']);
    }

    public function testCsrfAssertionPassesWithMatchingCookieAndHeader(): void {
        $_COOKIE['usgar_csrf'] = 'abc123def456';
        $request = new Request('POST', '/api/auth/logout', ['X-CSRF-Token' => 'abc123def456']);

        // No debe lanzar
        SessionService::assertCsrf($request);
        $this->assertTrue(true);
    }

    public function testCsrfAssertionRejectsMismatch(): void {
        $_COOKIE['usgar_csrf'] = 'abc123def456';
        $request = new Request('POST', '/api/auth/logout', ['X-CSRF-Token' => 'otro-token']);

        $this->expectException(HttpException::class);
        SessionService::assertCsrf($request);
    }

    public function testCsrfAssertionRejectsMissingCookie(): void {
        unset($_COOKIE['usgar_csrf']);
        $request = new Request('POST', '/api/auth/logout', ['X-CSRF-Token' => 'abc123def456']);

        $this->expectException(HttpException::class);
        SessionService::assertCsrf($request);
    }
}
