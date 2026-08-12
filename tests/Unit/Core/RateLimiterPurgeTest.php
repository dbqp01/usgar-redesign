<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * P3-3 (2026-08-12, OWASP API4:2023 resource consumption): purgeOldFiles
 * elimina archivos de limites viejos sin tocar los vivos ni archivos ajenos.
 */
final class RateLimiterPurgeTest extends TestCase {
    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/ratelimit-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function touchWithAge(string $file, int $ageSeconds): void {
        file_put_contents($file, '[]');
        touch($file, time() - $ageSeconds);
    }

    public function testRemovesOnlyFilesOlderThanMaxAge(): void {
        $this->touchWithAge($this->dir . '/limit_a.json', 5000);
        $this->touchWithAge($this->dir . '/limit_b.json', 100);
        $this->touchWithAge($this->dir . '/limit_c.json', 1300);

        $removed = RateLimiter::purgeOldFiles($this->dir, 1200);

        $this->assertSame(2, $removed);
        $this->assertFileDoesNotExist($this->dir . '/limit_a.json');
        $this->assertFileDoesNotExist($this->dir . '/limit_c.json');
        $this->assertFileExists($this->dir . '/limit_b.json');
    }

    public function testIgnoresNonLimitFiles(): void {
        file_put_contents($this->dir . '/README.md', 'hola');
        touch($this->dir . '/README.md', time() - 999999);

        $this->assertSame(0, RateLimiter::purgeOldFiles($this->dir, 60));
        $this->assertFileExists($this->dir . '/README.md');
    }

    public function testEmptyDirReturnsZero(): void {
        $this->assertSame(0, RateLimiter::purgeOldFiles($this->dir, 60));
    }
}
