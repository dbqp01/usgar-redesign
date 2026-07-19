<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use Exception;

/**
 * Servicio de integración con la API de Mercado Pago (Checkout Pro).
 * Fix: Acepta tokens APP_USR- y TEST- como válidos.
 * Fix: SSL verification, CONNECTTIMEOUT, JSON_THROW_ON_ERROR.
 */
class MercadoPagoService {
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

    /**
     * Crea una preferencia de pago en Mercado Pago (Checkout Pro).
     * Fix: Acepta tokens APP_USR- (Checkout Pro, Point, QR) y TEST- (Checkout API, Bricks).
     */
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

        // Requiere token válido
        if (empty($this->accessToken) || !$this->isValidToken($this->accessToken)) {
            throw new Exception('Mercado Pago Access Token is not configured or invalid.');
        }

        $payload = [
            'items' => [[
                'title'       => "Reserva USGAR Hotels — Habitación " . $idRoomType,
                'description' => "{$nights} noches ({$checkIn} → {$checkOut})",
                'quantity'    => 1,
                'unit_price'  => $totalPrice,
                'currency_id' => 'USD',
            ]],
            'external_reference' => $cartId,
            'back_urls' => [
                'success' => "{$this->siteUrl}/book/success?bookingId={$cartId}",
                'failure' => "{$this->siteUrl}/book?error=payment_failed&bookingId={$cartId}",
                'pending' => "{$this->siteUrl}/book/success?status=pending&bookingId={$cartId}",
            ],
            'expires'          => true,
            'expiration_date_to' => date('Y-m-d\TH:i:s.000P', strtotime('+15 minutes')),
        ];

        // Mercado Pago requiere HTTPS en back_urls para habilitar auto_return
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

        } catch (\JsonException $e) {
            Logger::error('MercadoPagoService: Error parseando respuesta de preferencia: ' . $e->getMessage());
            throw new Exception('Respuesta inválida desde la API de Mercado Pago.');
        } catch (Exception $e) {
            Logger::error('MercadoPagoService Exception: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Valida la firma HMAC-SHA256 del Webhook de Mercado Pago.
     * Implementación alineada con el SDK oficial de Mercado Pago (Context7 reference).
     */
    public function verifySignature(?string $signatureHeader, ?string $requestId, ?string $dataId): bool {
        if (empty($this->webhookSecret)) {
            Logger::error('MercadoPagoService: Webhook Secret is not configured.');
            return false;
        }

        if (empty($signatureHeader) || empty($requestId) || empty($dataId)) {
            Logger::error('MercadoPagoService: Headers requeridos ausentes en verifySignature.');
            return false;
        }

        // Parsear cabecera de firma (Formato: ts=TIMESTAMP,v1=HASH)
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
            Logger::error("MercadoPagoService: Cabecera x-signature malformada: '{$signatureHeader}'");
            return false;
        }

        // Construir manifiesto según el orden oficial del SDK
        $manifestParts = [];
        if ($dataId !== '') {
            $manifestParts[] = 'id:' . $dataId;
        }
        if ($requestId !== '') {
            $manifestParts[] = 'request-id:' . $requestId;
        }
        $manifestParts[] = 'ts:' . $ts;

        $manifest = implode(';', $manifestParts) . ';';

        // HMAC-SHA256 + comparación de tiempo constante
        $computed = hash_hmac('sha256', $manifest, $this->webhookSecret);

        if (!hash_equals($computed, $v1)) {
            Logger::error("MercadoPagoService: Firma no coincide. Computada: {$computed}, Recibida: {$v1}");
            return false;
        }

        return true;
    }

    /**
     * Consulta el estado del pago en la API de Mercado Pago.
     */
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
                Logger::error("MercadoPagoService: cURL error: {$curlError}");
                return null;
            }

            if ($httpCode >= 400 || !$response) {
                Logger::error("MercadoPagoService: Error al obtener pago. HTTP: {$httpCode}");
                return null;
            }

            return json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        } catch (\JsonException $e) {
            Logger::error('MercadoPagoService: JSON parse error en getPaymentDetails: ' . $e->getMessage());
            return null;
        } catch (Exception $e) {
            Logger::error('MercadoPagoService Exception en getPaymentDetails: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Verifica si un token de Mercado Pago tiene un prefijo válido.
     * APP_USR-: Checkout Pro, Point, QR. TEST-: Checkout API, Bricks, Payments API.
     */
    private function isValidToken(string $token): bool {
        return str_starts_with($token, 'APP_USR') || str_starts_with($token, 'TEST-');
    }

    /**
     * Ejecuta un POST JSON con cURL y retorna el body de respuesta.
     */
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
            Logger::error("MercadoPagoService: HTTP {$httpCode}. Respuesta: " . ($response ?: 'sin respuesta'));
            throw new Exception('Fallo en la comunicación con la API de Mercado Pago.');
        }

        return $response;
    }
}
