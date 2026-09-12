<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use PHPUnit\Framework\TestCase;

/**
 * P3-3 (2026-08-12, OWASP API4:2023 "limits set appropriately"): el rate
 * limit global exime /api/webhook y /api/health — MP reintenta en rafagas
 * legitimas y health es el probe de monitoreo.
 */
final class MiddlewareRateLimitExemptTest extends TestCase {
    public function testWebhookIsExemptEvenWithZeroBudget(): void {
        $mw = Middleware::rateLimit(0, 600);
        // maxRequests=0 bloquearia cualquier ruta no exenta; exenta => no lanza
        $mw(new Request('POST', '/api/webhook'));
        $this->addToAssertionCount(1);
    }

    public function testHealthIsExemptEvenWithZeroBudget(): void {
        $mw = Middleware::rateLimit(0, 600);
        $mw(new Request('GET', '/api/health'));
        $this->addToAssertionCount(1);
    }

    public function testNormalRouteIsBlockedWithZeroBudget(): void {
        $mw = Middleware::rateLimit(0, 600);
        $this->expectException(HttpException::class);
        $mw(new Request('GET', '/api/rooms'));
    }

    public function testOptionsDoesNotConsumeGlobalBudget(): void {
        $ip = '198.51.100.' . random_int(1, 254);
        $previousIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = $ip;
        try {
            $mw = Middleware::rateLimit(1, 600);
            $mw(new Request('OPTIONS', '/api/rooms'));
            $mw(new Request('GET', '/api/rooms'));
            $this->expectException(HttpException::class);
            $mw(new Request('GET', '/api/rooms'));
        } finally {
            if ($previousIp === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previousIp;
            }
        }
    }
}
