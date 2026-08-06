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
    /**
     * @return array<int, string> Headers normalizados a formato "Name: value".
     */
    private function normalizedHeaders(SpyMpHttpClient $spy): array {
        $normalized = [];
        foreach ($spy->lastHeaders as $name => $value) {
            $normalized[] = is_int($name) ? $value : $name . ': ' . $value;
        }
        return $normalized;
    }

    private function idempotencyHeaders(array $headers): array {
        return array_values(array_filter(
            $headers,
            static fn (string $h): bool => stripos($h, 'x-idempotency-key:') === 0
        ));
    }

    public function testUppercaseKeySendsExactlyOneCustomIdempotencyHeader(): void {
        $spy = new SpyMpHttpClient();
        $client = new PaymentClient($spy);

        $options = new RequestOptions();
        $options->setCustomHeaders(['X-Idempotency-Key' => 'pay_test_1']); // MAYUSCULAS

        $client->create([
            'transaction_amount' => 100.0,
            'payment_method_id'  => 'visa',
            'payer'              => ['email' => 'cliente@test.com'],
        ], $options);

        $idemHeaders = $this->idempotencyHeaders($this->normalizedHeaders($spy));

        $this->assertCount(1, $idemHeaders, 'Debe salir EXACTAMENTE UN header de idempotencia (ni null, ni UUID, ni duplicado).');
        $this->assertSame('X-Idempotency-Key: pay_test_1', $idemHeaders[0]);
    }

    public function testLowercaseKeyStillSendsExactlyOneCustomHeader(): void {
        // Documenta la convencion previa del adapter (clave en minusculas ya funcionaba).
        $spy = new SpyMpHttpClient();
        $client = new PaymentClient($spy);

        $options = new RequestOptions();
        $options->setCustomHeaders(['x-idempotency-key' => 'pay_test_1']);

        $client->create([
            'transaction_amount' => 100.0,
            'payment_method_id'  => 'visa',
            'payer'              => ['email' => 'cliente@test.com'],
        ], $options);

        $idemHeaders = $this->idempotencyHeaders($this->normalizedHeaders($spy));

        $this->assertCount(1, $idemHeaders);
        $this->assertSame('x-idempotency-key: pay_test_1', $idemHeaders[0]);
    }

    public function testSdkAddsUuidOnlyWhenNoCustomIdempotencyHeader(): void {
        $spy = new SpyMpHttpClient();
        $client = new PaymentClient($spy);

        $client->create([
            'transaction_amount' => 100.0,
            'payment_method_id'  => 'visa',
            'payer'              => ['email' => 'cliente@test.com'],
        ]);

        $idemHeaders = $this->idempotencyHeaders($this->normalizedHeaders($spy));

        $this->assertCount(1, $idemHeaders, 'Sin custom header el SDK debe autogenerar un UUID, pero nunca duplicar.');
    }
}
