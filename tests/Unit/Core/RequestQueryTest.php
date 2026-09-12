<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Request;
use PHPUnit\Framework\TestCase;

final class RequestQueryTest extends TestCase {
    /** @var array<string, mixed> */
    private array $serverBackup;

    /** @var array<string, mixed> */
    private array $getBackup;

    protected function setUp(): void {
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/api/cron/manual-review?action=redispatch&cart_id=CART-X';
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
    }

    public function testParsesQueryParametersFromDispatcherUri(): void {
        $request = new Request();

        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/cron/manual-review', $request->getPath());
        self::assertSame('redispatch', $request->getQuery('action'));
        self::assertSame('CART-X', $request->getQuery('cart_id'));
    }

    public function testExplicitPathQueryOverridesInjectedValueForThatKey(): void {
        $_GET = ['action' => 'list', 'keep' => 'yes'];

        $request = new Request('POST', '/api/cron/manual-review?action=redispatch&cart_id=CART-Y');

        self::assertSame('/api/cron/manual-review', $request->getPath());
        self::assertSame('redispatch', $request->getQuery('action'));
        self::assertSame('CART-Y', $request->getQuery('cart_id'));
        self::assertSame('yes', $request->getQuery('keep'));
    }
}
