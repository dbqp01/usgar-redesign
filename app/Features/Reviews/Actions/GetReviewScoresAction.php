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
        Response::success($payload);
    }

    public static function scoresFile(): string {
        $base = Config::get('STORAGE_PATH');
        if ($base === null || trim($base) === '') {
            // Fuera del arbol que el deploy reemplaza (igual que los logs): si el
            // fichero vive dentro de public_html cada build lo borra y el cron
            // refresh-reviews no sirve de nada.
            $base = (Config::deploymentBase() ?? dirname(__DIR__, 3)) . '/storage';
        }
        return rtrim($base, '/') . '/review-scores.json';
    }
}
