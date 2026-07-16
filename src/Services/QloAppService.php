<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use PDO;
use PDOException;
use SimpleXMLElement;
use Exception;

/**
 * Servicio de integración con QloApps (CMS de reserva hotelera).
 * Combina consultas SQL directas contra las tablas de QloApps (para velocidad y robustez)
 * y llamadas HTTP a la API Web Service de QloApps (para sincronización segura de carritos y órdenes).
 */
class QloAppService {
    private ?PDO $pdo;
    private ?string $apiUrl;
    private ?string $apiKey;

    /**
     * Constructor con inyección de dependencias opcional para cumplimiento de SOLID.
     */
    public function __construct(?PDO $pdo = null) {
        $db = Database::getInstance();
        $this->pdo = $pdo ?? $db->getConnection();
        
        $this->apiUrl = $db->getEnv('QLOAPP_API_URL', 'https://cms.hotelesusgar.com/api');
        $this->apiKey = $db->getEnv('QLOAPP_API_KEY');
    }

    /**
     * Obtiene el inventario de habitaciones y precios netos disponibles para un rango de fechas.
     * Descuenta las reservas confirmadas de QloApps y los Holds provisionales activos.
     */
    public function getAvailableRooms(string $checkIn, string $checkOut, int $hotelId = 1): array {
        if (!$this->pdo) {
            Logger::error("QloAppService: Conexión de base de datos offline. Retornando mock.");
            return $this->getMockRooms();
        }

        try {
            // 1. Obtener los tipos de habitaciones activas y sus precios base desde QloApps
            $stmt = $this->pdo->prepare("
                SELECT rt.id AS id_room_type, rt.id_product, pl.name AS room_name, p.price, rt.max_guests
                FROM qlo_htl_room_type rt
                INNER JOIN qlo_product p ON p.id_product = rt.id_product
                INNER JOIN qlo_product_lang pl ON pl.id_product = rt.id_product AND pl.id_lang = 1
                WHERE p.active = 1 AND rt.id_hotel = :id_hotel
            ");
            $stmt->execute([':id_hotel' => $hotelId]);
            $roomTypes = $stmt->fetchAll();

            $availableRooms = [];

            foreach ($roomTypes as $rt) {
                $idProduct = (int)$rt['id_product'];
                $idRoomType = (int)$rt['id_room_type'];

                // 2. Contar habitaciones físicas totales registradas en QloApps
                $stmtRooms = $this->pdo->prepare("
                    SELECT COUNT(*) FROM qlo_htl_room_information 
                    WHERE id_product = :id_product
                ");
                $stmtRooms->execute([':id_product' => $idProduct]);
                $totalPhysical = (int)$stmtRooms->fetchColumn();
                // Si no hay físicas registradas, asumimos un límite por defecto para pruebas
                $totalRooms = $totalPhysical > 0 ? $totalPhysical : 10;

                // 3. Contar habitaciones reservadas y no canceladas en QloApps en ese rango
                $stmtBooked = $this->pdo->prepare("
                    SELECT COUNT(DISTINCT id_room) FROM qlo_htl_booking_detail
                    WHERE id_product = :id_product
                      AND is_cancelled = 0
                      AND is_refunded = 0
                      AND date_from < :date_to
                      AND date_to > :date_from
                ");
                $stmtBooked->execute([
                    ':id_product' => $idProduct,
                    ':date_from' => $checkIn . ' 12:00:00', // Check-in estándar
                    ':date_to' => $checkOut . ' 10:30:00'   // Check-out estándar
                ]);
                $bookedCount = (int)$stmtBooked->fetchColumn();

                // 4. Contar bloqueos temporales (Holds) pendientes y no expirados
                $stmtHolds = $this->pdo->prepare("
                    SELECT COUNT(*) FROM provisional_bookings
                    WHERE id_hotel = :id_hotel
                      AND id_room_type = :id_room_type
                      AND status = 'pending'
                      AND expires_at > NOW()
                      AND checkin < :checkout
                      AND checkout > :checkin
                ");
                $stmtHolds->execute([
                    ':id_hotel' => $hotelId,
                    ':id_room_type' => $idRoomType,
                    ':checkin' => $checkIn,
                    ':checkout' => $checkOut
                ]);
                $holdCount = (int)$stmtHolds->fetchColumn();

                // Calcular inventario neto disponible
                $availableCount = max(0, $totalRooms - $bookedCount - $holdCount);

                if ($availableCount > 0) {
                    $availableRooms[] = [
                        'id_room_type' => $idRoomType,
                        'room_name' => $rt['room_name'],
                        'price' => (float)$rt['price'],
                        'max_guests' => (int)$rt['max_guests'],
                        'available_qty' => $availableCount
                    ];
                }
            }

            return $availableRooms;

        } catch (PDOException $e) {
            Logger::error("QloAppService: Error al consultar disponibilidad: " . $e->getMessage());
            return $this->getMockRooms();
        }
    }

    /**
     * Crea un Carrito de compras temporal en QloApps mediante la API Web Service (XML).
     * Retorna el ID del carrito creado o un ID mock en su defecto.
     */
    public function createCart(int $hotelId, int $idRoomType, string $checkIn, string $checkOut, int $guests): string {
        $mockCartId = 'MOCK-CART-' . time() . '-' . rand(100, 999);
        
        if (empty($this->apiKey) || empty($this->apiUrl)) {
            Logger::info("QloAppService: Creación de Carrito en modo MOCK ({$mockCartId})");
            return $mockCartId;
        }

        // Esquema XML de creación de Carrito en QloApps (PrestaShop)
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

        Logger::error("QloAppService: Error al crear el carrito en QloApps. Retornando Mock.");
        return $mockCartId;
    }

    /**
     * Convierte un Carrito en una Orden confirmada (Pagada) en QloApps mediante la API.
     */
    public function confirmOrder(string $cartId, float $totalPrice, string $guestName, string $guestEmail): ?string {
        if (str_contains($cartId, 'MOCK') || empty($this->apiKey) || empty($this->apiUrl)) {
            $mockOrderId = 'MOCK-ORDER-' . time() . '-' . rand(100, 999);
            Logger::info("QloAppService: Confirmación de Orden en modo MOCK ({$mockOrderId}) para Carrito {$cartId}");
            return $mockOrderId;
        }

        // Esquema XML de creación de Orden en QloApps (PrestaShop)
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
        <current_state>2</current_state> <!-- Estado 2: Pago Aceptado -->
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
     */
    private function executeRequest(string $endpoint, string $method = 'GET', ?string $xmlData = null): ?SimpleXMLElement {
        $url = rtrim($this->apiUrl, '/') . '/' . ltrim($endpoint, '/');
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Autenticación básica: API Key como usuario, contraseña vacía
        curl_setopt($ch, CURLOPT_USERPWD, $this->apiKey . ':');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlData);
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlData);
        }
        
        if ($xmlData) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400 || !$response) {
            Logger::error("QloAppService: API Error {$httpCode}. Respuesta: {$response}");
            return null;
        }
        
        try {
            return simplexml_load_string($response);
        } catch (Exception $e) {
            Logger::error("QloAppService: Error al parsear XML de respuesta: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Habitaciones estáticas de respaldo cuando la base de datos de Hostinger está offline.
     */
    private function getMockRooms(): array {
        return [
            [
                'id_room_type' => 1,
                'room_name' => 'Habitación Matrimonial Superior',
                'price' => 90.0,
                'max_guests' => 2,
                'available_qty' => 5
            ],
            [
                'id_room_type' => 2,
                'room_name' => 'Habitación Doble Superior',
                'price' => 90.0,
                'max_guests' => 2,
                'available_qty' => 5
            ],
            [
                'id_room_type' => 3,
                'room_name' => 'Habitación Triple Estándar',
                'price' => 120.0,
                'max_guests' => 3,
                'available_qty' => 3
            ],
            [
                'id_room_type' => 4,
                'room_name' => 'Habitación Familiar Superior',
                'price' => 150.0,
                'max_guests' => 7,
                'available_qty' => 2
            ]
        ];
    }
}
