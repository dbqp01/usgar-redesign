<?php
declare(strict_types=1);

namespace App\Features\Shared\Adapters;

use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use App\Core\Config;
use App\Core\Logger;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Payment\PaymentRefundClient;
use MercadoPago\Client\Common\RequestOptions;
use MercadoPago\Net\MPSearchRequest;
use MercadoPago\Webhook\WebhookSignatureValidator;
use MercadoPago\Exceptions\InvalidWebhookSignatureException;
use MercadoPago\Exceptions\MPApiException;
use Exception;

/**
 * Adaptador Hexagonal para la integracion con Mercado Pago.
 * Cumple con PaymentGatewayPortInterface.
 * Usa el SDK oficial dx-php v3 para verificacion de firma de webhooks.
 *
 * Cumplimiento del MP Quality Checklist:
 * - payer.email, payer.name, payer.surname, payer.phone
 * - items[].id, items[].title, items[].description, items[].category_id
 * - statement_descriptor
 * - binary_mode (configurable)
 * - notification_url
 * - external_reference
 * - Backend SDK (dx-php v3)
 * - X-Idempotency-Key header
 */
class MercadoPagoAdapter implements PaymentGatewayPortInterface {
    private readonly string $accessToken;
    private readonly ?string $webhookSecret;
    private readonly string $siteUrl;
    private readonly PaymentClient $paymentClient;
    private readonly PaymentRefundClient $refundClient;

    public function __construct(?PaymentClient $paymentClient = null, ?PaymentRefundClient $refundClient = null) {
        // Token unico: MERCADO_PAGO_ACCESS_TOKEN es la fuente de verdad.
        // No hay distincion sandbox/produccion — el token define el entorno.
        $token = Config::get('MERCADO_PAGO_ACCESS_TOKEN', '');
        if (empty($token)) {
            throw new Exception(
                'MERCADO_PAGO_ACCESS_TOKEN is not configured in .env. '
                . 'Use a TEST- prefix token for sandbox or APP_USR- for production.'
            );
        }
        $this->accessToken = $token;
        $this->webhookSecret = Config::get('MERCADO_PAGO_WEBHOOK_SECRET');

        $url = Config::get('SITE_URL') ?? '';
        $this->siteUrl = rtrim($url, '/');

        $this->paymentClient = $paymentClient ?? new PaymentClient();
        $this->refundClient = $refundClient ?? new PaymentRefundClient();
    }

    public function processPayment(array $paymentData): ?array {
        $cartId = $paymentData['external_reference'] ?? '';
        
        $finalPrice = (float)($paymentData['transaction_amount'] ?? 0);

        $statementDescriptor = Config::get('MP_STATEMENT_DESCRIPTOR', 'USGAR HOTELES CUSCO');

        // Map Payer Data
        $payer = $paymentData['payer'] ?? [];
        
        $payload = [
            'transaction_amount'  => (float) round($finalPrice, 2),
            'payment_method_id'   => $paymentData['payment_method_id'] ?? '',
            'payer'               => $payer,
            'external_reference'  => $cartId,
            'currency_id'         => Config::get('MERCADO_PAGO_CURRENCY', 'PEN'), // todo 4: explicito
            'statement_descriptor'=> $statementDescriptor,
            'binary_mode'         => Config::get('MP_BINARY_MODE', 'true') === 'true',
            // Todo 3 (clausula r2): SIN notification_url en el create — la config
            // del panel / save_webhook gobierna (doc MP: la URL del create tendria
            // prioridad sobre el panel; se mantiene AUSENTE a proposito).
        ];

        if (!empty($paymentData['additional_info'])) {
            $payload['additional_info'] = $paymentData['additional_info'];
        }

        if (!empty($paymentData['token'])) {
            $payload['token'] = $paymentData['token'];
        }
        if (!empty($paymentData['issuer_id'])) {
            $payload['issuer_id'] = (string) $paymentData['issuer_id'];
        }
        if (!empty($paymentData['installments'])) {
            $payload['installments'] = (int) $paymentData['installments'];
        }


        try {
            MercadoPagoConfig::setAccessToken($this->accessToken);

            $client = $this->paymentClient;
            $requestOptions = new RequestOptions();
            // Todo 2: UUID v4 FRESCO por intento. Una key determinista por carrito
            // bloqueaba reintentos legitimos (MP deduplica por X-Idempotency-Key).
            $requestOptions->setCustomHeaders(['x-idempotency-key' => $this->generateUuidV4()]);
            $requestOptions->setConnectionTimeout((int) Config::get('MERCADO_PAGO_TIMEOUT_CREATE_MS', '15000')); // 15s total

            $payment = $client->create($payload, $requestOptions);

            if (!$payment) {
                return null;
            }

            return [
                'id'                 => $payment->id,
                'status'             => $payment->status,
                'status_detail'      => $payment->status_detail,
                'external_reference' => $payment->external_reference,
            ];

        } catch (MPApiException $e) {
            $this->logApiError($e, 'MercadoPagoAdapter API Error en processPayment', ['cart_id' => $cartId]);
            throw $e;
        } catch (Exception $e) {
            Logger::error('MercadoPagoAdapter Exception en processPayment: ' . $e->getMessage());
            throw $e;
        }
    }

