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
use Exception;
use JsonException;

/**
 * Adaptador Hexagonal para la integracion con Mercado Pago.
 * Cumple con PaymentGatewayPortInterface.
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

        $payload = [
            'items' => [[
                'title'       => "Reserva USGAR Hotels — Habitación " . $idRoomType,
                'description' => "{$nights} noches ({$checkIn} → {$checkOut})",
                'quantity'    => 1,
                'unit_price'  => (float) round($totalPrice, 2),
                'currency_id' => 'USD',
            ]],
            'payer' => [
                'name'    => $firstName,
                'surname' => $lastName,
                'email'   => $guestEmail,
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
            $idempotencyKey = 'pref_' . $cartId;
            MercadoPagoConfig::setAccessToken($this->accessToken);
            $client = new PreferenceClient();
            $requestOptions = new RequestOptions();
            $requestOptions->setCustomHeaders(["X-Idempotency-Key: {$idempotencyKey}"]);
            
            $preference = $client->create($payload, $requestOptions);

            return [
                'id'                 => $preference->id,
                'init_point'         => $preference->init_point,
                'sandbox_init_point' => $preference->sandbox_init_point,
            ];

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
                Logger::error('MercadoPagoAdapter: Firma de webhook inválida.');
                return null;
            }
        }

        if ($type === 'payment' || isset($payload['data']['id'])) {
            return $this->getPaymentDetails((string)$dataId);
        }

        return null;
    }

    public function verifySignature(?string $signatureHeader, ?string $requestId, ?string $dataId): bool {
        if (empty($this->webhookSecret)) {
            Logger::error('MercadoPagoAdapter: Webhook Secret is not configured.');
            return false;
        }

        if (empty($signatureHeader) || empty($requestId) || empty($dataId)) {
            Logger::error('MercadoPagoAdapter: Headers requeridos ausentes en verifySignature.');
            return false;
        }

        $ts = '';
        $v1s = [];
        $parts = explode(',', $signatureHeader);
        foreach ($parts as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) {
                $key = trim($kv[0]);
                $val = trim($kv[1]);
                if ($key === 'ts') {
                    $ts = $val;
                } elseif ($key === 'v1') {
                    $v1s[] = $val;
                }
            }
        }

        if (empty($ts) || empty($v1s)) {
            Logger::error('MercadoPagoAdapter: Cabecera x-signature malformada.');
            return false;
        }

        $manifestParts = [
            'id:' . $dataId,
            'request-id:' . $requestId,
            'ts:' . $ts,
        ];
        $manifest = implode(';', $manifestParts) . ';';
        
        $computed = hash_hmac('sha256', $manifest, $this->webhookSecret);

        foreach ($v1s as $v1) {
            if (hash_equals($computed, $v1)) {
                return true;
            }
        }

        Logger::error("MercadoPagoAdapter: Signature match failed. DataId: {$dataId}");
        return false;
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
            $payment = $client->get((int)$paymentId);

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

        } catch (Exception $e) {
            Logger::error('MercadoPagoAdapter Exception en getPaymentDetails: ' . $e->getMessage());
            return null;
        }
    }

    private function isValidToken(string $token): bool {
        return str_starts_with($token, 'APP_USR') || str_starts_with($token, 'TEST-');
    }


}
