<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Shared;

use PHPUnit\Framework\TestCase;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Common\RequestOptions;
use MercadoPago\Net\MPHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;

/**
 * Spy de transporte HTTP del SDK (stub shape-only, mandato r10):
 * captura los headers salientes sin simular ningun resultado de MercadoPago.
 */
final class SpyMpHttpClient implements MPHttpClient {
    /** @var array<int|string, string> Headers crudos del ultimo request. */
    public array $lastHeaders = [];

    public function send(MPRequest $request): MPResponse {
        $this->lastHeaders = $request->getHeaders() ?? [];
        return new MPResponse(201, [
            'id'                 => 987654321,
            'status'             => 'approved',
            'status_detail'      => 'accredited',
            'external_reference' => 'USGAR-CART-1',
            'transaction_amount' => 100.0,
        ]);
    }
}

/**
 * Todo 1 (W1): composer-patch dx-php para getIdempotencyKey/headerExists.
 * Con la key en MAYUSCULAS el SDK debe enviar EXACTAMENTE UN header de
 * idempotencia con el valor custom (ni null, ni UUID, ni duplicado).
 */
final class SdkIdempotencyKeyTest extends TestCase {
    private function idempotencyHeaders(array $headers): array {
        return array_values(array_filter(
            $headers,
            static fn (string $h): bool => stripos($h, 'x-idempotency-key:') === 0
        ));
    }

    public function testStringFormCustomIdempotencyHeaderReachesTransport(): void {
        // Fix F3 (2026-08-06): el SDK espera el header como STRING "Name: value"
        // (doc oficial setCustomHeaders(["X-Idempotency-Key: <UUID>"])). El spy
        // captura los headers CRUDOS (los mismos que recibe CURLOPT_HTTPHEADER):
        // un header en forma de string sobrevive al array_merge del SDK y llega
        // a curl; la forma asociativa NO (curl descarta valores sin ':').
        $spy = new SpyMpHttpClient();
        $client = new PaymentClient($spy);

        $options = new RequestOptions();
        $options->setCustomHeaders(['X-Idempotency-Key: pay_test_1']);

        $client->create([
            'transaction_amount' => 100.0,
            'payment_method_id'  => 'visa',
            'payer'              => ['email' => 'cliente@test.com'],
        ], $options);

        $idemHeaders = $this->idempotencyHeaders($spy->lastHeaders);

        $this->assertCount(1, $idemHeaders, 'Debe salir EXACTAMENTE UN header de idempotencia en el transporte real.');
        $this->assertSame('X-Idempotency-Key: pay_test_1', $idemHeaders[0]);
    }

    public function testLowercaseStringFormAlsoReachesTransport(): void {
        // Documenta la convencion en minusculas (tambien como string "Name: value").
        $spy = new SpyMpHttpClient();
        $client = new PaymentClient($spy);

        $options = new RequestOptions();
        $options->setCustomHeaders(['x-idempotency-key: pay_test_1']);

        $client->create([
            'transaction_amount' => 100.0,
            'payment_method_id'  => 'visa',
            'payer'              => ['email' => 'cliente@test.com'],
        ], $options);

        $idemHeaders = $this->idempotencyHeaders($spy->lastHeaders);

        $this->assertCount(1, $idemHeaders);
        $this->assertSame('x-idempotency-key: pay_test_1', $idemHeaders[0]);
    }

    public function testAssociativeCustomHeaderDoesNotReachTransport(): void {
        // Pin del contrato de transporte: ['x-idempotency-key' => $uuid]
        // (asociativo) NO llega a curl — el array_merge del SDK lo deja como
        // valor sin ':' y curl lo descarta (MP responde 400 "Header
        // X-Idempotency-Key can't be null"). Por eso el adapter usa SIEMPRE
        // la forma string "Name: value".
        $spy = new SpyMpHttpClient();
        $client = new PaymentClient($spy);

        $options = new RequestOptions();
        $options->setCustomHeaders(['X-Idempotency-Key' => 'pay_test_1']);

        $client->create([
            'transaction_amount' => 100.0,
            'payment_method_id'  => 'visa',
            'payer'              => ['email' => 'cliente@test.com'],
        ], $options);

        $idemHeaders = $this->idempotencyHeaders($spy->lastHeaders);

        $this->assertCount(0, $idemHeaders, 'La forma asociativa no produce un header "Name: value" en el transporte.');
    }

    public function testSdkAddsUuidOnlyWhenNoCustomIdempotencyHeader(): void {
        $spy = new SpyMpHttpClient();
        $client = new PaymentClient($spy);

        $client->create([
            'transaction_amount' => 100.0,
            'payment_method_id'  => 'visa',
            'payer'              => ['email' => 'cliente@test.com'],
        ]);

        $idemHeaders = $this->idempotencyHeaders($spy->lastHeaders);

        $this->assertCount(1, $idemHeaders, 'Sin custom header el SDK debe autogenerar un UUID (string form), pero nunca duplicar.');
        $this->assertStringStartsWith('X-Idempotency-Key: ', $idemHeaders[0]);
    }
}
