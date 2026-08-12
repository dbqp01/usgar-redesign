<?php
declare(strict_types=1);

/**
 * Cron de limpieza de holds expirados (auditoría 2026-08-11: el endpoint
 * HTTP /api/cron/cleanup no tenía operador en producción — la tabla acumulaba
 * holds 'pending' vencidos sin marcar).
 * Uso: php cron/cleanup.php (cada 10 minutos)
 */

// Solo CLI (cron): nunca ejecucion via web (dist publicado expone cron/).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Features\Booking\Domain\ProvisionalBookingRepository;

$pdo = Database::getInstance()->getConnection();
if ($pdo === null) {
    echo "No database connection.\n";
    exit(1);
}

$repo = new ProvisionalBookingRepository($pdo);
$cleaned = $repo->cleanExpiredCarts();
echo "Cleanup completado: {$cleaned} holds expirados marcados.\n";
exit(0);
