<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config;
use App\Core\Request;
use PHPUnit\Framework\TestCase;

final class RequestHttpsTest extends TestCase {
    /** @var array<string, mixed> */
    private array $serverBackup;
    private string $trustedProxiesBackup;

    protected function setUp(): void {
        $this->serverBackup = $_SERVER;
        $this->trustedProxiesBackup = Config::get('TRUSTED_PROXIES', '') ?? '';
        Config::set('TRUSTED_PROXIES', '203.0.113.10');
    }

    protected function tearDown(): void {
        $_SERVER = $this->serverBackup;
        Config::set('TRUSTED_PROXIES', $this->trustedProxiesBackup);
    }

    public function testDirectHttpsRemainsTrusted(): void {
        $_SERVER = ['HTTPS' => 'on', 'REMOTE_ADDR' => '198.51.100.20'];

        self::assertTrue(Request::isHttps());
    }

    public function testForwardedHttpsRequiresTrustedProxy(): void {
        $_SERVER = [
            'HTTPS' => 'off',
            'SERVER_PORT' => 80,
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];

        self::assertTrue(Request::isHttps());
    }

    public function testUntrustedForwardedHttpsIsIgnored(): void {
        $_SERVER = [
            'HTTPS' => 'off',
            'SERVER_PORT' => 80,
            'REMOTE_ADDR' => '198.51.100.20',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];

        self::assertFalse(Request::isHttps());
    }
}
