<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Shared;

require_once __DIR__ . '/../../../fixtures/W3WebhookFixtures.php';

use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Core\Request;
use App\Core\Events\EventDispatcher;
use App\Features\Shared\Adapters\MercadoPagoAdapter;
use App\Features\Webhooks\Actions\HandleMercadoPagoWebhookAction;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Test\Fixtures\W3WebhookFixtures;
use MercadoPago\MercadoPagoConfig;
use PDO;
use Exception;

/**
 * Todo 17 (W3): ACK < 22s garantizado en el path del webhook.
 *
 * Doc MP (MCP search_documentation "webhooks", es/MPE): MP espera HTTP
 * 200/201 en 22 segundos; si no, reintenta cada 15 min. El handler hace
 * getPaymentDetails (un fetch sincronico) ANTES del ACK, por lo que ese fetch
 * DEBE ejecutarse con 1 solo intento y timeout total acotado.
 *
 * - El SDK reintenta 3x por defecto (MercadoPagoConfig::getMaxRetries) con
 *   backoff exponencial sobre errores de transporte/timeout — 4 intentos de
 *   8s excederian la ventana de 22s.
 * - Fixture de red local (endpoint que acepta conexion pero nunca responde —
 *   listen sin accept, patron de MercadoPagoTimeoutTest W1): es un test de
 *   limites del cliente HTTP, NO un mock de MP (mandato r10).
 */
final class MercadoPagoWebhookAckTest extends TestCase {
    private string $originalBaseUrl;
    private int $originalMaxRetries;
    /** @var resource|null Socket del fixture de red colgado. */
    private $hungSocket = null;
    private int $hungPort = 0;

    protected function setUp(): void {
        Config::set('APP_ENV', 'testing');
        Config::set('MERCADO_PAGO_ACCESS_TOKEN', 'TEST-123456789');
        Config::set('MERCADO_PAGO_WEBHOOK_SECRET', W3WebhookFixtures::TEST_SECRET);
        Config::set('MP_STATEMENT_DESCRIPTOR', 'USGAR HOTELES CUSCO');
        Config::set('MP_BINARY_MODE', 'true');

        $this->originalBaseUrl = MercadoPagoConfig::$BASE_URL;
        $this->originalMaxRetries = MercadoPagoConfig::getMaxRetries();
    }

    protected function tearDown(): void {
        if (is_resource($this->hungSocket)) {
            fclose($this->hungSocket);
        }
        MercadoPagoConfig::$BASE_URL = $this->originalBaseUrl;
        MercadoPagoConfig::setMaxRetries($this->originalMaxRetries);
    }

    /**
     * Listener local que NUNCA acepta ni responde: el kernel completa el
     * handshake TCP, el cliente espera una respuesta HTTP que no llega ->
     * solo el timeout total corta la llamada.
     */
    private function startHungServer(): int {
        $attempts = 0;
        while ($attempts < 10) {
            $port = 52000 + random_int(0, 60);
            $sock = @stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
            if ($sock) {
                $this->hungSocket = $sock;
                $this->hungPort = $port;
                return $port;
            }
            $attempts++;
        }
        throw new Exception("No se pudo abrir el fixture de red colgado: $errstr");
    }

    private function captureResponse(callable $fn): array {
        ob_start();
        $fn();
        $body = (string) ob_get_clean();
        return ['code' => http_response_code(), 'body' => $body];
    }

    public function testGetPaymentDetailsRunsSingleAttemptAndRestoresRetries(): void {
        // Todo 17 (mecanismo): con maxRetries del SDK = 3 (default), el path
        // del webhook DEBE ejecutar getPaymentDetails con 1 SOLO intento
        // (4 intentos de 1s + backoff tardarian ~4s; 4x8s excederian el ACK
        // de 22s). Tras la llamada, la config global se restaura.
        MercadoPagoConfig::setMaxRetries(3);
        Config::set('MERCADO_PAGO_TIMEOUT_GET_MS', '1000');

        $port = $this->startHungServer();
        MercadoPagoConfig::$BASE_URL = "http://127.0.0.1:$port";

        $adapter = new MercadoPagoAdapter();
        $start = microtime(true);
        $result = $adapter->getPaymentDetails('42424242');
        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        $this->assertNull($result, 'El fetch colgado debe fallar (null) en el path del webhook.');
        $this->assertLessThan(2500, $elapsedMs,
            "1 solo intento: con los 3 retries del SDK tardaria ~4x el timeout (tardo {$elapsedMs}ms).");
        $this->assertSame(3, MercadoPagoConfig::getMaxRetries(),
            'La config global de retries debe restaurarse tras la llamada.');
    }

    public function testWebhookHandlerResponds500WithinAckWindowOnHungFetch(): void {
        // Todo 17 (QA+ del plan): el HANDLER con el adapter real y un endpoint
        // colgado (delay > timeout 8s) responde 500 en < 9s — dentro de la
        // ventana de ACK de 22s de MP. Si el fetch falla -> 500: MP reintenta
        // y la idempotencia (todos 11/12) protege contra reprocesamiento.
        Config::set('MERCADO_PAGO_TIMEOUT_GET_MS', '8000');
        MercadoPagoConfig::setMaxRetries(3); // el adapter debe desactivarlos en este path

        $port = $this->startHungServer();
        MercadoPagoConfig::$BASE_URL = "http://127.0.0.1:$port";

        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $bookingRepo = $this->createMock(ProvisionalBookingRepository::class);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $action = new HandleMercadoPagoWebhookAction($pdo, new MercadoPagoAdapter(), $bookingRepo, $dispatcher);

        $dataId = '555666777';
        $request = new Request('POST', '/api/webhook', [
            'x-signature' => W3WebhookFixtures::signatureHeader($dataId, 'req-ack'),
            'x-request-id' => 'req-ack',
        ], ['type' => 'payment', 'data' => ['id' => $dataId]]);

        $start = microtime(true);
        $response = $this->captureResponse(fn () => ($action)($request));
        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        $this->assertSame(500, $response['code'],
            'Fetch fallido -> 500 (MP reintentara; la idempotencia protege).');
        $this->assertLessThan(9000, $elapsedMs,
            "El handler debe responder antes de la ventana de ACK de 22s (tardo {$elapsedMs}ms).");
    }
}
