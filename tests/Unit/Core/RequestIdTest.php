<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Request;
use PHPUnit\Framework\TestCase;

/**
 * P2-6 (2026-08-12): Request::getRequestId() respeta el header x-request-id
 * entrante (patron generate-if-missing) o genera uno CSPRNG de 32 hex.
 */
final class RequestIdTest extends TestCase {
    public function testGeneratesIdWhenHeaderMissing(): void {
        $request = new Request('GET', '/api/health');
        $id = $request->getRequestId();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        // Memoizado: misma instancia => mismo id.
        $this->assertSame($id, $request->getRequestId());
    }

    public function testRespectsIncomingHeader(): void {
        $request = new Request('GET', '/api/health', ['X-Request-ID' => 'cliente-provisto-123']);
        $this->assertSame('cliente-provisto-123', $request->getRequestId());
    }

    public function testDifferentRequestsGetDifferentIds(): void {
        $a = (new Request('GET', '/api/health'))->getRequestId();
        $b = (new Request('GET', '/api/health'))->getRequestId();
        $this->assertNotSame($a, $b);
    }
}
