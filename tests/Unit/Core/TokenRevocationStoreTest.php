<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\TokenRevocationStore;
use PHPUnit\Framework\TestCase;

final class TokenRevocationStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'usgar-token-store-tests';
        TokenRevocationStore::setDirectoryForTesting($this->directory);
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.revoked') ?: [] as $file) {
            @unlink($file);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.revoked') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
        TokenRevocationStore::setDirectoryForTesting(null);
    }

    public function testRevocationExpiresAndIsScoped(): void
    {
        TokenRevocationStore::revoke('abc', time() + 60, 'session');

        self::assertTrue(TokenRevocationStore::isRevoked('abc', 'session'));
        self::assertFalse(TokenRevocationStore::isRevoked('abc', 'panel'));

        TokenRevocationStore::revoke('expired', time() - 1, 'session');
        self::assertFalse(TokenRevocationStore::isRevoked('expired', 'session'));
    }

    public function testMalformedRevocationRecordFailsClosed(): void
    {
        TokenRevocationStore::revoke('malformed', time() + 60, 'session');
        $path = $this->directory . DIRECTORY_SEPARATOR . hash('sha256', 'session:malformed') . '.revoked';
        file_put_contents($path, 'not-a-timestamp');

        self::assertTrue(TokenRevocationStore::isRevoked('malformed', 'session'));
    }

    public function testDirectoryDisappearanceFailsClosedAfterHealthWasCached(): void
    {
        self::assertFalse(TokenRevocationStore::isRevoked('missing', 'session'));
        self::assertDirectoryExists($this->directory);
        self::assertTrue(rmdir($this->directory));

        self::assertTrue(TokenRevocationStore::isRevoked('missing', 'session'));
    }
}
