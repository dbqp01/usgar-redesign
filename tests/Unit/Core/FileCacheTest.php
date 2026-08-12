<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\FileCache;
use PHPUnit\Framework\TestCase;

/**
 * P3-1 (2026-08-12): cache de display en archivo con TTL por mtime.
 * Dir temporal por test: hermetico, no toca data/cache de runtime.
 */
final class FileCacheTest extends TestCase {
    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/filecache-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testMissReturnsNull(): void {
        $this->assertNull(FileCache::get('no-existe', 30, $this->dir));
    }

    public function testSetThenGetReturnsSameData(): void {
        $data = ['success' => true, 'rooms' => [['id' => 1, 'price' => 120.5]]];
        $this->assertTrue(FileCache::set('clave', $data, $this->dir));
        $this->assertSame($data, FileCache::get('clave', 30, $this->dir));
    }

    public function testExpiresAfterTtl(): void {
        FileCache::set('clave', ['rooms' => []], $this->dir);
        $file = $this->dir . '/' . hash('sha256', 'clave') . '.json';
        // Envejecer el archivo mas alla del TTL sin esperar
        touch($file, time() - 31);
        $this->assertNull(FileCache::get('clave', 30, $this->dir));
    }

    public function testExpiredFileIsOverwrittenOnSet(): void {
        FileCache::set('clave', ['v' => 1], $this->dir);
        $file = $this->dir . '/' . hash('sha256', 'clave') . '.json';
        touch($file, time() - 60);
        FileCache::set('clave', ['v' => 2], $this->dir);
        $this->assertSame(['v' => 2], FileCache::get('clave', 30, $this->dir));
    }
}
