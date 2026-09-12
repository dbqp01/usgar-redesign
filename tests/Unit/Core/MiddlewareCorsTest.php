<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\HttpException;
use App\Core\Middleware;
use PHPUnit\Framework\TestCase;

final class MiddlewareCorsTest extends TestCase {
    protected function tearDown(): void {
        \App\Core\Config::set('SITE_URL', 'https://usgarhoteles.com');
        \App\Core\Config::set('ALLOWED_ORIGINS', 'https://usgarhoteles.com');
    }

    public function testExplicitAllowlistOmitsOriginForSameOriginRequests(): void {
        self::assertNull(Middleware::resolveCorsOrigin(['https://usgarhoteles.com'], ''));
    }

    public function testExplicitAllowlistReturnsOnlyAnExactAllowedOrigin(): void {
        self::assertSame(
            'https://usgarhoteles.com',
            Middleware::resolveCorsOrigin(['https://usgarhoteles.com'], 'https://usgarhoteles.com')
        );
    }

    public function testExplicitAllowlistRejectsUnknownOrigin(): void {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(403);
        Middleware::resolveCorsOrigin(['https://usgarhoteles.com'], 'https://evil.example');
    }

    public function testWildcardConfigurationRemainsTheOnlyWildcardCase(): void {
        self::assertSame('*', Middleware::resolveCorsOrigin(['*'], ''));
        self::assertSame('*', Middleware::resolveCorsOrigin(['*'], 'https://localhost:4321'));
    }

    public function testMissingProductionAllowlistFailsClosed(): void {
        \App\Core\Config::set('SITE_URL', 'https://usgarhoteles.com');
        \App\Core\Config::set('ALLOWED_ORIGINS', '');

        self::assertSame([], \App\Core\Config::getAllowedOrigins());
    }

    public function testMissingDevelopmentAllowlistKeepsLocalWildcard(): void {
        \App\Core\Config::set('SITE_URL', 'http://localhost:4321');
        \App\Core\Config::set('ALLOWED_ORIGINS', '');

        self::assertSame(['*'], \App\Core\Config::getAllowedOrigins());
    }
}
