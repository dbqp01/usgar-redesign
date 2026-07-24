<?php
declare(strict_types=1);

namespace App\Features\Shared\Adapters;

use App\Features\Shared\Ports\ChannelManagerPortInterface;
use App\Features\Shared\RoomTypeRegistry;
use App\Core\Config;
use App\Core\Logger;
use Exception;

/**
 * Adaptador Hexagonal para la integracion con Channex Channel Manager.
 * Cumple con ChannelManagerPortInterface.
 */
class ChannexAdapter implements ChannelManagerPortInterface {
    private readonly ?string $apiKey;
    private readonly ?string $apiUrl;
    private readonly ?string $propertyId;

    public function __construct() {
        $this->apiKey = Config::get('CHANNEX_API_KEY');
        $this->apiUrl = Config::get('CHANNEX_API_URL', 'https://api.channex.io/api/v1');
        $this->propertyId = Config::get('CHANNEX_PROPERTY_ID');
    }

    public function pushAvailability(
        int $idRoomType,
        string $checkIn,
        string $checkOut,
        float $totalPrice,
        int $availabilityQty = 1
    ): bool {
        if (empty($this->apiKey) || empty($this->propertyId)) {
            return false;
        }

        try {
            $channexRoomId = $this->resolveChannexRoomId($idRoomType);
            if (empty($channexRoomId)) {
                Logger::error("ChannexAdapter: Mapeo de habitación faltante para ID {$idRoomType}");
                return false;
            }

            $endpoint = "{$this->apiUrl}/ari";
            $payload = [
                'values' => [
                    [
                        'property_id'  => $this->propertyId,
                        'room_type_id' => $channexRoomId,
                        'date_from'    => $checkIn,
                        'date_to'      => $checkOut,
                        'availability' => max(0, $availabilityQty),
                        'rate'         => $totalPrice,
                    ]
                ]
            ];

            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "user-api-key: {$this->apiKey}",
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200 || $httpCode === 201;

        } catch (Exception $e) {
            Logger::error('ChannexAdapter Exception en pushAvailability: ' . $e->getMessage());
            return false;
        }
    }

    public function processChannelBooking(array $bookingData): bool {
        $bookingId = $bookingData['booking_id'] ?? $bookingData['id'] ?? 'EXT-' . time();
        $checkIn = $bookingData['checkin'] ?? date('Y-m-d');
        $checkOut = $bookingData['checkout'] ?? date('Y-m-d', strtotime('+1 day'));
        $idRoomType = (int)($bookingData['id_room_type'] ?? 1);
        $totalPrice = (float)($bookingData['total_price'] ?? 0.0);
        $guestName = $bookingData['guest_name'] ?? 'OTA Guest';
        $guestEmail = $bookingData['guest_email'] ?? 'ota@usgarhoteles.com';
        $guestPhone = $bookingData['guest_phone'] ?? '';
        $adults = (int)($bookingData['adults'] ?? 2);

        return $this->createBooking(
            (string)$bookingId,
            $checkIn,
            $checkOut,
            $idRoomType,
            $totalPrice,
            $guestName,
            $guestEmail,
            $guestPhone,
            $adults
        );
    }

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

            $channexRoomId = $this->resolveChannexRoomId($idRoomType);
            $ratePlanId = $this->resolveChannexRatePlanId($idRoomType);

            if (empty($channexRoomId) || empty($ratePlanId)) {
                $slug = $this->getSlugByRoomType($idRoomType);
                Logger::error("ChannexAdapter: Mapeo faltante para ID {$idRoomType} (slug: {$slug})");
                return false;
            }

            $bookingPayload = [
                'booking' => [
                    'status'          => 'new',
                    'provider_code'   => Config::get('CHANNEX_PROVIDER_CODE', 'OpenChannel'),
                    'hotel_code'      => $this->propertyId,
                    'ota_name'        => Config::get('CHANNEX_OTA_NAME', 'Direct'),
                    'reservation_id'  => 'USG-' . $bookingId,
                    'arrival_date'    => $checkIn,
                    'departure_date'  => $checkOut,
                    'currency'        => 'USD',
                    'payment_collect' => 'property',
                    'payment_type'    => 'credit_card',
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
                "user-api-key: {$this->apiKey}",
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
                Logger::error("ChannexAdapter: cURL error: {$curlError}");
                return false;
            }

            if ($httpCode === 200 || $httpCode === 201) {
                Logger::info("ChannexAdapter Success: Reserva {$bookingId} enviada a Channex.");
                return true;
            }

            Logger::error("ChannexAdapter Error: HTTP {$httpCode}. Respuesta: {$response}");
            return false;

        } catch (Exception $e) {
            Logger::error('ChannexAdapter Exception en createBooking: ' . $e->getMessage());
            return false;
        }
    }

    private function resolveChannexRoomId(int $idRoomType): ?string {
        return RoomTypeRegistry::getChannexRoomId($idRoomType);
    }

    private function resolveChannexRatePlanId(int $idRoomType): ?string {
        return RoomTypeRegistry::getChannexRatePlanId($idRoomType);
    }

    private function getSlugByRoomType(int $idRoomType): string {
        return RoomTypeRegistry::getSlugById($idRoomType);
    }

}