    public function verifyNotification(array $payload, array $headers = []): ?array {
        $signatureHeader = $headers['x-signature'] ?? $headers['X-Signature'] ?? null;
        $requestId = $headers['x-request-id'] ?? $headers['X-Request-Id'] ?? null;

        $dataId = $payload['data']['id'] ?? $payload['id'] ?? null;
        $type = $payload['type'] ?? $payload['topic'] ?? '';

        if ($dataId === null) {
            return null;
        }

        // Validar firma HMAC si webhookSecret esta configurado
        if (!empty($this->webhookSecret)) {
            if (!$this->verifySignature($signatureHeader, $requestId, (string)$dataId)) {
                Logger::error('MercadoPagoAdapter: Firma de webhook invalida.');
                return null;
            }
        }

        if ($type === 'payment' || isset($payload['data']['id'])) {
            return $this->getPaymentDetails((string)$dataId);
        }

        return null;
    }

    /**
     * Valida la firma del webhook usando el WebhookSignatureValidator oficial del SDK.
     * Esto garantiza paridad algoritmica exacta con lo que Mercado Pago computa.
     */
    public function verifySignature(?string $signatureHeader, ?string $requestId, ?string $dataId): bool {
        if (empty($this->webhookSecret)) {
            Logger::error('MercadoPagoAdapter: Webhook Secret is not configured.');
            return false;
        }

        try {
            // Todo 15: toleranceSeconds=300 — ventana de replay de 5 min. La
            // doc MP (webhooks x-signature) declara ts en MILISEGUNDOS y el
            // SDK compara contra now() en ms (verificado empiricamente en W3).
            // Sin tolerance, cualquier replay del header era aceptado.
            WebhookSignatureValidator::validate(
                $signatureHeader,
                $requestId,
                $dataId,
                $this->webhookSecret,
                300
            );
            Logger::info("MercadoPagoAdapter: Firma de webhook validada correctamente. DataId: {$dataId}");
            return true;
        } catch (InvalidWebhookSignatureException $e) {
            Logger::error("MercadoPagoAdapter: SDK Signature validation failed - Reason: {$e->getMessage()}, DataId: {$dataId}, RequestId: {$requestId}");
            return false;
        } catch (\InvalidArgumentException $e) {
            Logger::error("MercadoPagoAdapter: Invalid argument in signature validation - {$e->getMessage()}");
            return false;
        }
    }

    public function getPaymentDetails(string $paymentId): ?array {
        // Todo 7 (QA-): guard ctype_digit antes del cast — un id alfanumerico
        // no debe llegar a la API como (int)0 ni lanzar TypeError.
        if (!ctype_digit($paymentId)) {
            Logger::error("MercadoPagoAdapter: paymentId no numerico ignorado: {$paymentId}");
            return null;
        }

        // Todo 17: el path del webhook corre ANTES del ACK (MP espera
        // 200/201 en 22s, doc webhooks). El SDK reintenta 3x por defecto
        // con backoff exponencial sobre timeouts (4x8s excederia la ventana);
        // RequestOptions no expone retries, asi que el unico knob es la
        // config global: 1 SOLO intento + restauracion en finally.
        $previousRetries = MercadoPagoConfig::getMaxRetries();
        MercadoPagoConfig::setMaxRetries(0);

        try {
            MercadoPagoConfig::setAccessToken($this->accessToken);
            $client = $this->paymentClient;
            $requestOptions = new RequestOptions();
            $requestOptions->setConnectionTimeout((int) Config::get('MERCADO_PAGO_TIMEOUT_GET_MS', '8000')); // 8s total (webhook ACK 22s)

            $payment = $client->get((int)$paymentId, $requestOptions);

            if (!$payment) {
                Logger::error("MercadoPagoAdapter: Error al obtener pago.");
                return null;
            }

            return [
                'id' => $payment->id,
                'status' => $payment->status,
                'status_detail' => $payment->status_detail, // todo 7: motivos de rechazo MP
                'external_reference' => $payment->external_reference,
                'transaction_amount' => $payment->transaction_amount,
            ];

        } catch (MPApiException $e) {
            $this->logApiError($e, 'MercadoPagoAdapter API Error en getPaymentDetails', ['payment_id' => $paymentId]);
            return null;
        } catch (Exception $e) {
            Logger::error('MercadoPagoAdapter Exception en getPaymentDetails: ' . $e->getMessage());
            return null;
        } finally {
            MercadoPagoConfig::setMaxRetries($previousRetries);
        }
    }

