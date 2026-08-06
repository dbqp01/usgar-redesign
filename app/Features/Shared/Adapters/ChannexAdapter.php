<?php
declare(strict_types=1);

namespace App\Features\Shared\Adapters;

use App\Features\Shared\Ports\ChannelManagerPortInterface;
use App\Features\Shared\RoomTypeRegistry;
use App\Core\Config;
use App\Core\Logger;
use App\Core\GuestName;
use App\Core\CartIdPrefix;
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
            $nameParts = GuestName::split(trim($guestName));
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? Config::get('DEFAULT_GUEST_NAME', 'Guest');

            $channexRoomId = $this->resolveChannexRoomId($idRoomType);
            $ratePlanId = $this->resolveChannexRatePlanId($idRoomType);

            if (empty($channexRoomId) || empty($ratePlanId)) {
                $slug = $this->getSlugByRoomType($idRoomType);
                Logger::error("ChannexAdapter: Mapeo faltante para ID {$idRoomType} (slug: {$slug})");
                // Todo 22: config rota -> exception (no false): el outbox lo
                // reintenta hasta MAX y la alerta terminal lo hace visible.
                throw new Exception("Channex createBooking: mapeo faltante para room type {$idRoomType} (slug: {$slug}).");
            }

            $begin = new \DateTime($checkIn);
            $end = new \DateTime($checkOut);
            $interval = \DateInterval::createFromDateString('1 day');
            $period = new \DatePeriod($begin, $interval, $end);
            
            $days = [];
            $nights = iterator_count($period);
            $pricePerNight = $nights > 0 ? round($totalPrice / $nights, 2) : $totalPrice;

            foreach ($period as $dt) {
                $days[] = [
                    'date'           => $dt->format('Y-m-d'),
                    'price'          => (string)$pricePerNight,
                    'rate_plan_code' => $ratePlanId,
                ];
            }

            $bookingPayload = [
                'booking' => [
                    'status'          => 'new',
                    'provider_code'   => Config::get('CHANNEX_PROVIDER_CODE', 'OpenChannel'),
                    'hotel_code'      => $this->propertyId,
                    'ota_name'        => Config::get('CHANNEX_OTA_NAME', 'Direct'),
                    'reservation_id'  => CartIdPrefix::CHANNEX . $bookingId,
                    'arrival_date'    => $checkIn,
                    'departure_date'  => $checkOut,
                    'currency'        => Config::get('MERCADO_PAGO_CURRENCY', 'USD'),
                    'payment_collect' => 'property',
                    'payment_type'    => 'credit_card',
                    'customer' => [
                        'name'    => $firstName,
                        'surname' => $lastName,
                        'mail'    => $guestEmail,
                        'phone'   => $guestPhone !== '' ? $guestPhone : Config::get('OTA_DEFAULT_PHONE', '000000000'),
                        'country' => 'PE',
                    ],
                    'rooms' => [
                        [
                            'index'          => 0,
                            'room_type_code' => $channexRoomId,
                            'occupancy' => [
                                'adults'   => max(1, $adults),
                                'children' => 0,
                                'infants'  => 0,
                            ],
                            'guests' => [
                                ['name' => $firstName, 'surname' => $lastName]
                            ],
                            'days' => $days,
                        ],
                    ],
                ],
            ];

            $endpoint = "{$this->apiUrl}/channel_webhooks/open_channel/new_booking";
            $result = $this->request('POST', $endpoint, [
                "user-api-key: {$this->apiKey}",
                'Content-Type: application/json',
            ], json_encode($bookingPayload, JSON_THROW_ON_ERROR));

            if ($result['curl_error'] !== '') {
                Logger::error("ChannexAdapter: cURL error: {$result['curl_error']}");
                throw new Exception("Channex Network Error in createBooking: {$result['curl_error']}");
            }

            if ($result['code'] === 200 || $result['code'] === 201) {
                Logger::info("ChannexAdapter Success: Reserva {$bookingId} enviada a Channex.");
                return true;
            }

            // Todo 22: un HTTP de error de Channex NO se traga (antes
            // `return false` silenciaba la desync PMS): exception -> el outbox
            // reintenta con backoff hasta MAX + alerta terminal.
            Logger::error("ChannexAdapter Error: HTTP {$result['code']}. Respuesta: {$result['body']}");
            throw new Exception("Channex createBooking fallo: HTTP {$result['code']}.");

        } catch (Exception $e) {
            Logger::error('ChannexAdapter Exception en createBooking: ' . $e->getMessage());
            throw $e;
        }
    }

    public function fetchBookingRevision(string $revisionId): ?array {
        if (empty($this->apiKey)) {
            return null;
        }

        try {
            $endpoint = "{$this->apiUrl}/booking_revisions/{$revisionId}";
            $result = $this->request('GET', $endpoint, [
                "user-api-key: {$this->apiKey}",
                'Accept: application/json',
            ]);

            if ($result['curl_error'] !== '') {
                throw new Exception("Channex Network Error in fetchBookingRevision: {$result['curl_error']}");
            }

            if ($result['code'] === 200 && !empty($result['body'])) {
                $decoded = json_decode($result['body'], true);
                return $decoded['data'] ?? ($decoded['booking'] ?? $decoded);
            }

            Logger::error("ChannexAdapter: Error al consultar revision {$revisionId}. HTTP {$result['code']}");
            return null;
        } catch (Exception $e) {
            Logger::error("ChannexAdapter Exception en fetchBookingRevision: " . $e->getMessage());
            return null;
        }
    }

    public function acknowledgeRevision(string $revisionId): bool {
        if (empty($this->apiKey)) {
            return false;
        }

        try {
            $endpoint = "{$this->apiUrl}/booking_revisions/{$revisionId}/ack";
            $result = $this->request('POST', $endpoint, [
                "user-api-key: {$this->apiKey}",
                'Content-Type: application/json',
            ], '{}');

            if ($result['curl_error'] !== '') {
                throw new Exception("Channex Network Error in acknowledgeRevision: {$result['curl_error']}");
            }

            if ($result['code'] >= 200 && $result['code'] < 300) {
                Logger::info("ChannexAdapter: Revision {$revisionId} confirmada (ACK) exitosamente.");
                return true;
            }

            Logger::error("ChannexAdapter: Fallo al enviar ACK para revision {$revisionId}. HTTP {$result['code']}");
            return false;
        } catch (Exception $e) {
            Logger::error("ChannexAdapter Exception en acknowledgeRevision: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Dedup del consumidor (todo 22): consulta si ya existe un booking en
     * Channex para la external_reference del dominio (USGAR-{cartId}).
     *
     * La reserva se guarda en Channex como reservation_id = USG-{cartId}
     * (ver createBooking, CartIdPrefix::CHANNEX); se busca por ese filtro.
     *
     * FAIL-CLOSED: error de transporte/HTTP -> exception (nunca null por
     * error: el listener recrearia y duplicaria la reserva).
     */
    public function findBookingByExternalReference(string $externalReference): ?array {
        if (empty($this->apiKey) || empty($this->propertyId)) {
            throw new Exception('Channex API Key or Property ID is not configured.');
        }

        $cartId = str_starts_with($externalReference, 'USGAR-')
            ? substr($externalReference, 6)
            : $externalReference;
        $reservationId = CartIdPrefix::CHANNEX . $cartId; // USG-{cartId}

        $endpoint = "{$this->apiUrl}/bookings?filter[reservation_id]=" . rawurlencode($reservationId);
        $result = $this->request('GET', $endpoint, [
            "user-api-key: {$this->apiKey}",
            'Accept: application/json',
        ]);

        if ($result['curl_error'] !== '') {
            throw new Exception("Channex Network Error in findBookingByExternalReference: {$result['curl_error']}");
        }

        if ($result['code'] !== 200) {
            throw new Exception("Channex Error en findBookingByExternalReference: HTTP {$result['code']}");
        }

        $decoded = json_decode($result['body'], true);
        $data = $decoded['data'] ?? [];
        if (!is_array($data)) {
            return null;
        }
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $attributes = $item['attributes'] ?? $item;
            if (is_array($attributes) && ($attributes['reservation_id'] ?? '') === $reservationId) {
                return $item;
            }
        }
        return null; // 2xx sin coincidencia: el booking NO existe -> se crea.
    }

    /**
     * Ejecuta una peticion cURL con la convencion de Channex y devuelve el resultado.
     *
     * @param array<int, string> $headers
     * @return array{code: int, body: string, curl_error: string}
     */
    private function request(string $method, string $url, array $headers, ?string $body = null): array {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return [
            'code'       => (int)$httpCode,
            'body'       => is_string($response) ? $response : '',
            'curl_error' => (string)$curlError,
        ];
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
