<?php
declare(strict_types=1);

namespace Tests\Unit\Reviews;

use App\Core\Config;
use App\Features\Reviews\Actions\GetReviewScoresAction;
use PHPUnit\Framework\TestCase;

/**
 * Ruta del fichero de scores de reseñas. El cron refresh-reviews lo escribe y
 * GET /api/reviews-score lo lee: tienen que resolver SIEMPRE al mismo sitio,
 * y ese sitio no puede ser la raiz del sistema (STORAGE_PATH vacio en el .env)
 * ni un directorio dentro del arbol que cada build reemplaza.
 */
final class ReviewScoresPathTest extends TestCase {
    protected function tearDown(): void {
        Config::set('STORAGE_PATH', '');
    }

    public function testExplicitStoragePathIsHonoured(): void {
        Config::set('STORAGE_PATH', '/tmp/usgar-storage');
        $this->assertSame('/tmp/usgar-storage/review-scores.json', GetReviewScoresAction::scoresFile());
    }

    public function testEmptyStoragePathDoesNotResolveToTheFilesystemRoot(): void {
        Config::set('STORAGE_PATH', '');
        $file = GetReviewScoresAction::scoresFile();
        $this->assertNotSame('/review-scores.json', $file);
        $this->assertStringEndsWith('/storage/review-scores.json', $file);
        // La raiz del proyecto, nunca app/ (que el postbuild copia a dist/).
        $this->assertStringStartsWith(dirname(__DIR__, 3) . '/storage/', $file);
    }

    public function testDefaultStorageDirectoryHasAnExistingParent(): void {
        Config::set('STORAGE_PATH', '');
        $file = GetReviewScoresAction::scoresFile();
        $this->assertDirectoryExists(dirname(dirname($file)));
    }
}
