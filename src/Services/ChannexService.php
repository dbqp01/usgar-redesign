<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use Exception;

/**
 * Servicio de integración con Channex (Channel Manager).
 * Envía las reservas confirmadas en QloApps al endpoint "Open Channel" de Channex
 * para descontar inventario de canales OTA (Booking, Expedia, Airbnb, etc.).
 */
class ChannexService {
    private ?string $apiKey;
    private ?string $apiUrl;
    private ?string $propertyId;

    public function __construct() {
        $db = Database::getInstance();
        $this->apiKey = $db->getEnv('CHANNEX_API_KEY');
        $this->apiUrl = $db->getEnv('CHANNEX_API_URL', 'https://api.channex.io/api/v1');
        $this->propertyId = $db->getEnv('CHANNEX_PROPERTY_ID');
    }

    /**
     * Envía la reserva confirmada de QloApps a Channex.
     */
    public function createBooking(
        string $bookingId,
        string $checkIn,
        string $checkOut,
        int $idRoomType,
        float $totalPrice,
        string $guestName,
        string $guestEmail,
        string $guestPhone = ''
    ): bool {
        if (empty($this->apiKey) || empty($this->propertyId)) {
            Logger::info("ChannexService: Modo MOCK. Sincronización simulada para Reserva #{$bookingId}");
            return true;
        }

        try {
            $nameParts = explode(' ', trim($guestName), 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? 'Guest';

            // Resolver ID del Room Type de Channex desde variables de entorno mapeadas
            $slug = $this->getSlugByRoomType($idRoomType);
            $envKey = 'CHANNEX_ROOM_' . strtoupper(str_replace('-', '_', $slug));
            
            $db = Database::getInstance();
            $channexRoomId = $db->getEnv($envKey);
            $ratePlanId = $db->getEnv('CHANNEX_RATE_PLAN_ID');

            if (empty($channexRoomId) || empty($ratePlanId)) {
                Logger::error("ChannexService Error: Mapeo de habitación faltante en .env para el ID {$idRoomType} (slug: {$slug})");
                return false;
            }

            $bookingPayload = [
                'booking' => [
                    'status' => 'new',
                    'reservation_id' => 'USG-' . $bookingId,
                    'arrival_date' => $checkIn,
                    'departure_date' => $checkOut,
                    'currency' => 'USD',
                    'payment_collect' => 'property',
                    'payment_type' => 'credit_card',
                    'customer' => [
                        'name' => $firstName,
                        'surname' => $lastName,
                        'mail' => $guestEmail,
                        'phone' => $guestPhone !== '' ? $guestPhone : '000000000',
                        'country' => 'PE'
                    ],
                    'rooms' => [
                        [
                            'index' => 0,
                            'room_type_code' => $channexRoomId,
                            'rate_plan_code' => $ratePlanId,
                            'occupancy' => [
                                'adults' => 2,
                                'children' => 0,
                                'infants' => 0
                            ]
                        ]
                    ]
                ]
            ];

            // Endpoint Open Channel de Channex
            $endpoint = "{$this->apiUrl}/channel_webhooks/open_channel/new_booking";
            
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "api-key: {$this->apiKey}",
                "Content-Type: application/json"
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($bookingPayload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 || $httpCode === 201) {
                Logger::info("ChannexService Success: Reserva {$bookingId} enviada a Channex exitosamente.");
                return true;
            }

            Logger::error("ChannexService Error: Servidor retornó HTTP {$httpCode}. Respuesta: {$response}");
            return false;

        } catch (Exception $e) {
            Logger::error("ChannexService Exception en createBooking: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mapea el ID de tipo de habitación local a un Slug para leer la configuración de entorno (.env).
     */
    private function getSlugByRoomType(int $idRoomType): string {
        $mappings = [
            1 => 'matrimonial',
            2 => 'doble-superior',
            3 => 'triple-standar',
            4 => 'familiar-superior'
        ];
        return $mappings[$idRoomType] ?? 'matrimonial';
    }
}
