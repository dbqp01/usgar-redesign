<?php
declare(strict_types=1);

namespace App\Features\Shared\Adapters;

use App\Features\Shared\Ports\PmsPortInterface;
use App\Features\Shared\DiscountResolver;
use App\Core\Config;
use App\Core\Logger;
use App\Core\Database;
use PDO;
use PDOException;
use SimpleXMLElement;
use Exception;
use Throwable;

/**
 * Adaptador Hexagonal para la integracion con QloApps PMS.
 * Cumple estrictamente con PmsPortInterface.
 */
class QloAppAdapter implements PmsPortInterface {
    private const QLOAPPS_LOCAL_PREFIX = 'USGAR-';
    private ?PDO $pdo;
    private readonly ?string $apiUrl;
    private readonly ?string $apiKey;

    public function __construct(?PDO $pdo = null) {
        $db = Database::getInstance();
        $this->pdo = $pdo ?? $db->getConnection();

        $this->apiUrl = Config::get('QLOAPP_API_URL', 'https://cms.usgarhoteles.com/api');
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
                    (
                        SELECT COUNT(*) FROM qlo_htl_room_information ri
                        WHERE ri.id_product = rt.id_product
                    ) AS total_rooms,
                    (
                        COALESCE((
                            SELECT COUNT(DISTINCT bd.id_room) FROM qlo_htl_booking_detail bd
                            WHERE bd.id_product = rt.id_product
                              AND bd.is_cancelled = 0
                              AND bd.is_refunded = 0
                              AND bd.date_from < :date_to_booked
                              AND bd.date_to > :date_from_booked
                        ), 0) +
                        COALESCE((
                            SELECT COUNT(*) FROM provisional_bookings pb
                            WHERE pb.id_room_type = rt.id
                              AND (pb.status = 'paid' OR (pb.status = 'pending' AND pb.expires_at > NOW()))
                              AND pb.checkin < :check_out_date
                              AND pb.checkout > :check_in_date
                              AND pb.id_hotel = :id_hotel_holds
                        ), 0)
                    ) AS booked_count
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
                ':check_in_date'    => $checkIn,
                ':check_out_date'   => $checkOut,
            ]);

            $rows = $stmt->fetchAll();
            $availableRooms = [];

            // Planes de precio (Feature Price) para la tarifa No Reembolsable,
            // centralizados en el admin de QloApps. Si falla la consulta =>
            // sin descuento (fail-safe, nunca rompe la reserva).
            [$plans, $restrictionsByPlan] = $this->loadFeaturePricePlans();

            foreach ($rows as $row) {
                $totalRooms = max((int)$row['total_rooms'], 0);
                $availableCount = max(0, $totalRooms - (int)$row['booked_count']);
                $basePrice = (float)$row['price'];
                $idProduct = (int)$row['id_product'];

                $plan = DiscountResolver::pickPlan(
                    $plans[$idProduct] ?? [],
                    $restrictionsByPlan,
                    $checkIn,
                    $checkOut
                );

                $availableRooms[] = [
                    'id_room_type'       => (int)$row['id_room_type'],
                    'id_product'         => $idProduct,
                    'room_name'          => $row['room_name'],
                    'price'              => $basePrice,
                    'non_refundable_price' => DiscountResolver::nonRefundablePrice($basePrice, $plan),
                    'max_guests'         => (int)$row['max_guests'],
                    'total_rooms'        => $totalRooms,
                    'available_qty'      => $availableCount,
                ];
            }

            return $availableRooms;

        } catch (PDOException $e) {
            Logger::error('QloAppAdapter: Error en consulta SQL: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Carga los planes maestros de Feature Price (id_cart=0) y sus restricciones
     * de fechas. Devuelve [planesPorProducto, restriccionesPorPlan].
     * Schema verificado contra la BD real y el modulo hotelreservationsystem.
     */
    private function loadFeaturePricePlans(): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id_feature_price, id_product, impact_way, impact_type, impact_value
                FROM qlo_htl_room_type_feature_pricing
                WHERE id_cart = 0 AND id_guest = 0 AND id_room = 0 AND active = 1
            ");
            $stmt->execute();
            $plans = [];
            foreach ($stmt->fetchAll() as $r) {
                $plans[(int)$r['id_product']][] = $r;
            }

            $restrictions = [];
            $stmt2 = $this->pdo->prepare("
                SELECT id_feature_price, date_selection_type, special_days, date_from, date_to
                FROM qlo_htl_room_type_feature_pricing_restriction
            ");
            $stmt2->execute();
            foreach ($stmt2->fetchAll() as $r) {
                $restrictions[(int)$r['id_feature_price']][] = $r;
            }

            return [$plans, $restrictions];
        } catch (Throwable $e) {
            Logger::warning('QloAppAdapter: Feature Price Plans no disponibles (' . $e->getMessage() . '); sin descuento no reembolsable.');
            return [[], []];
        }
    }

    /**
     * Disponibilidad por día y por habitación para un rango de calendario.
     * Devuelve: [ 'YYYY-MM-DD' => [ 'id_room_type' => qty_disponible, ... ], ... ]
     *
     * El cálculo replica la semántica de getAvailableRooms() (solapamiento
     * date_from < checkout AND date_to > checkin, excluyendo canceladas/
     * reembolsadas y descontando holds activos en provisional_bookings) evaluado día a día.
     */
    public function getAvailabilityCalendar(string $from, string $to, int $idHotel = 1): array {
        if (!$this->pdo) {
            Logger::error('QloAppAdapter: DB Connection is offline. Cannot get calendar availability.');
            return [];
        }

        $fromTs = strtotime($from . ' 00:00:00');
        $toTs   = strtotime($to . ' 00:00:00');
        if ($fromTs === false || $toTs === false || $toTs < $fromTs) {
            return [];
        }
        // Protección: máx. 120 días por request
        if (($toTs - $fromTs) / 86400 > 120) {
            $toTs = $fromTs + (120 * 86400);
        }

        try {
            // Habitaciones activas con su inventario total
            $roomsStmt = $this->pdo->prepare("
                SELECT 
                    rt.id AS id_room_type,
                    rt.id_product,
                    pl.name AS room_name,
                    (
                        SELECT COUNT(*) FROM qlo_htl_room_information ri
                        WHERE ri.id_product = rt.id_product
                    ) AS total_rooms
                FROM qlo_htl_room_type rt
                INNER JOIN qlo_product p ON p.id_product = rt.id_product
                INNER JOIN qlo_product_lang pl ON pl.id_product = rt.id_product AND pl.id_lang = 1
                WHERE p.active = 1 AND rt.id_hotel = :id_hotel
            ");
            $roomsStmt->execute([':id_hotel' => $idHotel]);
            $rooms = $roomsStmt->fetchAll();

            if (empty($rooms)) {
                return [];
            }

            // Todas las reservas activas (no canceladas/refundadas) en QloApps que tocan el rango.
            $bookingsStmt = $this->pdo->prepare("
                SELECT bd.id_product, bd.date_from, bd.date_to
                FROM qlo_htl_booking_detail bd
                INNER JOIN qlo_htl_room_type rt ON rt.id_product = bd.id_product
                WHERE bd.is_cancelled = 0
                  AND bd.is_refunded = 0
                  AND bd.date_from < :range_to
                  AND bd.date_to > :range_from
                  AND rt.id_hotel = :id_hotel
            ");
            $bookingsStmt->execute([
                ':range_from' => date('Y-m-d 00:00:00', $fromTs),
                ':range_to'   => date('Y-m-d 23:59:59', $toTs),
                ':id_hotel'   => $idHotel,
            ]);
            $bookings = $bookingsStmt->fetchAll();

            // Todos los holds provisionales activos (paid o pending unexpired) en provisional_bookings
            $holdsStmt = $this->pdo->prepare("
                SELECT pb.id_room_type, pb.checkin, pb.checkout
                FROM provisional_bookings pb
                WHERE (pb.status = 'paid' OR (pb.status = 'pending' AND pb.expires_at > NOW()))
                  AND pb.checkin < :range_to_date
                  AND pb.checkout > :range_from_date
                  AND pb.id_hotel = :id_hotel
            ");
            $holdsStmt->execute([
                ':range_from_date' => date('Y-m-d', $fromTs),
                ':range_to_date'   => date('Y-m-d', $toTs),
                ':id_hotel'        => $idHotel,
            ]);
            $holds = $holdsStmt->fetchAll();

            // Índice de inventario por id_room_type
            $inventory = [];
            foreach ($rooms as $room) {
                $inventory[(int)$room['id_room_type']] = max((int)$room['total_rooms'], 0);
            }

            // Para cada día del rango, contar cuántas habitaciones ocupadas por QloApps + provisional_bookings
            $days = [];
            for ($ts = $fromTs; $ts <= $toTs; $ts += 86400) {
                $dateKey = date('Y-m-d', $ts);
                $occupied = [];

                foreach ($bookings as $b) {
                    $bFrom = strtotime((string)$b['date_from']);
                    $bTo   = strtotime((string)$b['date_to']);
                    if ($ts < $bTo && $ts >= $bFrom) {
                        $idRoomType = (int)$b['id_product'];
                        $occupied[$idRoomType] = ($occupied[$idRoomType] ?? 0) + 1;
                    }
                }

                foreach ($holds as $h) {
                    $hFrom = strtotime((string)$h['checkin'] . ' 00:00:00');
                    $hTo   = strtotime((string)$h['checkout'] . ' 00:00:00');
                    if ($ts < $hTo && $ts >= $hFrom) {
                        $idRoomType = (int)$h['id_room_type'];
                        $occupied[$idRoomType] = ($occupied[$idRoomType] ?? 0) + 1;
                    }
                }

                $dayAvailability = [];
                foreach ($inventory as $idRoomType => $total) {
                    $dayAvailability[$idRoomType] = max(0, $total - ($occupied[$idRoomType] ?? 0));
                }
                $days[$dateKey] = $dayAvailability;
            }

            return $days;

        } catch (PDOException $e) {
            Logger::error('QloAppAdapter: Error en consulta de calendario: ' . $e->getMessage());
            return [];
        }
    }

    public function createCart(int $idHotel, int $idProduct, string $checkIn, string $checkOut, int $guests = 1, float $totalPrice = 0, string $guestName = '', string $guestEmail = '', string $guestPhone = ''): string {
        if (empty($this->apiKey) || empty($this->apiUrl)) {
            Logger::error('QloAppAdapter: QloApps API key or API URL is not configured. Falling back to local cart.');
            return 'USGAR-' . bin2hex(random_bytes(6));
        }

        $nameParts = explode(' ', $guestName, 2);
        $firstName = htmlspecialchars($nameParts[0] ?: Config::get('DEFAULT_GUEST_NAME', 'Guest'), ENT_XML1);
        $lastName = htmlspecialchars($nameParts[1] ?? Config::get('DEFAULT_GUEST_NAME', 'Guest'), ENT_XML1);
        $safeEmail = htmlspecialchars($guestEmail ?: Config::get('DEFAULT_REPLY_EMAIL'), ENT_XML1);
        $phone = htmlspecialchars($guestPhone ?: Config::get('OTA_DEFAULT_PHONE', '000000000'), ENT_XML1);
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
                <total_tax>0</total_tax>
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
                            <total_tax>0</total_tax>
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
        return self::QLOAPPS_LOCAL_PREFIX . bin2hex(random_bytes(6));
    }

    public function confirmOrder(string $cartId, float $totalPrice, string $guestName, string $guestEmail): ?string {
        if (!$this->pdo) {
            Logger::error("QloAppAdapter: PDO database connection offline. Cannot confirm order {$cartId}.");
            return null;
        }

        // Si cartId inicia con USGAR- o es un cart_id local
        $stmt = $this->pdo->prepare("SELECT * FROM provisional_bookings WHERE cart_id = ? OR cart_id = ?");
        $fullCartId = str_starts_with($cartId, self::QLOAPPS_LOCAL_PREFIX) ? $cartId : self::QLOAPPS_LOCAL_PREFIX . $cartId;
        $stmt->execute([$cartId, $fullCartId]);
        $hold = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$hold) {
            Logger::error("QloAppAdapter: No se encontró la reserva provisional local para {$cartId}");
            return null;
        }

        $idHotel = (int)($hold['id_hotel'] ?? Config::get('DEFAULT_HOTEL_ID', '1'));
        $idRoomType = (int)$hold['id_room_type'];
        $checkIn = (string)$hold['checkin'];
        $checkOut = (string)$hold['checkout'];

        $guestData = json_decode((string)($hold['guest_data'] ?? '{}'), true) ?? [];
        $guests = (int)($guestData['guests'] ?? 1);
        $phone = (string)($guestData['phone'] ?? Config::get('OTA_DEFAULT_PHONE', '000000000'));

        $nameParts = explode(' ', trim($guestName), 2);
        $firstName = $nameParts[0] ?: 'Guest';
        $lastName = $nameParts[1] ?? 'Guest';

        try {
            $this->pdo->beginTransaction();

            // 1. Cliente: buscar por email o insertar
            $stmtCust = $this->pdo->prepare("SELECT id_customer FROM qlo_customer WHERE email = ?");
            $stmtCust->execute([$guestEmail]);
            $idCustomer = (int)$stmtCust->fetchColumn();

            if ($idCustomer === 0) {
                $stmtInsCust = $this->pdo->prepare("
                    INSERT INTO qlo_customer (id_shop_group, id_shop, firstname, lastname, email, passwd, secure_key, active, is_guest, date_add, date_upd)
                    VALUES (1, 1, ?, ?, ?, md5(rand()), md5(rand()), 1, 1, NOW(), NOW())
                ");
                $stmtInsCust->execute([$firstName, $lastName, $guestEmail]);
                $idCustomer = (int)$this->pdo->lastInsertId();
            }

            // 2. Carrito QloApps
            $stmtCart = $this->pdo->prepare("
                INSERT INTO qlo_cart (id_shop_group, id_shop, id_lang, id_currency, id_customer, id_address_delivery, id_address_invoice, date_add, date_upd)
                VALUES (1, 1, 1, 2, ?, 0, 0, NOW(), NOW())
            ");
            $stmtCart->execute([$idCustomer]);
            $idCart = (int)$this->pdo->lastInsertId();

            // 3. Referencia única de orden (9 caracteres alfanuméricos en mayúsculas)
            $ref = strtoupper(substr(bin2hex(random_bytes(5)), 0, 9));

            // 4. Orden QloApps (current_state = 2: Pago completo recibido, source = $fullCartId)
            $stmtOrder = $this->pdo->prepare("
                INSERT INTO qlo_orders (
                    reference, id_shop_group, id_shop, id_carrier, id_lang, id_customer, id_cart, id_currency,
                    id_address_delivery, id_address_invoice, current_state, payment, total_paid, total_paid_tax_incl,
                    total_paid_tax_excl, total_products, total_products_wt, conversion_rate, module, valid, source, date_add, date_upd
                ) VALUES (
                    ?, 1, 1, 0, 1, ?, ?, 2,
                    0, 0, 2, 'Mercado Pago (Online)', ?, ?,
                    ?, ?, ?, 1.0, 'mercadopago', 1, ?, NOW(), NOW()
                )
            ");
            $stmtOrder->execute([$ref, $idCustomer, $idCart, $totalPrice, $totalPrice, $totalPrice, $totalPrice, $totalPrice, $fullCartId]);
            $idOrder = (int)$this->pdo->lastInsertId();

            // 5. Nombre del producto de la habitación
            $stmtRoomName = $this->pdo->prepare("SELECT pl.name FROM qlo_product_lang pl WHERE pl.id_product = ? AND pl.id_lang = 1 LIMIT 1");
            $stmtRoomName->execute([$idRoomType]);
            $roomName = (string)($stmtRoomName->fetchColumn() ?: 'Habitación USGAR');

            // 6. Detalle de orden
            $stmtDetail = $this->pdo->prepare("
                INSERT INTO qlo_order_detail (
                    id_order, id_shop, product_id, product_name, product_quantity, product_price,
                    total_price_tax_incl, total_price_tax_excl, unit_price_tax_incl, unit_price_tax_excl, is_booking_product
                ) VALUES (
                    ?, 1, ?, ?, 1, ?,
                    ?, ?, ?, ?, 1
                )
            ");
            $stmtDetail->execute([$idOrder, $idRoomType, $roomName, $totalPrice, $totalPrice, $totalPrice, $totalPrice, $totalPrice]);

            // 7. Detalle de reserva en carrito (qlo_htl_cart_booking_data)
            $stmtBookingData = $this->pdo->prepare("
                INSERT INTO qlo_htl_cart_booking_data (
                    id_cart, id_guest, id_order, id_customer, id_currency, id_product, id_room, id_hotel,
                    quantity, booking_type, comment, is_back_order, extra_demands, date_from, date_to, adults, children, child_ages, date_add, date_upd
                ) VALUES (
                    ?, 0, ?, ?, 2, ?, 1, ?,
                    1, 1, '', 0, '[]', ?, ?, ?, 0, '[]', NOW(), NOW()
                )
            ");
            $stmtBookingData->execute([
                $idCart, $idOrder, $idCustomer, $idRoomType, $idHotel,
                $checkIn . ' 00:00:00', $checkOut . ' 00:00:00', $guests
            ]);

            // 8. Detalle en qlo_htl_booking_detail para descuento inmediato de disponibilidad
            $stmtHtlDetail = $this->pdo->prepare("
                INSERT INTO qlo_htl_booking_detail (
                    id_product, id_order, id_order_detail, id_cart, id_room, id_hotel, id_customer,
                    booking_type, id_status, comment, check_in, check_out, planned_check_out, date_from, date_to,
                    total_price_tax_excl, total_price_tax_incl, total_paid_amount, is_back_order, hotel_name,
                    room_type_name, city, phone, email, adults, children, child_ages, is_refunded, is_cancelled, date_add, date_upd
                ) VALUES (
                    ?, ?, 0, ?, 1, ?, ?,
                    1, 1, '', ?, ?, ?, ?, ?,
                    ?, ?, ?, 0, 'USGAR Hotels',
                    ?, 'San Pedro', ?, ?, ?, 0, '[]', 0, 0, NOW(), NOW()
                )
            ");
            $stmtHtlDetail->execute([
                $idRoomType, $idOrder, $idCart, $idHotel, $idCustomer,
                $checkIn . ' 12:00:00', $checkOut . ' 10:30:00', $checkOut . ' 10:30:00', $checkIn . ' 12:00:00', $checkOut . ' 10:30:00',
                $totalPrice, $totalPrice, $totalPrice, $roomName, $phone, $guestEmail, $guests
            ]);

            // 9. Historial de estado de orden (Estado 2 = Pago completo recibido)
            $stmtHistory = $this->pdo->prepare("
                INSERT INTO qlo_order_history (id_employee, id_order, id_order_state, date_add)
                VALUES (0, ?, 2, NOW())
            ");
            $stmtHistory->execute([$idOrder]);

            $this->pdo->commit();
            Logger::info("QloAppAdapter: Orden #{$idOrder} (Ref: {$ref}) creada exitosamente en QloApps via PDO atómico para Cart {$cartId}");
            return (string)$idOrder;

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Logger::error("QloAppAdapter: Excepción al crear orden QloApps via PDO para Cart {$cartId}: " . $e->getMessage());
            return null;
        }
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

    /**
     * Dedup del consumidor: verifica si la orden con el cartId o externalReference
     * ya fue confirmada en QloApps.
     *
     * FAIL-CLOSED: si la BD falla, propaga excepción para que el outbox reintente.
     */
    public function isOrderConfirmed(string $externalReference): bool {
        $cartId = $externalReference;
        $fullCartId = str_starts_with($cartId, self::QLOAPPS_LOCAL_PREFIX) ? $cartId : self::QLOAPPS_LOCAL_PREFIX . $cartId;
        $rawCartId = str_starts_with($cartId, self::QLOAPPS_LOCAL_PREFIX) ? substr($cartId, strlen(self::QLOAPPS_LOCAL_PREFIX)) : $cartId;

        if ($this->pdo) {
            try {
                $stmt = $this->pdo->prepare("
                    SELECT current_state
                    FROM qlo_orders
                    WHERE source = ? OR source = ? OR source = ? OR id_order = ?
                    LIMIT 1
                ");
                $numericId = ctype_digit($rawCartId) ? (int)$rawCartId : 0;
                $stmt->execute([$externalReference, $fullCartId, $rawCartId, $numericId]);
                $state = $stmt->fetchColumn();

                if ($state !== false && (int)$state === 2) {
                    return true;
                }
                return false;

            } catch (Throwable $e) {
                Logger::error("QloAppAdapter: Error en DB isOrderConfirmed ({$externalReference}): " . $e->getMessage());
                throw new Exception('Error de base de datos durante isOrderConfirmed (' . $externalReference . ').');
            }
        }

        // Fallback HTTP si PDO no está conectado
        if (empty($this->apiKey) || empty($this->apiUrl)) {
            throw new Exception('QloApps API key or API URL is not configured.');
        }

        if ($cartId === '' || !ctype_digit($cartId)) {
            return false;
        }

        $xml = $this->executeRequest('bookings/' . $cartId, 'GET');
        if ($xml === null) {
            throw new Exception('QloApps API no disponible durante isOrderConfirmed (' . $cartId . ').');
        }

        $paymentStatus = isset($xml->booking->payment_status)
            ? trim((string)$xml->booking->payment_status)
            : '';
        return $paymentStatus === '1';
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
