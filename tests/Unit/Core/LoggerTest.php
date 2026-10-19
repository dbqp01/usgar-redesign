<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Logger;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'usgar-logger-tests';
        @unlink($this->directory . DIRECTORY_SEPARATOR . 'app.log');
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . DIRECTORY_SEPARATOR . 'app.log');
        @unlink($this->directory . DIRECTORY_SEPARATOR . '.htaccess');
        @rmdir($this->directory);
        Logger::setDirectoryForTesting(null);
    }

    public function testWritesJsonEntryToTheConfiguredDirectory(): void
    {
        Logger::setDirectoryForTesting($this->directory);

        Logger::info('mensaje de prueba', ['k' => 'v']);

        $path = $this->directory . DIRECTORY_SEPARATOR . 'app.log';
        self::assertFileExists($path);
        $entry = json_decode((string) file_get_contents($path), true);
        self::assertSame('INFO', $entry['level']);
        self::assertSame('mensaje de prueba', $entry['message']);
        self::assertSame(['k' => 'v'], $entry['context']);
    }

    public function testPrefersTheDirectoryOutsideTheDeployTree(): void
    {
        // El deploy reemplaza public_html entero: el log debe vivir en la raiz
        // del dominio para no perderse en cada push.
        $candidates = Logger::candidateDirs('/srv/dominio');

        self::assertSame('/srv/dominio' . DIRECTORY_SEPARATOR . 'logs', $candidates[0]);
        self::assertSame(
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'logs',
            $candidates[1]
        );
    }

    public function testFallsBackToTheDeployTreeWithoutADeploymentBase(): void
    {
        $candidates = Logger::candidateDirs(null);

        self::assertCount(1, $candidates);
        self::assertSame(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'logs', $candidates[0]);
    }
}
