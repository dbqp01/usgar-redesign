<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Panel;

use App\Core\Config;
use App\Core\TokenRevocationStore;
use App\Features\Panel\Actions\PanelLoginAction;
use App\Core\Request;
use App\Core\RateLimiter;
use App\Core\HttpException;
use App\Features\Panel\Domain\PanelAuth;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

final class PanelAuthTest extends TestCase
{
    protected function setUp(): void
    {
        Config::set('AUTH_JWT_SECRET', str_repeat('s', 32));
        Config::set('SITE_URL', 'https://usgarhoteles.com');
        unset($_COOKIE['usgar_panel']);
        TokenRevocationStore::setDirectoryForTesting(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'usgar-panel-revocation-tests');
        foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'usgar-panel-revocation-tests' . DIRECTORY_SEPARATOR . '*.revoked') ?: [] as $file) {
            @unlink($file);
        }
    }

    protected function tearDown(): void
    {
        TokenRevocationStore::setDirectoryForTesting(null);
        unset($_COOKIE['usgar_panel']);
    }

    public function testPanelLoginUsesDedicatedRateLimitBucket(): void
    {
        Config::set('PANEL_PASSWORD', 'panel-password');
        $_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int(1, 254);
        $action = new PanelLoginAction();
        $request = new Request('POST', '/api/panel/login', [], ['password' => 'wrong']);

        for ($i = 0; $i < 5; $i++) {
            try {
                $action($request);
            } catch (HttpException $e) {
                self::assertSame(401, $e->getStatusCode());
            }
        }

        try {
            $action($request);
            self::fail('Sixth attempt should be rate limited.');
        } catch (HttpException $e) {
            self::assertSame(429, $e->getStatusCode());
        }
    }

    public function testIssuedTokenContainsPanelIssuerAudienceAndJti(): void
    {
        $token = PanelAuth::issueToken();
        $claims = JWT::decode($token, new Key(str_repeat('s', 32), 'HS256'));

        $this->assertSame('panel', $claims->role);
        $this->assertSame('https://usgarhoteles.com', $claims->iss);
        $this->assertSame('usgar-panel', $claims->aud);
        $this->assertIsString($claims->jti);
        $this->assertNotSame('', $claims->jti);
    }

    public function testTokenWithWrongAudienceIsRejected(): void
    {
        $token = JWT::encode([
            'role' => 'panel',
            'iat' => time(),
            'exp' => time() + 3600,
            'iss' => 'https://usgarhoteles.com',
            'aud' => 'wrong-audience',
            'jti' => bin2hex(random_bytes(16)),
        ], str_repeat('s', 32), 'HS256');
        $_COOKIE['usgar_panel'] = $token;

        $this->assertFalse(PanelAuth::isAuthenticated());
    }

    public function testRevokedPanelTokenIsRejected(): void
    {
        $_COOKIE['usgar_panel'] = PanelAuth::issueToken();
        self::assertTrue(PanelAuth::isAuthenticated());

        PanelAuth::revokeCurrentToken();

        self::assertFalse(PanelAuth::isAuthenticated());
    }
}
