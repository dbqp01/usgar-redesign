<?php
declare(strict_types=1);

namespace App\Features\Reviews\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Config;

/**
 * Accion ADR: GET /api/reviews-score
 * Scores de reseñas de plataformas (Booking/KAYAK/Expedia) refrescados por el
 * cron refresh-reviews (RefreshReviewScoresAction). Si el cron no ha corrido
 * aún, devuelve scores vacíos y el frontend conserva los valores del build.
 */
class GetReviewScoresAction {
    public function __invoke(Request $request): void {
        $file = self::scoresFile();
        $payload = ['scores' => [], 'updatedAt' => null];
        if (file_exists($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $payload = $data;
            }
        }
        header('Cache-Control: public, max-age=3600');
        Response::json($payload);
    }

    public static function scoresFile(): string {
        $base = Config::get('STORAGE_PATH', dirname(__DIR__, 3) . '/storage');
        return rtrim((string) $base, '/') . '/review-scores.json';
    }
}
