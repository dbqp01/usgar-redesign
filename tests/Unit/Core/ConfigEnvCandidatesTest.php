<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config;
use PHPUnit\Framework\TestCase;

final class ConfigEnvCandidatesTest extends TestCase
{
    public function testPrefersTheHostingerPanelFile(): void
    {
        // hPanel -> Environment variables escribe hbuilds/config/.env en la raiz
        // del dominio: esa es la fuente unica (verificado en el File Manager).
        $candidates = Config::envCandidates('/srv/dominio');

        self::assertSame(
            '/srv/dominio' . DIRECTORY_SEPARATOR . 'hbuilds' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . '.env',
            $candidates[0]
        );
        self::assertSame('/srv/dominio' . DIRECTORY_SEPARATOR . '.env', $candidates[1]);
        self::assertSame(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.env', $candidates[2]);
    }

    public function testFallsBackToTheProjectRootWithoutADeploymentBase(): void
    {
        putenv('ENV_FILE'); // sin override: solo la raiz del proyecto
        $candidates = Config::envCandidates(null);

        self::assertCount(1, $candidates);
        self::assertSame(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.env', $candidates[0]);
    }

    public function testEnvFileOverrideWinsInDev(): void
    {
        putenv('ENV_FILE=/tmp/usgar-test.env');
        try {
            $candidates = Config::envCandidates(null);

            self::assertSame('/tmp/usgar-test.env', $candidates[0]);
        } finally {
            putenv('ENV_FILE');
        }
    }

    public function testEnvFileOverrideIsIgnoredInProduction(): void
    {
        // En un despliegue real manda el .env de hPanel, nunca otro archivo.
        putenv('ENV_FILE=/tmp/usgar-test.env');
        try {
            $candidates = Config::envCandidates('/srv/dominio');

            self::assertStringStartsWith('/srv/dominio', $candidates[0]);
            self::assertNotContains('/tmp/usgar-test.env', $candidates);
        } finally {
            putenv('ENV_FILE');
        }
    }
}