    /**
     * Busca un pago por external_reference (GET /v1/payments/search).
     * Consumido por el todo 31 (W5) para evitar segundos cobros tras commit-falla.
     * Devuelve el primer resultado normalizado, o null si no existe.
     */
    public function findPaymentByExternalReference(string $externalRef): ?array {
        try {
            MercadoPagoConfig::setAccessToken($this->accessToken);
            $requestOptions = new RequestOptions();
            $requestOptions->setConnectionTimeout((int) Config::get('MERCADO_PAGO_TIMEOUT_GET_MS', '8000')); // 8s total

            $search = $this->paymentClient->search(
                new MPSearchRequest(1, 0, ['external_reference' => $externalRef]),
                $requestOptions
            );

            $results = $search->results ?? null;
            if (!is_array($results) || count($results) === 0) {
                return null;
            }

            $first = $results[0];
            return [
                'id' => (int) ($first->id ?? 0),
                'status' => (string) ($first->status ?? ''),
                'status_detail' => (string) ($first->status_detail ?? ''),
                'external_reference' => (string) ($first->external_reference ?? $externalRef),
                'transaction_amount' => (float) ($first->transaction_amount ?? 0.0),
            ];

        } catch (MPApiException $e) {
            $this->logApiError($e, 'MercadoPagoAdapter API Error en findPaymentByExternalReference', ['external_reference' => $externalRef]);
            return null;
        } catch (Exception $e) {
            Logger::error('MercadoPagoAdapter Exception en findPaymentByExternalReference: ' . $e->getMessage());
            return null;
        }
    }
    public function refundPayment(string $paymentId, ?float $amount = null): bool {
        try {
            MercadoPagoConfig::setAccessToken($this->accessToken);
            $client = $this->refundClient;
            $requestOptions = new RequestOptions();
            // Misma convencion de clave en minusculas que processPayment (bug SDK getIdempotencyKey).
            $requestOptions->setCustomHeaders(['x-idempotency-key' => 'refund_' . $paymentId . '_' . hash('sha256', $paymentId . '_' . ($amount ?? 'full'))]);
            $requestOptions->setConnectionTimeout(10000); // 10s

            if ($amount !== null) {
                // Reembolso parcial
                $client->refund((int)$paymentId, $amount, $requestOptions);
            } else {
                // Reembolso total (el amount se toma del monto total de la transaccion, o se envia null para total)
                $client->refundTotal((int)$paymentId, $requestOptions);
            }

            Logger::info("MercadoPagoAdapter: Reembolso procesado exitosamente para Payment ID {$paymentId}");
            return true;
        } catch (MPApiException $e) {
            $this->logApiError($e, 'MercadoPagoAdapter API Error en refundPayment', ['payment_id' => $paymentId]);
            return false;
        } catch (Exception $e) {
            Logger::error('MercadoPagoAdapter Exception en refundPayment: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Genera un UUID v4 fresco por llamada (idempotency key por intento).
     * Mismo algoritmo que el SDK dx-php (random_bytes + version/bits).
     */
    private function generateUuidV4(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Registra un error de API de Mercado Pago con status y body normalizados.
     *
     * @param array<string, mixed> $context
     */
    private function logApiError(MPApiException $e, string $message, array $context): void {
        $statusCode = $e->getStatusCode();
        $apiBody = $e->getApiResponse() ? $e->getApiResponse()->getContent() : 'N/A';
        $context['status_code'] = $statusCode;
        $context['api_response'] = is_array($apiBody) ? json_encode($apiBody) : $apiBody;
        Logger::error($message, $context);
    }
}
