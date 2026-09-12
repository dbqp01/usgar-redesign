<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\RateLimitStorageException;
use App\Core\Middleware;
use App\Core\RateLimiter;
use App\Core\Request;
use PHPUnit\Framework\TestCase;

final class RateLimiterStorageTest extends TestCase {
    private string $previousDir;

    protected function setUp(): void {
        $reflection = new \ReflectionClass(RateLimiter::class);
        $property = $reflection->getProperty('limitsDir');
        $property->setAccessible(true);
        $this->previousDir = (string)$property->getValue();
        // A non-empty, missing path bypasses directory creation and makes the
        // storage failure deterministic without touching the real data folder.
        $property->setValue(null, sys_get_temp_dir() . '/missing-rate-limit-' . bin2hex(random_bytes(8)));
    }

    protected function tearDown(): void {
        $reflection = new \ReflectionClass(RateLimiter::class);
        $property = $reflection->getProperty('limitsDir');
        $property->setAccessible(true);
        $property->setValue(null, $this->previousDir);
    }

    public function testStorageOpenFailureFailsClosedWithDistinguishableException(): void {
        $this->expectException(RateLimitStorageException::class);
        RateLimiter::check('198.51.100.10', 5, 60);
    }

    public function testGlobalMiddlewareKeepsPublicRouteAvailableOnStorageFailure(): void {
        Middleware::rateLimit(5, 60)(new Request('GET', '/api/rooms'));
        $this->addToAssertionCount(1);
    }
}
