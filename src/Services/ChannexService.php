<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use Exception;

/**
 * Servicio de integración con Channex (Channel Manager).
 * Envía reservas confirmadas al endpoint "Open Channel" de Channex
 * para descontar inventario en OTAs (Booking, Expedia, Airbnb).
 *
 * Fix: occupancy dinámica (no hardcoded), SSL verification, Config centralizado.
 */
class ChannexService {
    private readonly ?string $apiKey;
    private readonly ?string $apiUrl;
    private readonly ?string $propertyId;

    public function __construct() {
        $this->apiKey = Config::get('CHANNEX_API_KEY');
        $this->apiUrl = Config::get('CHANNEX_API_URL', 'https://api.channex.io/api/v1');
        $this->propertyId = Config::get('CHANNEX_PROPERTY_ID');
    }

    /**
     * Envía la reserva confirmada de QloApps a Channex.
     *
     * @param int $adults Número real de adultos de la reserva (ya no hardcoded)
     */
    public function createBooking(
        string $bookingId,
        string $checkIn,
        string $checkOut,
        int $idRoomType,
        float $totalPrice,
        string $guestName,
        string $guestEmail,
        string $guestPhone = '',
        int $adults = 2
    ): bool {
        if (empty($this->apiKey) || empty($this->propertyId)) {
            throw new Exception('Channex API Key or Property ID is not configured.');
        }

        try {
            $nameParts = explode(' ', trim($guestName), 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? 'Guest';

            // Resolver IDs de Room Type y Rate Plan en Channex dinámicamente según la habitación
            $channexRoomId = $this->resolveChannexRoomId($idRoomType);
            $ratePlanId = $this->resolveChannexRatePlanId($idRoomType);

            if (empty($channexRoomId) || empty($ratePlanId)) {
                $slug = $this->getSlugByRoomType($idRoomType);
                Logger::error("ChannexService: Mapeo de habitación/tarifa faltante en .env para ID {$idRoomType} (slug: {$slug})");
                return false;
            }

            $bookingPayload = [
                'booking' => [
                    'status'         => 'new',
                    'provider_code'  => Config::get('CHANNEX_PROVIDER_CODE', 'OpenChannel'),
                    'hotel_code'     => $this->propertyId,
                    'ota_name'       => Config::get('CHANNEX_OTA_NAME', 'Direct'),
                    'reservation_id' => 'USG-' . $bookingId,
                    'arrival_date'   => $checkIn,
                    'departure_date' => $checkOut,
                    'currency'       => 'USD',
                    'payment_collect' => 'property',
                    'payment_type'   => 'credit_card',
                    'customer' => [
                        'name'    => $firstName,
                        'surname' => $lastName,
                        'mail'    => $guestEmail,
                        'phone'   => $guestPhone !== '' ? $guestPhone : '000000000',
                        'country' => 'PE',
                    ],
                    'rooms' => [
                        [
                            'index'          => 0,
                            'room_type_code' => $channexRoomId,
                            'rate_plan_code' => $ratePlanId,
                            'occupancy' => [
                                'adults'   => max(1, $adults),
                                'children' => 0,
                                'infants'  => 0,
                            ],
                        ],
                    ],
                ],
            ];

            $endpoint = "{$this->apiUrl}/channel_webhooks/open_channel/new_booking";

            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "api-key: {$this->apiKey}",
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($bookingPayload, JSON_THROW_ON_ERROR));
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError !== '') {
                Logger::error("ChannexService: cURL error: {$curlError}");
                return false;
            }

            if ($httpCode === 200 || $httpCode === 201) {
                Logger::info("ChannexService Success: Reserva {$bookingId} enviada a Channex exitosamente.");
                return true;
            }

            Logger::error("ChannexService Error: HTTP {$httpCode}. Respuesta: {$response}");
            return false;

        } catch (Exception $e) {
            Logger::error('ChannexService Exception en createBooking: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Resuelve el UUID de Channex desde las variables de entorno para un room type.
     */
    private function resolveChannexRoomId(int $idRoomType): ?string {
        $slug = $this->getSlugByRoomType($idRoomType);
        $envKey = 'CHANNEX_ROOM_' . strtoupper(str_replace('-', '_', $slug));
        return Config::get($envKey);
    }

    /**
     * Resuelve el UUID del Rate Plan en Channex específico para la habitación o usa el general.
     */
    private function resolveChannexRatePlanId(int $idRoomType): ?string {
        $slug = $this->getSlugByRoomType($idRoomType);
        $envKey = 'CHANNEX_RATE_' . strtoupper(str_replace('-', '_', $slug));
        $specificRate = Config::get($envKey);
        return !empty($specificRate) ? $specificRate : Config::get('CHANNEX_RATE_PLAN_ID');
    }

    /**
     * Mapea el ID de tipo de habitación local a un slug.
     */
    private function getSlugByRoomType(int $idRoomType): string {
        return match ($idRoomType) {
            1 => 'matrimonial',
            2 => 'doble-superior',
            3 => 'triple-standar',
            4 => 'familiar-superior',
            default => 'matrimonial',
        };
    }
}
