<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use PDO;
use PDOException;
use SimpleXMLElement;
use Exception;

/**
 * Servicio de integración con QloApps (CMS de reserva hotelera).
 * Combina consultas SQL directas (velocidad) y API Web Service (sincronización).
 *
 * Optimización: N+1 queries consolidadas en una sola query con subqueries.
 * Fix: SSL verification en cURL, Config centralizado.
 */
class QloAppService {
    private ?PDO $pdo;
    private readonly ?string $apiUrl;
    private readonly ?string $apiKey;

    public function __construct(?PDO $pdo = null) {
        $db = \App\Core\Database::getInstance();
        $this->pdo = $pdo ?? $db->getConnection();

        $this->apiUrl = Config::get('QLOAPP_API_URL', 'https://cms.hotelesusgar.com/api');
        $this->apiKey = Config::get('QLOAPP_API_KEY');
    }

    /**
     * Obtiene el inventario de habitaciones con disponibilidad neta.
     * Optimizado: una sola query con subqueries en vez de N+1 queries por room type.
     */
    public function getAvailableRooms(string $checkIn, string $checkOut, int $hotelId = 1): array {
        if (!$this->pdo) {
            throw new Exception('Database connection is offline.');
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    rt.id AS id_room_type,
                    rt.id_product,
                    pl.name AS room_name,
                    p.price,
                    rt.max_guests,
                    -- Total de habitaciones físicas registradas
                    COALESCE((
                        SELECT COUNT(*) FROM qlo_htl_room_information ri
                        WHERE ri.id_product = rt.id_product
                    ), 10) AS total_rooms,
                    -- Habitaciones reservadas (confirmadas) en ese rango
                    COALESCE((
                        SELECT COUNT(DISTINCT bd.id_room) FROM qlo_htl_booking_detail bd
                        WHERE bd.id_product = rt.id_product
                          AND bd.is_cancelled = 0
                          AND bd.is_refunded = 0
                          AND bd.date_from < :date_to_booked
                          AND bd.date_to > :date_from_booked
                    ), 0) AS booked_count,
                    -- Bloqueos temporales (Holds) activos en ese rango
                    COALESCE((
                        SELECT COUNT(*) FROM provisional_bookings pb
                        WHERE pb.id_hotel = :id_hotel_holds
                          AND pb.id_room_type = rt.id
                          AND pb.status = 'pending'
                          AND pb.expires_at > NOW()
                          AND pb.checkin < :checkout_holds
                          AND pb.checkout > :checkin_holds
                    ), 0) AS hold_count
                FROM qlo_htl_room_type rt
                INNER JOIN qlo_product p ON p.id_product = rt.id_product
                INNER JOIN qlo_product_lang pl ON pl.id_product = rt.id_product AND pl.id_lang = 1
                WHERE p.active = 1 AND rt.id_hotel = :id_hotel
            ");

            $stmt->execute([
                ':id_hotel'         => $hotelId,
                ':id_hotel_holds'   => $hotelId,
                ':date_from_booked' => $checkIn . ' 12:00:00',
                ':date_to_booked'   => $checkOut . ' 10:30:00',
                ':checkin_holds'    => $checkIn,
                ':checkout_holds'   => $checkOut,
            ]);

            $rows = $stmt->fetchAll();
            $availableRooms = [];

            foreach ($rows as $row) {
                $totalRooms = max((int)$row['total_rooms'], 1);
                $availableCount = max(0, $totalRooms - (int)$row['booked_count'] - (int)$row['hold_count']);

                if ($availableCount > 0) {
                    $availableRooms[] = [
                        'id_room_type'  => (int)$row['id_room_type'],
                        'room_name'     => $row['room_name'],
                        'price'         => (float)$row['price'],
                        'max_guests'    => (int)$row['max_guests'],
                        'available_qty' => $availableCount,
                    ];
                }
            }

            return $availableRooms;

        } catch (PDOException $e) {
            Logger::error('QloAppService: Error al consultar disponibilidad: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Crea un Carrito temporal en QloApps mediante la API Web Service (XML).
     */
    public function createCart(int $hotelId, int $idRoomType, string $checkIn, string $checkOut, int $guests): string {
        if (empty($this->apiKey) || empty($this->apiUrl)) {
            throw new Exception('QloApps API key or API URL is not configured.');
        }

        $xmlData = <<<XML
<prestashop xmlns:xlink="http://www.w3.org/1999/xlink">
    <cart>
        <id_currency>1</id_currency>
        <id_lang>1</id_lang>
        <id_shop>{$hotelId}</id_shop>
        <associations>
            <cart_rows>
                <cart_row>
                    <id_product>{$idRoomType}</id_product>
                    <quantity>1</quantity>
                </cart_row>
            </cart_rows>
        </associations>
    </cart>
</prestashop>
XML;

        $xml = $this->executeRequest('carts', 'POST', $xmlData);
        if ($xml && isset($xml->cart->id)) {
            return (string)$xml->cart->id;
        }

        throw new Exception('Error creating cart on QloApps API.');
    }

    /**
     * Convierte un Carrito en una Orden confirmada en QloApps.
     */
    public function confirmOrder(string $cartId, float $totalPrice, string $guestName, string $guestEmail): ?string {
        if (empty($this->apiKey) || empty($this->apiUrl)) {
            throw new Exception('QloApps API key or API URL is not configured.');
        }
        if (str_contains($cartId, 'MOCK')) {
            throw new Exception('Cannot confirm mock cart.');
        }

        $xmlData = <<<XML
<prestashop xmlns:xlink="http://www.w3.org/1999/xlink">
    <order>
        <id_cart>{$cartId}</id_cart>
        <id_currency>1</id_currency>
        <id_lang>1</id_lang>
        <module>mercadopago</module>
        <payment>Mercado Pago</payment>
        <total_paid>{$totalPrice}</total_paid>
        <total_paid_real>{$totalPrice}</total_paid_real>
        <total_products>{$totalPrice}</total_products>
        <total_products_wt>{$totalPrice}</total_products_wt>
        <current_state>2</current_state>
    </order>
</prestashop>
XML;

        $xml = $this->executeRequest('orders', 'POST', $xmlData);
        if ($xml && isset($xml->order->id)) {
            return (string)$xml->order->id;
        }

        Logger::error("QloAppService: Error al confirmar la Orden para el Carrito {$cartId}");
        return null;
    }

    /**
     * Ejecuta una petición HTTP cURL contra la API XML de QloApps.
     * Fix: SSL verification habilitada.
     */
    private function executeRequest(string $endpoint, string $method = 'GET', ?string $xmlData = null): ?SimpleXMLElement {
        $url = rtrim($this->apiUrl, '/') . '/' . ltrim($endpoint, '/');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->apiKey . ':');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlData);
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlData);
        }

        if ($xmlData !== null) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            Logger::error("QloAppService: cURL error: {$curlError}");
            return null;
        }

        if ($httpCode >= 400 || !$response) {
            Logger::error("QloAppService: API Error {$httpCode}. Respuesta: {$response}");
            return null;
        }

        try {
            $xml = simplexml_load_string($response);
            return $xml !== false ? $xml : null;
        } catch (Exception $e) {
            Logger::error('QloAppService: Error al parsear XML: ' . $e->getMessage());
            return null;
        }
    }
}
