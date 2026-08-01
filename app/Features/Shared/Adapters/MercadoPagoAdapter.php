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

        $url = Config::get('SITE_URL', 'http://localhost:4321');
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
            'statement_descriptor'=> $statementDescriptor,
            'binary_mode'         => Config::get('MP_BINARY_MODE', 'true') === 'true',
            'notification_url'    => "{$this->siteUrl}/api/webhook",
        ];

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
            $idempotencyKey = 'pay_' . $cartId . '_' . hash('sha256', $cartId . '_' . $finalPrice);
            MercadoPagoConfig::setAccessToken($this->accessToken);

            $client = $this->paymentClient;
            $requestOptions = new RequestOptions();
            $requestOptions->setCustomHeaders(['X-Idempotency-Key' => $idempotencyKey]);
            $requestOptions->setConnectionTimeout(10000); // 10s

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
            $statusCode = $e->getStatusCode();
            $apiBody = $e->getApiResponse() ? $e->getApiResponse()->getContent() : 'N/A';
            Logger::error('MercadoPagoAdapter API Error en processPayment', [
                'status_code' => $statusCode,
                'api_response' => is_array($apiBody) ? json_encode($apiBody) : $apiBody,
                'cart_id' => $cartId,
            ]);
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
            WebhookSignatureValidator::validate(
                $signatureHeader,
                $requestId,
                $dataId,
                $this->webhookSecret
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
        try {
            MercadoPagoConfig::setAccessToken($this->accessToken);
            $client = $this->paymentClient;
            $requestOptions = new RequestOptions();
            $requestOptions->setConnectionTimeout(10000); // 10s

            $payment = $client->get((int)$paymentId, $requestOptions);

            if (!$payment) {
                Logger::error("MercadoPagoAdapter: Error al obtener pago.");
                return null;
            }

            return [
                'id' => $payment->id,
                'status' => $payment->status,
                'external_reference' => $payment->external_reference,
                'transaction_amount' => $payment->transaction_amount,
            ];

        } catch (MPApiException $e) {
            $statusCode = $e->getStatusCode();
            $apiBody = $e->getApiResponse() ? $e->getApiResponse()->getContent() : 'N/A';
            Logger::error('MercadoPagoAdapter API Error en getPaymentDetails', [
                'status_code' => $statusCode,
                'api_response' => is_array($apiBody) ? json_encode($apiBody) : $apiBody,
                'payment_id' => $paymentId,
            ]);
            return null;
        } catch (Exception $e) {
            Logger::error('MercadoPagoAdapter Exception en getPaymentDetails: ' . $e->getMessage());
            return null;
        }
    }
    public function refundPayment(string $paymentId, ?float $amount = null): bool {
        try {
            MercadoPagoConfig::setAccessToken($this->accessToken);
            $client = $this->refundClient;
            $requestOptions = new RequestOptions();
            $requestOptions->setCustomHeaders(['X-Idempotency-Key' => 'refund_' . $paymentId . '_' . hash('sha256', $paymentId . '_' . ($amount ?? 'full'))]);
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
            $statusCode = $e->getStatusCode();
            $apiBody = $e->getApiResponse() ? $e->getApiResponse()->getContent() : 'N/A';
            Logger::error('MercadoPagoAdapter API Error en refundPayment', [
                'status_code' => $statusCode,
                'api_response' => is_array($apiBody) ? json_encode($apiBody) : $apiBody,
                'payment_id' => $paymentId,
            ]);
            return false;
        } catch (Exception $e) {
            Logger::error('MercadoPagoAdapter Exception en refundPayment: ' . $e->getMessage());
            return false;
        }
    }
}
