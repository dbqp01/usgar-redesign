<?php
declare(strict_types=1);

namespace App\Features\Shared\Adapters;

use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use App\Core\Config;
use App\Core\Logger;
use Exception;
use JsonException;

/**
 * Adaptador Hexagonal para la integración con Mercado Pago.
 * Cumple con PaymentGatewayPortInterface.
 */
class MercadoPagoAdapter implements PaymentGatewayPortInterface {
    private readonly ?string $accessToken;
    private readonly ?string $webhookSecret;
    private readonly string $siteUrl;

    public function __construct() {
        $this->accessToken = Config::get('MERCADO_PAGO_ACCESS_TOKEN');
        $this->webhookSecret = Config::get('MERCADO_PAGO_WEBHOOK_SECRET');
        
        $url = Config::get('SITE_URL', 'http://localhost:8000');
        if (Config::isProduction() && str_starts_with($url, 'http://')) {
            $url = str_replace('http://', 'https://', $url);
        }
        $this->siteUrl = $url;
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
                'unit_price'  => $totalPrice,
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
            $response = $this->curlPost(
                'https://api.mercadopago.com/checkout/preferences',
                $payload,
                $idempotencyKey
            );

            return json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        } catch (JsonException $e) {
            Logger::error('MercadoPagoAdapter: Error parseando respuesta de preferencia: ' . $e->getMessage());
            throw new Exception('Respuesta inválida desde la API de Mercado Pago.');
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

        // Si se configuró webhookSecret, validar firma HMAC
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
        $v1 = '';
        $parts = explode(',', $signatureHeader);
        foreach ($parts as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) {
                $key = trim($kv[0]);
                $val = trim($kv[1]);
                match ($key) {
                    'ts' => $ts = $val,
                    'v1' => $v1 = $val,
                    default => null,
                };
            }
        }

        if ($ts === '' || $v1 === '') {
            Logger::error("MercadoPagoAdapter: Cabecera x-signature malformada: '{$signatureHeader}'");
            return false;
        }

        $manifestParts = [];
        if ($dataId !== '') {
            $manifestParts[] = 'id:' . $dataId;
        }
        if ($requestId !== '') {
            $manifestParts[] = 'request-id:' . $requestId;
        }
        $manifestParts[] = 'ts:' . $ts;

        $manifest = implode(';', $manifestParts) . ';';
        $computed = hash_hmac('sha256', $manifest, $this->webhookSecret);

        return hash_equals($computed, $v1);
    }

    public function getPaymentDetails(string $paymentId): ?array {
        if (empty($this->accessToken)) {
            throw new Exception('Mercado Pago Access Token is not configured.');
        }
        if (str_contains($paymentId, 'MOCK')) {
            throw new Exception('Cannot query mock payment.');
        }

        try {
            $ch = curl_init("https://api.mercadopago.com/v1/payments/{$paymentId}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$this->accessToken}",
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError !== '') {
                Logger::error("MercadoPagoAdapter: cURL error: {$curlError}");
                return null;
            }

            if ($httpCode >= 400 || !$response) {
                Logger::error("MercadoPagoAdapter: Error al obtener pago. HTTP: {$httpCode}");
                return null;
            }

            return json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        } catch (JsonException $e) {
            Logger::error('MercadoPagoAdapter: JSON parse error en getPaymentDetails: ' . $e->getMessage());
            return null;
        } catch (Exception $e) {
            Logger::error('MercadoPagoAdapter Exception en getPaymentDetails: ' . $e->getMessage());
            return null;
        }
    }

    private function isValidToken(string $token): bool {
        return str_starts_with($token, 'APP_USR') || str_starts_with($token, 'TEST-');
    }

    private function curlPost(string $url, array $payload, ?string $idempotencyKey = null): string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        
        $headers = [
            "Authorization: Bearer {$this->accessToken}",
            'Content-Type: application/json',
        ];
        if ($idempotencyKey !== null) {
            $headers[] = "X-Idempotency-Key: {$idempotencyKey}";
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            throw new Exception("cURL error: {$curlError}");
        }

        if ($httpCode >= 400 || !$response) {
            Logger::error("MercadoPagoAdapter: HTTP {$httpCode}. Respuesta: " . ($response ?: 'sin respuesta'));
            throw new Exception('Fallo en la comunicación con la API de Mercado Pago.');
        }

        return $response;
    }
}
