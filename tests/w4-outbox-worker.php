<?php
declare(strict_types=1);

/**
 * Worker de concurrencia para el cron de outbox (Wave 4, todo 20).
 * Uso: php tests/w4-outbox-worker.php <dispatchLog>
 *
 * Corre ProcessOutboxAction contra la BD real con un dispatcher in-memory
 * que cuenta cada entrega en un log compartido (los workers son procesos
 * PHP distintos) — doble del PORT de dominio, nunca un mock de MP.
 */

require __DIR__ . '/../tests/bootstrap.php';
require __DIR__ . '/fixtures/W4TestDoubles.php';
require __DIR__ . '/fixtures/W2TestDoubles.php';

use App\Core\Events\EventInterface;
use App\Features\Cron\Actions\ProcessOutboxAction;
use App\Test\Fixtures\TestDb;

$log = $argv[1] ?? '';
if ($log === '') {
    fwrite(STDERR, "log path required\n");
    exit(2);
}

$pdo = TestDb::connect();
if ($pdo === null) {
    fwrite(STDERR, "no db\n");
    exit(2);
}

$dispatch = function (EventInterface $event) use ($log): void {
    // Registra cada entrega REAL (el claim SKIP LOCKED + lease garantizan
    // que cada evento se entrega una sola vez aunque dos workers corran).
    file_put_contents($log, $event->getCartId() . PHP_EOL, FILE_APPEND | LOCK_EX);
    // Ventana de superposicion: ambos workers procesan "al mismo tiempo".
    usleep(150_000);
};

$action = new ProcessOutboxAction($pdo, $dispatch);
$stats = $action->run();
echo json_encode($stats) . "\n";
exit(0);
