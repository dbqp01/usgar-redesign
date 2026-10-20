<?php
declare(strict_types=1);

namespace App\Features\Cron\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Config;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Ports\PmsPortInterface;

/**
 * Accion ADR: POST /api/cron/cleanup
 * Tarea programada para limpiar carritos expirados (mas de 15 minutos sin pago).
 * Ademas libera en el PMS los holds de esas reservas caducadas: sin eso la
 * habitacion queda bloqueada en el calendario de QloApps.
 */
class CleanExpiredCartsAction {
    private ProvisionalBookingRepository $bookingRepo;
    private PmsPortInterface $pms;

    public function __construct(ProvisionalBookingRepository $bookingRepo, PmsPortInterface $pms) {
        $this->bookingRepo = $bookingRepo;
        $this->pms = $pms;
    }

    public function __invoke(Request $request): void {
        // En entorno HTTP, exigir validacion de token de cron (excepto CLI)
        if (PHP_SAPI !== 'cli') {
            $cronSecret = Config::get('CRON_SECRET');
           // Cast (string) + return tras cada error: paridad con
           // RetryManualReviewAction. Sin cast, un ?secret[]=array daba
           // TypeError en hash_equals; sin return, en modo testing
           // (Response::json no hace exit) el cron corria igual tras
           // responder el error — el guard era inefectivo fuera de prod.
            // Cron secrets stay in a header so web-server/proxy access logs,
            // browser history and Referer headers cannot capture them.
            $providedSecret = (string)($request->getHeader('x-cron-secret') ?? '');

            if (empty($cronSecret)) {
                Logger::error("CleanExpiredCartsAction: CRON_SECRET no está configurado en servidor.");
                Response::error('Cron secret non-configured.', 500);
                return;
            }

            if (!hash_equals($cronSecret, $providedSecret)) {
                Logger::error("CleanExpiredCartsAction: Petición no autorizada al endpoint de cron.");
                Response::unauthorized('Invalid cron secret token.');
                return;
            }
        }

        $cleanedCount = $this->bookingRepo->cleanExpiredCarts();
        $releasedCount = $this->pms->releaseStaleWebserviceHolds();
        Logger::info("CleanExpiredCartsAction: Se limpiaron {$cleanedCount} bloqueos temporales expirados y se liberaron {$releasedCount} reservas del PMS.");

        Response::json([
            'success'             => true,
            'cleaned_count'       => $cleanedCount,
            'released_pms_holds'  => $releasedCount,
            'timestamp'           => date('Y-m-d H:i:s'),
        ]);
    }
}
