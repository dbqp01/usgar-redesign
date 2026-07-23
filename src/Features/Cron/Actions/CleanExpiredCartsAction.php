<?php
declare(strict_types=1);

namespace App\Features\Cron\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Config;
use App\Features\Booking\Domain\ProvisionalBookingRepository;

/**
 * Acción ADR: POST /api/cron/cleanup
 * Tarea programada para limpiar carritos expirados (más de 15 minutos sin pago).
 */
class CleanExpiredCartsAction {
    private ProvisionalBookingRepository $bookingRepo;

    public function __construct(?ProvisionalBookingRepository $bookingRepo = null) {
        $this->bookingRepo = $bookingRepo ?? new ProvisionalBookingRepository();
    }

    public function __invoke(Request $request): void {
        // En entorno HTTP, exigir validación de token de cron (excepto CLI)
        if (PHP_SAPI !== 'cli') {
            $cronSecret = Config::get('CRON_SECRET');
            $providedSecret = $request->getHeader('x-cron-secret') ?? $request->getQuery('secret', '');

            if (empty($cronSecret)) {
                if (Config::isProduction()) {
                    Logger::error("CleanExpiredCartsAction: CRON_SECRET no está configurado en entorno de producción.");
                    Response::error('Cron secret non-configured.', 500);
                }
                $cronSecret = 'USGAR_CRON_SECRET_DEV_ONLY';
            }

            if (!hash_equals($cronSecret, $providedSecret)) {
                Logger::error("CleanExpiredCartsAction: Petición no autorizada al endpoint de cron.");
                Response::unauthorized('Invalid cron secret token.');
            }
        }

        $cleanedCount = $this->bookingRepo->cleanExpiredCarts();
        Logger::info("CleanExpiredCartsAction: Se limpiaron {$cleanedCount} bloqueos temporales expirados.");

        Response::json([
            'success'       => true,
            'cleaned_count' => $cleanedCount,
            'timestamp'     => date('Y-m-d H:i:s'),
        ]);
    }
}
