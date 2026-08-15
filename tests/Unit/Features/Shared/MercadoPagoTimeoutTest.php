<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Shared;

use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Features\Shared\Adapters\MercadoPagoAdapter;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;
use MercadoPago\Net\HttpRequest;
use Exception;

/**
 * Stub de transporte shape-only (mandato r10): captura las opciones que el
 * MPDefaultHttpClient REAL entrega a curl; responde al instante (no simula
 * ningun resultado de MercadoPago).
 */
final class CapturingHttpRequest implements HttpRequest {
    /** @var array<int, mixed> */
    public array $capturedOptions = [];

    public function setOptionArray(array $options): void {
        $this->capturedOptions = $options;
    }

    public function execute(): string|false {
        return json_encode([
            'id' => 12345,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'external_reference' => 'USGAR-CART-T',
            'transaction_amount' => 100.0,
        ]);
    }

    public function getInfo(mixed $name): mixed {
        return 200;
    }

    public function error(): string {
        return '';
    }

    public function close(): void {}
}

/**
 * Todo 5 (W1): timeout TOTAL acotado en las llamadas SDK.
 * - Mecanismo: el MPDefaultHttpClient REAL debe entregar CURLOPT_TIMEOUT_MS a curl.
 * - Comportamiento (fixture de red local, permitido por r10): endpoint que acepta
 *   la conexion (handshake TCP del kernel) pero NUNCA responde -> el read colgado
 *   debe cortar en ~timeout, no colgar indefinidamente.
 * - QA-: llamada a transporte con respuesta inmediata -> OK (el timeout no rompe
 *   las llamadas normales).
 *
 * NOTA de entorno: los procesos hijos de php.exe no pueden hacer bind en esta
 * maquina (el built-in server falla con "reason: ?" y stream_socket_server con
 * error 0 enmascarado), asi que el server "colgado" vive en el proceso del test
 * con listen() sin accept() — el kernel completa el handshake igualmente.
 */
final class MercadoPagoTimeoutTest extends TestCase {
    private string $originalBaseUrl;
    private int $originalMaxRetries;
    /** @var resource|null Socket del fixture de red colgado. */
    private $hungSocket = null;
    private int $hungPort = 0;

    protected function setUp(): void {
        Config::set('MERCADO_PAGO_ACCESS_TOKEN', 'TEST-123456789');
        Config::set('MP_STATEMENT_DESCRIPTOR', 'USGAR HOTELES CUSCO');
        Config::set('MP_BINARY_MODE', 'true');

        $this->originalBaseUrl = MercadoPagoConfig::$BASE_URL;
        $this->originalMaxRetries = MercadoPagoConfig::getMaxRetries();

        MercadoPagoConfig::setMaxRetries(0); // 1 solo intento: medir el limite del cliente HTTP
    }

    protected function tearDown(): void {
        if (is_resource($this->hungSocket)) {
            fclose($this->hungSocket);
        }
        MercadoPagoConfig::$BASE_URL = $this->originalBaseUrl;
        MercadoPagoConfig::setMaxRetries($this->originalMaxRetries);
    }

    /**
     * Abre un listener local que NUNCA acepta ni responde (read colgado real):
     * el kernel completa el SYN-ACK del handshake, el cliente espera una
     * respuesta HTTP que no llega -> solo el timeout total corta la llamada.
     */
    private function startHungServer(): int {
        $attempts = 0;
        while ($attempts < 10) {
            $port = 51900 + random_int(0, 60);
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

    private function basePaymentData(): array {
        return [
            'external_reference' => 'USGAR-CART-T',
            'transaction_amount' => 100.0,
            'payment_method_id'  => 'visa',
            'payer'              => ['email' => 'cliente@test.com'],
        ];
    }

    public function testTotalTimeoutOptionIsDeliveredToCurl(): void {
        // Mecanismo del patch: el cliente HTTP REAL pone CURLOPT_TIMEOUT_MS.
        $transport = new CapturingHttpRequest();
        $httpClient = new MPDefaultHttpClient($transport);

        $request = new MPRequest('/v1/payments', 'POST', '{}', [], 1000);
        $httpClient->send($request);

        $this->assertArrayHasKey(CURLOPT_TIMEOUT_MS, $transport->capturedOptions,
            'MPDefaultHttpClient debe entregar CURLOPT_TIMEOUT_MS a curl (timeout TOTAL).');
        $this->assertSame(1000, $transport->capturedOptions[CURLOPT_TIMEOUT_MS]);
    }

    public function testHungEndpointFailsWithinTimeoutPlusOneSecond(): void {
        // QA+ del todo 5: endpoint colgado (delay > timeout) -> falla en ~1s, no cuelga.
        // Guard: sin el patch (CURLOPT_TIMEOUT_MS ausente) curl espera indefinidamente
        // y colgaria el suite; el RED limpio del mecanismo lo cubre el test
        // testTotalTimeoutOptionIsDeliveredToCurl.
        $probe = new CapturingHttpRequest();
        (new MPDefaultHttpClient($probe))->send(new MPRequest('/v1/payments', 'POST', '{}', [], 1000));
        if (!array_key_exists(CURLOPT_TIMEOUT_MS, $probe->capturedOptions)) {
            $this->markTestSkipped('Patch de timeout total no aplicado (CURLOPT_TIMEOUT_MS ausente): el RED lo cubre testTotalTimeoutOptionIsDeliveredToCurl.');
        }

        Config::set('MERCADO_PAGO_TIMEOUT_CREATE_MS', '1000');
        $port = $this->startHungServer();
        MercadoPagoConfig::$BASE_URL = "http://127.0.0.1:$port";

        $start = microtime(true);
        $thrown = null;
        try {
            (new MercadoPagoAdapter())->processPayment($this->basePaymentData());
        } catch (Exception $e) {
            $thrown = $e;
        }
        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        $this->assertNotNull($thrown, 'Con timeout total la llamada DEBE fallar (read colgado).');
        $this->assertLessThan(2500, $elapsedMs, "Debe fallar en ~1s (timeout 1000ms + 1s slack); tardo {$elapsedMs}ms.");
        $this->assertGreaterThanOrEqual(400, $elapsedMs, 'No debe fallar instantaneamente (el corte real lo produce el timeout).');
    }

    public function testFastTransportRespondsOk(): void {
        // QA- del todo 5: transporte con respuesta inmediata -> OK (el timeout
        // no afecta llamadas normales).
        Config::set('MERCADO_PAGO_TIMEOUT_CREATE_MS', '1000');
        $paymentClient = new \MercadoPago\Client\Payment\PaymentClient(new FastSpyHttpClient());

        $start = microtime(true);
        $result = (new MercadoPagoAdapter($paymentClient))->processPayment($this->basePaymentData());
        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        $this->assertNotNull($result);
        $this->assertSame('approved', $result['status']);
        $this->assertLessThan(2000, $elapsedMs, "La llamada normal no debe tardar; tardo {$elapsedMs}ms.");
    }
}

/**
 * Stub de transporte con respuesta inmediata (shape-only, sin simular MP).
 */
final class FastSpyHttpClient implements \MercadoPago\Net\MPHttpClient {
    public function send(MPRequest $request): MPResponse {
        return new MPResponse(200, [
            'id'                 => 12345,
            'status'             => 'approved',
            'status_detail'      => 'accredited',
            'external_reference' => 'USGAR-CART-T',
            'transaction_amount' => 100.0,
        ]);
    }
}
