<?php
declare(strict_types=1);

namespace App\Features\Shared\Adapters;

use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use App\Core\Config;
use App\Core\Logger;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Common\RequestOptions;
use MercadoPago\Webhook\WebhookSignatureValidator;
use MercadoPago\Exceptions\InvalidWebhookSignatureException;
use MercadoPago\Exceptions\MPApiException;
use Exception;

/**
 * Adaptador Hexagonal para la integracion con Mercado Pago.
 * Cumple con PaymentGatewayPortInterface.
 * Usa el SDK oficial dx-php v3 para verificacion de firma de webhooks.
 */
class MercadoPagoAdapter implements PaymentGatewayPortInterface {
    private readonly ?string $accessToken;
    private readonly ?string $webhookSecret;
    private readonly string $siteUrl;

    public function __construct() {
        $this->accessToken = Config::isProduction() 
            ? Config::get('MP_PROD_ACCESS_TOKEN', Config::get('MERCADO_PAGO_ACCESS_TOKEN'))
            : Config::get('MP_TEST_ACCESS_TOKEN', Config::get('MERCADO_PAGO_ACCESS_TOKEN'));
        $this->webhookSecret = Config::get('MERCADO_PAGO_WEBHOOK_SECRET');
        
        $url = Config::get('SITE_URL', 'http://localhost:8000');
        if (Config::isProduction() && str_starts_with($url, 'http://')) {
            $url = str_replace('http://', 'https://', $url);
        }
        $this->siteUrl = rtrim($url, '/');
    }

    public function createPreference(
        string $cartId,
        int $idRoomType,
        string $checkIn,
        string $checkOut,
        float $totalPrice,
        string $guestName,
        string $guestEmail
    ): array {
        $nights = (int)round((strtotime($checkOut) - strtotime($checkIn)) / 86400);

        if (empty($this->accessToken) || !$this->isValidToken($this->accessToken)) {
            if (!Config::isProduction()) {
                Logger::info("MercadoPagoAdapter: Generando preferencia Mock para desarrollo (Cart ID: {$cartId}).");
                return [
                    'id'                 => 'MP-MOCK-PREF-' . $cartId,
                    'init_point'         => "{$this->siteUrl}/book/success?bookingId={$cartId}&mock=true",
                    'sandbox_init_point' => "{$this->siteUrl}/book/success?bookingId={$cartId}&mock=true",
                ];
            }
            throw new Exception('Mercado Pago Access Token is not configured or invalid.');
        }

        $nameParts = explode(' ', trim($guestName), 2);
        $firstName = $nameParts[0] ?? $guestName;
        $lastName = $nameParts[1] ?? '';

        $currencyId = Config::get('MERCADO_PAGO_CURRENCY', 'PEN');
        $finalPrice = $totalPrice;

        // QloApps prices are in USD. If MP requires PEN, we must convert it.
        if ($currencyId === 'PEN') {
            $exchangeRate = (float) Config::get('EXCHANGE_RATE_USD_PEN', 3.80);
            $finalPrice = $totalPrice * $exchangeRate;
        }

        $payload = [
            'items' => [[
                'title'       => "Reserva USGAR Hotels — Habitacion " . $idRoomType,
                'description' => "{$nights} noches ({$checkIn} → {$checkOut})",
                'quantity'    => 1,
                'unit_price'  => (float) round($finalPrice, 2),
                'currency_id' => $currencyId,
            ]],
            'payer' => [
                'name'    => $firstName,
                'surname' => $lastName,
                // Omitimos el email. En Sandbox de Mercado Pago, enviar un email distinto 
                // al del test user que hace login genera un bucle de redirecciones (ERR_TOO_MANY_REDIRECTS).
            ],
            'external_reference' => $cartId,
            'back_urls' => [
                'success' => "{$this->siteUrl}/book/success?bookingId={$cartId}",
                'failure' => "{$this->siteUrl}/book?error=payment_failed&bookingId={$cartId}",
                'pending' => "{$this->siteUrl}/book/success?status=pending&bookingId={$cartId}",
            ],
            'expires'            => true,
            'expiration_date_to' => date('Y-m-d\TH:i:s.000P', strtotime('+15 minutes')),
        ];

        if (str_starts_with($this->siteUrl, 'https://')) {
            $payload['auto_return'] = 'approved';
            $payload['notification_url'] = "{$this->siteUrl}/api/webhook";
        }

        try {
            $idempotencyKey = 'pref_' . $cartId . '_' . time();
            MercadoPagoConfig::setAccessToken($this->accessToken);
            
            $client = new PreferenceClient();
            $requestOptions = new RequestOptions();
            $requestOptions->setCustomHeaders(["X-Idempotency-Key: {$idempotencyKey}"]);
            $requestOptions->setConnectionTimeout(10000); // 10s
            
            $preference = $client->create($payload, $requestOptions);

            $checkoutUrl = (!Config::isProduction() && !empty($preference->sandbox_init_point))
                ? $preference->sandbox_init_point
                : $preference->init_point;

            return [
                'id'                 => $preference->id,
                'init_point'         => $checkoutUrl,
                'sandbox_init_point' => $preference->sandbox_init_point,
            ];

        } catch (MPApiException $e) {
            $statusCode = $e->getStatusCode();
            $apiBody = $e->getApiResponse() ? $e->getApiResponse()->getContent() : 'N/A';
            Logger::error('MercadoPagoAdapter API Error', [
                'status_code' => $statusCode,
                'api_response' => is_array($apiBody) ? json_encode($apiBody) : $apiBody,
                'cart_id' => $cartId,
            ]);
            throw $e;
        } catch (Exception $e) {
            Logger::error('MercadoPagoAdapter Exception: ' . $e->getMessage());
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

        // Si se configuro webhookSecret, validar firma HMAC
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
        if (empty($this->accessToken)) {
            throw new Exception('Mercado Pago Access Token is not configured.');
        }
        if (str_contains($paymentId, 'MOCK')) {
            if (!Config::isProduction()) {
                $extRef = 'USGAR-287f0138cfc1';
                if (preg_match('/(USGAR-[a-f0-9]+)/i', $paymentId, $matches)) {
                    $extRef = $matches[1];
                }
                return [
                    'id'                 => $paymentId,
                    'status'             => 'approved',
                    'external_reference' => $extRef,
                    'transaction_amount' => 342.0,
                ];
            }
            throw new Exception('Cannot query mock payment.');
        }

        try {
            MercadoPagoConfig::setAccessToken($this->accessToken);
            $client = new PaymentClient();
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

    private function isValidToken(string $token): bool {
        return str_starts_with($token, 'APP_USR') || str_starts_with($token, 'TEST-');
    }
}
