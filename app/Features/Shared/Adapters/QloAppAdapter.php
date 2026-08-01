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
 * Adaptador Hexagonal para la integracion con QloApps PMS.
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
            Logger::error('QloAppAdapter: DB Connection is offline. Cannot get availability.');
            return [];
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
                    ), 5) AS total_rooms,
                    COALESCE((
                        SELECT COUNT(DISTINCT bd.id_room) FROM qlo_htl_booking_detail bd
                        WHERE bd.id_product = rt.id_product
                          AND bd.is_cancelled = 0
                          AND bd.is_refunded = 0
                          AND bd.date_from < :date_to_booked
                          AND bd.date_to > :date_from_booked
                    ), 0) AS booked_count
                FROM qlo_htl_room_type rt
                INNER JOIN qlo_product p ON p.id_product = rt.id_product
                INNER JOIN qlo_product_lang pl ON pl.id_product = rt.id_product AND pl.id_lang = 1
                WHERE p.active = 1 AND rt.id_hotel = :id_hotel
            ");

            $stmt->execute([
                ':id_hotel'         => $idHotel,
                ':date_from_booked' => $checkIn . ' 12:00:00',
                ':date_to_booked'   => $checkOut . ' 10:30:00',
            ]);

            $rows = $stmt->fetchAll();
            $availableRooms = [];

            foreach ($rows as $row) {
                $totalRooms = max((int)$row['total_rooms'], 1);
                $availableCount = max(0, $totalRooms - (int)$row['booked_count']);

                $availableRooms[] = [
                    'id_room_type'  => (int)$row['id_room_type'],
                    'id_product'    => (int)$row['id_product'],
                    'room_name'     => $row['room_name'],
                    'price'         => (float)$row['price'],
                    'max_guests'    => (int)$row['max_guests'],
                    'available_qty' => $availableCount,
                ];
            }

            return $availableRooms;

        } catch (PDOException $e) {
            Logger::error('QloAppAdapter: Error en consulta SQL: ' . $e->getMessage());
            return [];
        }
    }

    public function createCart(int $idHotel, int $idProduct, string $checkIn, string $checkOut, int $guests = 1, float $totalPrice = 0, string $guestName = '', string $guestEmail = '', string $guestPhone = ''): string {
        if (empty($this->apiKey) || empty($this->apiUrl)) {
            Logger::error('QloAppAdapter: QloApps API key or API URL is not configured. Falling back to local cart.');
            return 'USGAR-' . bin2hex(random_bytes(6));
        }

        $nameParts = explode(' ', $guestName, 2);
        $firstName = htmlspecialchars($nameParts[0] ?: 'Guest', ENT_XML1);
        $lastName = htmlspecialchars($nameParts[1] ?? 'Guest', ENT_XML1);
        $safeEmail = htmlspecialchars($guestEmail ?: 'no-reply@hotelesusgar.com', ENT_XML1);
        $phone = htmlspecialchars($guestPhone ?: '000000000', ENT_XML1);
        $currency = Config::get('MERCADO_PAGO_CURRENCY', 'USD');
        
        // payment_status 2 is generally Pending in QloApps/PrestaShop
        $xmlData = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<qloapps xmlns:xlink="http://www.w3.org/1999/xlink">
    <booking>
        <id_property>{$idHotel}</id_property>
        <currency>{$currency}</currency>
        <booking_status>1</booking_status>
        <payment_status>0</payment_status>
        <source>website</source>
        <booking_date>MERCADO PAGO (HOLD)</booking_date>
        <id_language>1</id_language>
        <associations>
            <customer_detail api="customer_detail">
                <firstname>{$firstName}</firstname>
                <lastname>{$lastName}</lastname>
                <email>{$safeEmail}</email>
                <phone>{$phone}</phone>
            </customer_detail>
            <price_details api="price_details">
                <total_paid>0</total_paid>
                <total_price_with_tax>{$totalPrice}</total_price_with_tax>
            </price_details>
            <room_types nodeType="room_type" api="room_types">
                <room_type>
                    <id_room_type>{$idProduct}</id_room_type>
                    <checkin_date>{$checkIn} 12:00:00</checkin_date>
                    <checkout_date>{$checkOut} 10:00:00</checkout_date>
                    <number_of_rooms>1</number_of_rooms>
                    <rooms>
                        <room>
                            <adults>{$guests}</adults>
                            <child>0</child>
                            <unit_price_without_tax>{$totalPrice}</unit_price_without_tax>
                        </room>
                    </rooms>
                </room_type>
            </room_types>
        </associations>
    </booking>
</qloapps>
XML;

        $xml = $this->executeRequest('bookings', 'POST', $xmlData);
        if ($xml && isset($xml->booking->id)) {
            return (string)$xml->booking->id;
        }

        Logger::error("QloAppAdapter: Error al crear Cart/Booking en QloApps, fallback a USGAR- local.");
        return 'USGAR-' . bin2hex(random_bytes(6));
    }

    public function confirmOrder(string $cartId, float $totalPrice, string $guestName, string $guestEmail): ?string {
        if (empty($this->apiKey) || empty($this->apiUrl)) {
            throw new Exception('QloApps API key or API URL is not configured.');
        }

        // Si el cartId es el fallback local (USGAR-), tenemos que usar el flujo antiguo (crearlo de cero)
        if (str_starts_with($cartId, 'USGAR-')) {
            $stmt = $this->pdo->prepare("SELECT * FROM provisional_bookings WHERE cart_id = :cartId");
            $stmt->execute([':cartId' => $cartId]);
            $hold = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$hold) {
                Logger::error("QloAppAdapter: No se encontró la reserva provisional local para {$cartId}");
                return null;
            }

            $idHotel = $hold['id_hotel'] ?? 1;
            $idProduct = $hold['id_room_type'];
            $checkIn = $hold['checkin'];
            $checkOut = $hold['checkout'];
            
            $guestData = json_decode((string)$hold['guest_data'], true) ?? [];
            $guests = $guestData['guests'] ?? 1;
            $phone = $guestData['phone'] ?? '000000000';
            
            $nameParts = explode(' ', $guestName, 2);
            $firstName = htmlspecialchars($nameParts[0] ?? $guestName, ENT_XML1);
            $lastName = htmlspecialchars($nameParts[1] ?? 'Guest', ENT_XML1);
            $safeEmail = htmlspecialchars($guestEmail, ENT_XML1);
            $currency = Config::get('MERCADO_PAGO_CURRENCY', 'USD');

            $xmlData = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<qloapps xmlns:xlink="http://www.w3.org/1999/xlink">
    <booking>
        <id_property>{$idHotel}</id_property>
        <currency>{$currency}</currency>
        <booking_status>1</booking_status>
        <payment_status>1</payment_status>
        <source>website</source>
        <booking_date>MERCADO PAGO</booking_date>
        <id_language>1</id_language>
        <associations>
            <customer_detail api="customer_detail">
                <firstname>{$firstName}</firstname>
                <lastname>{$lastName}</lastname>
                <email>{$safeEmail}</email>
                <phone>{$phone}</phone>
            </customer_detail>
            <price_details api="price_details">
                <total_paid>{$totalPrice}</total_paid>
                <total_price_with_tax>{$totalPrice}</total_price_with_tax>
            </price_details>
            <room_types nodeType="room_type" api="room_types">
                <room_type>
                    <id_room_type>{$idProduct}</id_room_type>
                    <checkin_date>{$checkIn} 12:00:00</checkin_date>
                    <checkout_date>{$checkOut} 10:00:00</checkout_date>
                    <number_of_rooms>1</number_of_rooms>
                    <rooms>
                        <room>
                            <adults>{$guests}</adults>
                            <child>0</child>
                            <unit_price_without_tax>{$totalPrice}</unit_price_without_tax>
                        </room>
                    </rooms>
                </room_type>
            </room_types>
        </associations>
    </booking>
</qloapps>
XML;
            $xml = $this->executeRequest('bookings', 'POST', $xmlData);
            if ($xml && isset($xml->booking->id)) {
                return (string)$xml->booking->id;
            }
            return null;
        }

        // El cartId es un ID real numérico de QloApps, hacemos PUT para actualizar el pago
        $currency = Config::get('MERCADO_PAGO_CURRENCY', 'USD');
        $xmlData = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<qloapps xmlns:xlink="http://www.w3.org/1999/xlink">
    <booking>
        <id>{$cartId}</id>
        <payment_status>1</payment_status>
        <price_details api="price_details">
            <total_paid>{$totalPrice}</total_paid>
            <total_price_with_tax>{$totalPrice}</total_price_with_tax>
        </price_details>
    </booking>
</qloapps>
XML;
        $xml = $this->executeRequest('bookings/' . $cartId, 'PUT', $xmlData);
        if ($xml && isset($xml->booking->id)) {
            return (string)$xml->booking->id;
        }

        Logger::error("QloAppAdapter: Error al actualizar la reserva {$cartId} a pagada.");
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
            $prevErrors = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($response);
            if ($xml === false) {
                $xmlErrors = libxml_get_errors();
                libxml_clear_errors();
                libxml_use_internal_errors($prevErrors);
                $errMsgs = array_map(fn($err) => trim($err->message), $xmlErrors);
                Logger::error('QloAppAdapter: Error al parsear XML de QloApps: ' . implode(' | ', $errMsgs));
                return null;
            }
            libxml_use_internal_errors($prevErrors);
            return $xml;
        } catch (Exception $e) {
            Logger::error('QloAppAdapter: Excepcion al parsear XML: ' . $e->getMessage());
            return null;
        }
    }
}
