<?php
declare(strict_types=1);

namespace App\Features\Shared\Adapters;

use App\Features\Shared\Ports\PmsPortInterface;
use App\Core\Config;
use App\Core\Logger;
use App\Core\Database;
use PDO;
use PDOException;
use SimpleXMLElement;
use Exception;

/**
 * Adaptador Hexagonal para la integración con QloApps PMS.
 * Cumple estrictamente con PmsPortInterface.
 */
class QloAppAdapter implements PmsPortInterface {
    private ?PDO $pdo;
    private readonly ?string $apiUrl;
    private readonly ?string $apiKey;

    public function __construct(?PDO $pdo = null) {
        $db = Database::getInstance();
        $this->pdo = $pdo ?? $db->getConnection();

        $this->apiUrl = Config::get('QLOAPP_API_URL', 'https://cms.hotelesusgar.com/api');
        $this->apiKey = Config::get('QLOAPP_API_KEY');
    }

    public function getAvailableRooms(string $checkIn, string $checkOut, int $idHotel = 1): array {
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
                    COALESCE((
                        SELECT COUNT(*) FROM qlo_htl_room_information ri
                        WHERE ri.id_product = rt.id_product
                    ), 10) AS total_rooms,
                    COALESCE((
                        SELECT COUNT(DISTINCT bd.id_room) FROM qlo_htl_booking_detail bd
                        WHERE bd.id_product = rt.id_product
                          AND bd.is_cancelled = 0
                          AND bd.is_refunded = 0
                          AND bd.date_from < :date_to_booked
                          AND bd.date_to > :date_from_booked
                    ), 0) AS booked_count,
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
                ':id_hotel'         => $idHotel,
                ':id_hotel_holds'   => $idHotel,
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
                        'id_product'    => (int)$row['id_product'],
                        'room_name'     => $row['room_name'],
                        'price'         => (float)$row['price'],
                        'max_guests'    => (int)$row['max_guests'],
                        'available_qty' => $availableCount,
                    ];
                }
            }

            return $availableRooms;

        } catch (PDOException $e) {
            Logger::error('QloAppAdapter: Error al consultar disponibilidad: ' . $e->getMessage());
            throw $e;
        }
    }

    public function createCart(int $idHotel, int $idProduct, string $checkIn, string $checkOut, int $guests = 1): string {
        if (empty($this->apiKey) || empty($this->apiUrl)) {
            throw new Exception('QloApps API key or API URL is not configured.');
        }

        $xmlData = <<<XML
<prestashop xmlns:xlink="http://www.w3.org/1999/xlink">
    <cart>
        <id_currency>1</id_currency>
        <id_lang>1</id_lang>
        <id_shop>{$idHotel}</id_shop>
        <associations>
            <cart_rows>
                <cart_row>
                    <id_product>{$idProduct}</id_product>
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

        Logger::error("QloAppAdapter: Error al confirmar la Orden para el Carrito {$cartId}");
        return null;
    }

    public function extendCartSession(string $cartId): bool {
        if (!$this->pdo) {
            return false;
        }
        try {
            $stmt1 = $this->pdo->prepare("UPDATE qlo_cart SET date_upd = NOW() WHERE id_cart = :cartId");
            $stmt1->execute([':cartId' => $cartId]);

            $stmt2 = $this->pdo->prepare("UPDATE qlo_htl_cart_booking_data SET date_upd = NOW() WHERE id_cart = :cartId");
            $stmt2->execute([':cartId' => $cartId]);

            return true;
        } catch (PDOException $e) {
            Logger::error("QloAppAdapter: Error al extender sesión de carrito {$cartId}: " . $e->getMessage());
            return false;
        }
    }

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
            Logger::error("QloAppAdapter: cURL error: {$curlError}");
            return null;
        }

        if ($httpCode >= 400 || !$response) {
            Logger::error("QloAppAdapter: API Error {$httpCode}. Respuesta: {$response}");
            return null;
        }

        try {
            $xml = simplexml_load_string($response);
            return $xml !== false ? $xml : null;
        } catch (Exception $e) {
            Logger::error('QloAppAdapter: Error al parsear XML: ' . $e->getMessage());
            return null;
        }
    }
}
