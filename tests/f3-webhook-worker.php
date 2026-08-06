<?php
declare(strict_types=1);

/**
 * Worker CLI de entrega de webhook REAL (F3, escenarios f/i — concurrencia y
 * orphan). Corre el handler real HandleMercadoPagoWebhookAction con el adapter
 * real (firma HMAC real con el secret real del .env) contra la BD real.
 *
 * Uso:
 *   php tests/f3-webhook-worker.php <paymentId> <requestId>
 *
 * Salida: "OK:<httpCode>:<body>" o "ERR:<mensaje>" en stdout. Nunca imprime
 * secretos. Dos procesos lanzados a la vez sobre el MISMO payment demuestran
 * la idempotencia por indice unico (payment_id, event_type) + ON DUPLICATE KEY
 * (todo 11/14): exactamente 1 registro en processed_payments.
 */

require __DIR__ . '/../tests/bootstrap.php';
require __DIR__ . '/fixtures/F3RealSandboxFixtures.php';

use App\Core\Config;
use App\Core\Request;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Adapters\MercadoPagoAdapter;
use App\Features\Webhooks\Actions\HandleMercadoPagoWebhookAction;
use App\Test\Fixtures\F3RealSandboxFixtures;
use App\Test\Fixtures\F3OutboxEventDispatcher;

// Entorno deterministico: restaura los valores REALES del .env (proceso PHP
// fresco: Config::boot ya los cargo; restoreRealConfig es idempotente) y evita
// exit() de Response::json dentro del handler.
F3RealSandboxFixtures::restoreRealConfig();
Config::set('APP_ENV', 'testing');

$paymentId = (string)($argv[1] ?? '');
$requestId = (string)($argv[2] ?? '');
if ($paymentId === '' || $requestId === '') {
    fwrite(STDERR, "usage: php tests/f3-webhook-worker.php <paymentId> <requestId>\n");
    exit(2);
}

$pdo = F3RealSandboxFixtures::connect();
if ($pdo === null) {
    echo 'ERR:no-db';
    exit(2);
}

try {
    $adapter = new MercadoPagoAdapter();
    $repo = new ProvisionalBookingRepository($pdo);
    $dispatcher = new F3OutboxEventDispatcher($pdo);
    $action = new HandleMercadoPagoWebhookAction($pdo, $adapter, $repo, $dispatcher);

    $_GET['data_id'] = $paymentId;
    $request = new Request('POST', '/api/webhook', [
        'x-signature'  => F3RealSandboxFixtures::signatureHeader($paymentId, $requestId),
        'x-request-id' => $requestId,
    ], ['type' => 'payment', 'data' => ['id' => $paymentId]]);

    ob_start();
    $action($request);
    $body = (string)ob_get_clean();
    echo 'OK:' . http_response_code() . ':' . $body;
    exit(0);
} catch (Throwable $e) {
    echo 'ERR:' . $e->getMessage();
    exit(1);
}
