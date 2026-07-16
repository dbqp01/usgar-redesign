<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use Exception;

/**
 * Servicio de integración con la API de Mercado Pago (Checkout Pro).
 * Encapsula la creación de preferencias de pago y la validación de firmas HMAC-SHA256 de webhooks.
 */
class MercadoPagoService {
    private ?string $accessToken;
    private ?string $webhookSecret;
    private string $siteUrl;

    public function __construct() {
        $db = Database::getInstance();
        $this->accessToken = $db->getEnv('MERCADO_PAGO_ACCESS_TOKEN');
        $this->webhookSecret = $db->getEnv('MERCADO_PAGO_WEBHOOK_SECRET');
        $this->siteUrl = $db->getEnv('SITE_URL', 'http://localhost:8000');
    }

    /**
     * Crea una preferencia de pago en Mercado Pago (Checkout Pro).
     * Retorna la respuesta con 'init_point' (URL de pasarela) o datos MOCK en desarrollo.
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
        
        // Modo de desarrollo/mock si no hay un token real de Mercado Pago configurado
        if (empty($this->accessToken) || !str_starts_with($this->accessToken, 'APP_USR')) {
            $params = http_build_query([
                'bookingId' => $cartId,
                'amount' => $totalPrice,
                'checkIn' => $checkIn,
                'checkOut' => $checkOut,
                'guestName' => $guestName,
                'guestEmail' => $guestEmail
            ]);
            $mockUrl = "{$this->siteUrl}/book/mock-payment?{$params}";
            Logger::info("MercadoPagoService: Creación de preferencia en modo MOCK para Cart ID: {$cartId}");
            return [
                'id' => 'MOCK-PREF-' . time(),
                'init_point' => $mockUrl,
                'sandbox_init_point' => $mockUrl
            ];
        }

        $payload = [
            'items' => [[
                'title' => "Reserva USGAR Hotels — Habitación " . $idRoomType,
                'description' => "{$nights} noches ({$checkIn} → {$checkOut})",
                'quantity' => 1,
                'unit_price' => $totalPrice,
                'currency_id' => 'USD'
            ]],
            'external_reference' => $cartId,
            'back_urls' => [
                'success' => "{$this->siteUrl}/book/success?bookingId={$cartId}",
                'failure' => "{$this->siteUrl}/book?error=payment_failed&bookingId={$cartId}",
                'pending' => "{$this->siteUrl}/book/success?status=pending&bookingId={$cartId}"
            ],
            'auto_return' => 'approved',
            'notification_url' => "{$this->siteUrl}/api/webhook",
            'expires' => true,
            'expiration_date_to' => date('Y-m-d\TH:i:s.000P', strtotime('+15 minutes'))
        ];

        try {
            $ch = curl_init('https://api.mercadopago.com/checkout/preferences');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$this->accessToken}",
                "Content-Type: application/json"
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 400 || !$response) {
                Logger::error("MercadoPagoService: Error en creación de preferencia. HTTP: {$httpCode}. Respuesta: " . ($response ?: 'sin respuesta'));
                throw new Exception("Fallo en la comunicación con la API de Mercado Pago.");
            }

            return json_decode($response, true) ?? [];

        } catch (Exception $e) {
            Logger::error("MercadoPagoService Exception: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Valida la firma HMAC-SHA256 enviada por el Webhook de Mercado Pago para prevenir suplantación.
     */
    public function verifySignature(?string $signatureHeader, ?string $requestId, ?string $dataId): bool {
        if (empty($this->webhookSecret)) {
            Logger::info("MercadoPagoService: Webhook Secret ausente. Saltando validación en modo desarrollo.");
            return true;
        }

        if (empty($signatureHeader) || empty($requestId) || empty($dataId)) {
            Logger::error("MercadoPagoService: Parámetros/Headers requeridos ausentes en verifySignature.");
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
                if ($key === 'ts') {
                    $ts = $val;
                } elseif ($key === 'v1') {
                    $v1 = $val;
                }
            }
        }

        if (empty($ts) || empty($v1)) {
            Logger::error("MercadoPagoService: Cabecera x-signature malformada: '{$signatureHeader}'");
            return false;
        }

        // Construir manifiesto a firmar según el orden oficial
        $manifestParts = [];
        if ($dataId !== '') {
            $manifestParts[] = 'id:' . $dataId;
        }
        if ($requestId !== '') {
            $manifestParts[] = 'request-id:' . $requestId;
        }
        $manifestParts[] = 'ts:' . $ts;
        
        $manifest = implode(';', $manifestParts) . ';';

        // Calcular HMAC-SHA256 y comparar de manera segura (tiempo constante)
        $computed = hash_hmac('sha256', $manifest, $this->webhookSecret);

        if (!hash_equals($computed, $v1)) {
            Logger::error("MercadoPagoService: Firma de Webhook no coincide. Computada: {$computed}, Recibida: {$v1}");
            return false;
        }

        return true;
    }

    /**
     * Consulta el estado del pago directamente en la API de Mercado Pago.
     */
    public function getPaymentDetails(string $paymentId): ?array {
        if (str_contains($paymentId, 'MOCK') || empty($this->accessToken)) {
            Logger::info("MercadoPagoService: Obteniendo detalles de pago MOCK para ID: {$paymentId}");
            return [
                'status' => 'approved',
                'transaction_amount' => 90.0,
                'external_reference' => 'MOCK-CART-' . time()
            ];
        }

        try {
            $ch = curl_init("https://api.mercadopago.com/v1/payments/{$paymentId}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$this->accessToken}"
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 400 || !$response) {
                Logger::error("MercadoPagoService: Error al obtener detalles de pago. HTTP: {$httpCode}. Respuesta: " . ($response ?: 'sin respuesta'));
                return null;
            }

            return json_decode($response, true);

        } catch (Exception $e) {
            Logger::error("MercadoPagoService Exception en getPaymentDetails: " . $e->getMessage());
            return null;
        }
    }
}
