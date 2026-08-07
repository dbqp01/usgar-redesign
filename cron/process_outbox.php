<?php
declare(strict_types=1);

// Solo CLI (cron): nunca ejecucion via web (dist publicado expone cron/).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Bootstrap compartido: autoloaders, Config, Container, bindings y listeners de dominio.
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Core\Events\EventDispatcher;
use App\Features\Cron\Actions\ProcessOutboxAction;

$pdo = Database::getInstance()->getConnection();
if ($pdo === null) {
    echo "No database connection.\n";
    exit(1);
}

// Todo 19/20: la logica (auto-heal de columnas attempts/next_attempt_at con
// backfill, claim FOR UPDATE SKIP LOCKED con lease, reintento de FAILED con
// backoff, reclaim de IN_PROGRESS huerfanos y residuales, set_time_limit(0))
// vive en ProcessOutboxAction — mismo patron que ReconcilePaymentsAction.
$dispatcher = EventDispatcher::getInstance();
$action = new ProcessOutboxAction($pdo, fn ($event) => $dispatcher->dispatchNow($event));

try {
    $stats = $action->run();
    echo "Outbox completado: claimed={$stats['claimed']}, completed={$stats['completed']}, "
        . "failed={$stats['failed']}, reclaimed={$stats['reclaimed']}, terminal={$stats['terminal']}\n";
    if ($stats['claimed'] === 0 && $stats['reclaimed'] === 0) {
        echo "No events to process.\n";
    }
    exit(0);
} catch (\Throwable $e) {
    echo "Cron process_outbox Error: " . $e->getMessage() . "\n";
    exit(1);
}
