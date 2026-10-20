<?php
declare(strict_types=1);

namespace Tests\Unit\Features\Auth;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\TokenRevocationStore;
use App\Features\Auth\SessionService;
use PHPUnit\Framework\TestCase;

/**
 * JWT con iss/aud/jti + validacion, y
 * patron de doble cookie CSRF en mutaciones autenticadas.
 */
final class SessionServiceTest extends TestCase {
    protected function setUp(): void {
        // Fix CI 2026-08-12: Config::set() pisa la CACHE de Config (el .env
        // del CI copia .env.example cuyo AUTH_JWT_SECRET de ejemplo no cumple
        // el minimo de 32 chars; putenv() no vence a la cache). Hermetico
        // contra cualquier .env del entorno.
        Config::set('AUTH_JWT_SECRET', 'unit-test-secret-0123456789abcdef');
        TokenRevocationStore::setDirectoryForTesting(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'usgar-revocation-tests');
        foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'usgar-revocation-tests' . DIRECTORY_SEPARATOR . '*.revoked') ?: [] as $file) {
            @unlink($file);
        }
    }

    protected function tearDown(): void {
        TokenRevocationStore::setDirectoryForTesting(null);
    }

    public function testRevokedTokenIsRejectedAfterLogout(): void {
        $token = SessionService::createToken([
            'id' => 9, 'first_name' => 'Ana', 'last_name' => 'Perez',
            'email' => 'ana@example.com', 'photo_url' => null, 'provider' => 'email',
        ]);
        $payload = SessionService::validateToken($token);
        self::assertNotNull($payload);
        $_COOKIE['usgar_session'] = $token;

        SessionService::revokeCurrentToken();

        self::assertNull(SessionService::validateToken($token));
        unset($_COOKIE['usgar_session']);
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

    public function testCsrfAssertionRejectsHtmlFormWithoutHeader(): void {
        $_COOKIE['usgar_csrf'] = 'abc123def456';
        $request = new Request('POST', '/api/user/profile', ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'text/html']);

        $this->expectException(HttpException::class);
        SessionService::assertCsrf($request);
    }
}
