<?php
declare(strict_types=1);

namespace App\Features\Shared\Adapters;

use App\Features\Shared\Ports\PmsPortInterface;
use App\Features\Shared\PmsUnavailableException;
use App\Features\Shared\DiscountResolver;
use App\Core\Config;
use App\Core\Logger;
use App\Core\Database;
use App\Core\BookingStatus;
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

    // Literales de la instalacion QloApps verificada.
    private const SHOP_GROUP_ID = 1;
    private const SHOP_ID = 1;
    private const LANG_ID = 1;
    private const ORDER_STATE_PAID = 2;        // current_state / id_order_state
    private const CHECKIN_TIME = '12:00:00';   // horario de entrada del hotel
    private const CHECKOUT_TIME = '10:30:00';  // horario de salida del hotel
    private const HOTEL_NAME = 'USGAR Hotels';
    private const HOTEL_CITY = 'San Pedro';

    private ?PDO $pdo;
    private readonly ?string $apiUrl;
    private readonly ?string $apiKey;
    private bool $roomLedgerChecked = false;

    public function __construct(?PDO $pdo = null) {
        $db = Database::getInstance();
        $this->pdo = $pdo ?? $db->getConnection();

        $this->apiUrl = Config::get('QLOAPP_API_URL', 'https://cms.usgarhoteles.com/api');
        $this->apiKey = Config::get('QLOAPP_API_KEY');
    }

    /**
     * Nombres de huesped en el formato que exige el PMS.
     *
     * PrestaShop/QloApps valida firstname y lastname con Validate::isName(),
     * que rechaza digitos y !<>,;?=+()@#"°{}_$% (classes/Validate.php del CMS).
     * Un cliente guardado con un nombre invalido hace revocar el webservice al
     * actualizarlo: PrestaShopException que el dispatcher no captura -> HTTP
     * 500 sin cuerpo. Normalizamos aqui todo nombre que entra al PMS.
     *
     * @return array{0:string,1:string} [firstname, lastname]
     */
    public static function pmsGuestNames(string $guestName): array {
        $parts = explode(' ', trim($guestName), 2);
        $default = (string)Config::get('DEFAULT_GUEST_NAME', 'Guest');
        $names = [];
        foreach ([$parts[0], $parts[1] ?? ''] as $part) {
            $clean = preg_replace('/[0-9!<>;,?=+()@#"°{}_$%:]/u', ' ', $part) ?? '';
            $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
            $names[] = $clean === '' ? $default : mb_substr($clean, 0, 32);
        }
        return $names;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAvailableRooms(string $checkIn, string $checkOut, int $idHotel = 1, ?int $idLang = null): array {
        if (!$this->pdo) {
            Logger::error('QloAppAdapter: DB Connection is offline. Cannot get availability.');
            throw new PmsUnavailableException('PMS database is offline.');
        }
        $idLang = $idLang ?? (int)Config::get('QLOAPPS_DEFAULT_LANG_ID', '1');
        $this->ensureRoomLedgerTable();

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
                        WHERE ri.id_product = rt.id_product AND ri.id_hotel = rt.id_hotel
                    ) AS total_rooms,
                    (
                        COALESCE((
                            SELECT COUNT(*) FROM qlo_htl_booking_detail bd
                            WHERE bd.id_product = rt.id_product
                              AND bd.id_hotel = :id_hotel_booked
                              AND bd.is_cancelled = 0
                              AND bd.is_refunded = 0
                              AND bd.date_from < :date_to_booked
                              AND bd.date_to > :date_from_booked
                              -- El hold del webservice (POST /api/bookings) y el hold
                              -- local son la MISMA reserva: se cuenta una sola vez
                              -- (el id_order del booking del webservice es el cart_id).
                              AND NOT EXISTS (
                                  SELECT 1 FROM provisional_bookings pb
                                  WHERE pb.cart_id = CAST(bd.id_order AS CHAR)
                                    AND (pb.status = 'paid' OR (pb.status = 'pending' AND pb.expires_at > NOW()))
                              )
                        ), 0) +
                        COALESCE((
                            SELECT COALESCE(SUM(CASE WHEN pbr.quantity IS NULL THEN 1 ELSE pbr.quantity END), 0)
                            FROM provisional_bookings pb
                            LEFT JOIN provisional_booking_rooms pbr
                              ON pbr.cart_id = pb.cart_id AND pbr.id_room_type = rt.id
                            WHERE (pbr.id_room_type IS NOT NULL OR pb.id_room_type = rt.id)
                              AND (pb.status = 'paid' OR (pb.status = 'pending' AND pb.expires_at > NOW()))
                              AND pb.checkin < :check_out_date
                              AND pb.checkout > :check_in_date
                              AND pb.id_hotel = :id_hotel_holds
                              AND NOT EXISTS (
                                  SELECT 1 FROM qlo_orders qo
                                  WHERE qo.current_state = 2
                                    AND (qo.source = pb.cart_id OR qo.source = CONCAT('USGAR-', pb.cart_id))
                              )
                        ), 0) +
                        COALESCE((
                            SELECT COUNT(DISTINCT rd.id_room)
                            FROM qlo_htl_room_disable_dates rd
                            INNER JOIN qlo_htl_room_information ri_disable ON ri_disable.id = rd.id_room
                            WHERE rd.id_room_type = rt.id
                              AND ri_disable.id_hotel = :id_hotel_disabled
                              AND rd.date_from < :date_to_disabled
                              AND rd.date_to > :date_from_disabled
                        ), 0)
                    ) AS booked_count
                FROM qlo_htl_room_type rt
                INNER JOIN qlo_product p ON p.id_product = rt.id_product
                INNER JOIN qlo_product_lang pl ON pl.id_product = rt.id_product AND pl.id_lang = :id_lang
                WHERE p.active = 1 AND rt.id_hotel = :id_hotel
            ");

            $stmt->execute([
                ':id_hotel'         => $idHotel,
                ':id_hotel_holds'   => $idHotel,
                ':id_hotel_booked'  => $idHotel,
                ':id_hotel_disabled' => $idHotel,
                ':id_lang'          => $idLang,
                ':date_from_booked' => $checkIn . ' ' . self::CHECKIN_TIME,
                ':date_to_booked'   => $checkOut . ' ' . self::CHECKOUT_TIME,
                ':check_in_date'    => $checkIn,
                ':check_out_date'   => $checkOut,
                ':date_from_disabled' => $checkIn,
                ':date_to_disabled'   => $checkOut,
            ]);

            $rows = $stmt->fetchAll();
            $availableRooms = [];

            // Reservas/bloqueos importados por el dueno (manual_blocks) que
            // aun no descuenta el PMS: sin esto una reserva importada sigue
            // vendiendose en la web. Agrupado por tipo y una vez por cuarto
            // fisico (el import puede repetir filas o solapar fechas).
            $manualBlockedByType = [];
            foreach ($this->fetchManualBlockedRooms($checkIn, $checkOut, $idHotel) as $mb) {
                $manualBlockedByType[$mb['id_room_type']][$mb['room_id']] = true;
            }

            // Planes de precio (Feature Price) para la tarifa No Reembolsable,
            // centralizados en el admin de QloApps. Si falla la consulta =>
            // sin descuento (fail-safe, nunca rompe la reserva).
            [$plans, $restrictionsByPlan] = $this->loadFeaturePricePlans();

            foreach ($rows as $row) {
                $totalRooms = max((int)$row['total_rooms'], 0);
                $booked = (int)$row['booked_count'] + count($manualBlockedByType[(int)$row['id_room_type']] ?? []);
                $availableCount = max(0, $totalRooms - $booked);
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
            throw new PmsUnavailableException('PMS availability query failed.', 0, $e);
        }
    }

    /**
     * Cuartos con reserva/bloqueo importado (manual_blocks) en el rango, sin
     * los que ya descuentan como disable_date: el mismo cuarto fisico no debe
     * restar dos unidades. Una fila por cuarto aunque el import tenga
     * duplicados o fechas solapadas.
     *
     * Sin tabla manual_blocks no hay importaciones; otros errores abortan la
     * consulta para no vender una habitacion cuya disponibilidad es incierta.
     * Cuenta cualquier estado (confirmed/hold): un hold importado tambien
     * reserva inventario, igual que un hold web.
     *
     * @param bool $skipAlreadyDisabled true para disponibilidad de un rango
     *        (un cuarto ya descontado por disable_dates cuenta una sola vez);
     *        false para el calendario, que necesita todas las filas para
     *        descontar dia a dia aunque el disable cubra solo otro tramo.
     * @return list<array{id_room_type: int, room_id: int, checkin: string, checkout: string}>
     */
    private function fetchManualBlockedRooms(string $from, string $to, int $hotelId, bool $skipAlreadyDisabled = true): array {
        if (!$this->pdo) {
            return [];
        }
        $excludeDisabled = $skipAlreadyDisabled
            ? "AND NOT EXISTS (
                      SELECT 1 FROM qlo_htl_room_disable_dates rd_manual
                      INNER JOIN qlo_htl_room_information ri_manual ON ri_manual.id = rd_manual.id_room
                      WHERE rd_manual.id_room = mb.room_id
                        AND rd_manual.id_room_type = rt.id
                        AND ri_manual.id_hotel = :id_hotel_manual_disabled
                        AND rd_manual.date_from < :date_to_manual_disabled
                        AND rd_manual.date_to > :date_from_manual_disabled
                  )
              AND NOT EXISTS (
                  SELECT 1 FROM qlo_htl_booking_detail bd_manual
                  WHERE bd_manual.id_room = mb.room_id
                    AND bd_manual.id_product = rt.id_product
                    AND bd_manual.id_hotel = :id_hotel_manual_booked
                    AND bd_manual.is_cancelled = 0 AND bd_manual.is_refunded = 0
                    AND NOT EXISTS (
                        SELECT 1 FROM provisional_bookings pb_manual
                        WHERE pb_manual.cart_id = CAST(bd_manual.id_order AS CHAR)
                          AND (pb_manual.status = 'paid' OR (pb_manual.status = 'pending' AND pb_manual.expires_at > NOW()))
                    )
                    AND bd_manual.date_from < :date_to_manual_booked
                    AND bd_manual.date_to > :date_from_manual_booked
              )"
            : '';
        $params = [
            ':id_hotel_manual'  => $hotelId,
            ':date_from_manual' => $from,
            ':date_to_manual'   => $to,
        ];
        if ($skipAlreadyDisabled) {
            $params += [
                ':id_hotel_manual_disabled'  => $hotelId,
                ':date_from_manual_disabled' => $from,
                ':date_to_manual_disabled'   => $to,
                ':id_hotel_manual_booked'    => $hotelId,
                ':date_from_manual_booked'   => $from . ' ' . self::CHECKIN_TIME,
                ':date_to_manual_booked'     => $to . ' ' . self::CHECKOUT_TIME,
            ];
        }
        try {
            $stmt = $this->pdo->prepare("
                SELECT rt.id AS id_room_type, mb.room_id, mb.checkin, mb.checkout
                FROM manual_blocks mb
                LEFT JOIN qlo_htl_room_information ri_mb ON ri_mb.id = mb.room_id AND ri_mb.id_hotel = mb.id_hotel
                LEFT JOIN qlo_htl_room_type rt
                  ON rt.id_product = ri_mb.id_product AND rt.id_hotel = ri_mb.id_hotel
                WHERE mb.id_hotel = :id_hotel_manual
                  AND mb.checkin < :date_to_manual
                  AND mb.checkout > :date_from_manual
                  AND (rt.id IS NULL OR (1=1 {$excludeDisabled}))
            ");
            $stmt->execute($params);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                if ($r['id_room_type'] === null) {
                    throw new PmsUnavailableException('Manual block references an unknown room.');
                }
                $out[] = [
                    'id_room_type' => (int)$r['id_room_type'],
                    'room_id'      => (int)$r['room_id'],
                    'checkin'      => substr((string)$r['checkin'], 0, 10),
                    'checkout'     => substr((string)$r['checkout'], 0, 10),
                ];
            }
            return $out;
        } catch (PDOException $e) {
            $missingTable = str_contains($e->getMessage(), 'manual_blocks')
                && ($e->getCode() === '42S02' || str_contains($e->getMessage(), 'no such table: manual_blocks'));
            if (!$missingTable) {
                throw $e;
            }
            return [];
        }
    }

    /**
     * Carga los planes maestros de Feature Price (id_cart=0) y sus restricciones
     * de fechas. Devuelve [planesPorProducto, restriccionesPorPlan].
     * Schema verificado contra la BD real y el modulo hotelreservationsystem.
     *
     * @return array{0: array<int, list<array<string,mixed>>>, 1: array<int, list<array<string,mixed>>>}
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
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $plans[(int)$r['id_product']][] = $r;
            }

            $restrictions = [];
            $stmt2 = $this->pdo->prepare("
                SELECT id_feature_price, date_selection_type, special_days, date_from, date_to
                FROM qlo_htl_room_type_feature_pricing_restriction
            ");
            $stmt2->execute();
            foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
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
     *
     * @return array<string, array<int, int>>
     */
    public function getAvailabilityCalendar(string $from, string $to, int $idHotel = 1): array {
        if (!$this->pdo) {
            Logger::error('QloAppAdapter: DB Connection is offline. Cannot get calendar availability.');
            throw new PmsUnavailableException('PMS database is offline.');
        }

        // Mismo default que getAvailableRooms() (P2-3: id_lang ya no hardcodeado)
        $idLang = (int)Config::get('QLOAPPS_DEFAULT_LANG_ID', '1');
        $this->ensureRoomLedgerTable();

        $fromTs = strtotime($from . ' 00:00:00');
        $toTs   = strtotime($to . ' 00:00:00');
        if ($fromTs === false || $toTs === false || $toTs < $fromTs) {
            return [];
        }
        // Protección: máx. 120 días por request
        if (($toTs - $fromTs) / 86400 > 120) {
            $toTs = $fromTs + (120 * 86400);
        }

        $rangeEndExclusive = date('Y-m-d', strtotime('+1 day', $toTs));
        try {
            // Habitaciones activas con su inventario total
            $roomsStmt = $this->pdo->prepare("
                SELECT 
                    rt.id AS id_room_type,
                    rt.id_product,
                    pl.name AS room_name,
                    (
                        SELECT COUNT(*) FROM qlo_htl_room_information ri
                        WHERE ri.id_product = rt.id_product AND ri.id_hotel = rt.id_hotel
                    ) AS total_rooms
                FROM qlo_htl_room_type rt
                INNER JOIN qlo_product p ON p.id_product = rt.id_product
                INNER JOIN qlo_product_lang pl ON pl.id_product = rt.id_product AND pl.id_lang = {$idLang}
                WHERE p.active = 1 AND rt.id_hotel = :id_hotel
            ");
            $roomsStmt->execute([':id_hotel' => $idHotel]);
            $rooms = $roomsStmt->fetchAll();

            if (empty($rooms)) {
                return [];
            }

            // Todas las reservas activas (no canceladas/refundadas) en QloApps que tocan el rango.
            $bookingsStmt = $this->pdo->prepare("
                SELECT bd.id_product, bd.id_room, rt.id AS room_type_id, bd.date_from, bd.date_to
                FROM qlo_htl_booking_detail bd
                INNER JOIN qlo_htl_room_type rt ON rt.id_product = bd.id_product
                WHERE bd.is_cancelled = 0
                  AND bd.is_refunded = 0
                  AND bd.date_from < :range_to
                  AND bd.date_to > :range_from
                  AND bd.id_hotel = :id_hotel_booking
                  AND rt.id_hotel = :id_hotel
            ");
            $bookingsStmt->execute([
                ':range_from' => date('Y-m-d 00:00:00', $fromTs),
                ':range_to'   => date('Y-m-d 23:59:59', $toTs),
                ':id_hotel'   => $idHotel,
                ':id_hotel_booking' => $idHotel,
            ]);
            $bookings = $bookingsStmt->fetchAll();

            // Todos los holds provisionales activos (paid o pending unexpired) en provisional_bookings
            $holdsStmt = $this->pdo->prepare("
                SELECT COALESCE(pbr.id_room_type, pb.id_room_type) AS id_room_type,
                       COALESCE(pbr.quantity, 1) AS quantity,
                       pb.checkin, pb.checkout
                FROM provisional_bookings pb
                LEFT JOIN provisional_booking_rooms pbr ON pbr.cart_id = pb.cart_id
                WHERE (pb.status = 'paid' OR (pb.status = 'pending' AND pb.expires_at > NOW()))
                  AND pb.checkin < :range_to_date
                  AND pb.checkout > :range_from_date
                  AND pb.id_hotel = :id_hotel
                  AND NOT EXISTS (
                      SELECT 1 FROM qlo_orders qo
                      WHERE qo.current_state = 2
                        AND (qo.source = pb.cart_id OR qo.source = CONCAT('USGAR-', pb.cart_id))
                  )
            ");
            $holdsStmt->execute([
                ':range_from_date' => date('Y-m-d', $fromTs),
                ':range_to_date'   => $rangeEndExclusive,
                ':id_hotel'        => $idHotel,
            ]);
            $holds = $holdsStmt->fetchAll();

            // Bloqueos del dueño / mantenimiento (qlo_htl_room_disable_dates):
            // descuentan disponibilidad igual que una reserva — nadie compra en
            // la web una habitación bloqueada.
            $disabledStmt = $this->pdo->prepare("
                SELECT rd.id_room_type, rd.id_room, rd.date_from, rd.date_to
                FROM qlo_htl_room_disable_dates rd
                INNER JOIN qlo_htl_room_information ri_disable ON ri_disable.id = rd.id_room
                WHERE rd.date_from < :range_to_disabled
                  AND rd.date_to > :range_from_disabled
                  AND ri_disable.id_hotel = :id_hotel_disabled
            ");
            $disabledStmt->execute([
                ':range_from_disabled' => date('Y-m-d', $fromTs),
                ':range_to_disabled'   => $rangeEndExclusive,
                ':id_hotel_disabled'   => $idHotel,
            ]);
            $disabled = $disabledStmt->fetchAll();

            // Reservas/bloqueos importados por el dueno (manual_blocks): mismo
            // descuento que un disable_date, sin doblar el cuarto si ya lo esta.
            $manualBlocks = $this->fetchManualBlockedRooms(
                date('Y-m-d', $fromTs),
                $rangeEndExclusive,
                $idHotel,
                false
            );

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
                $bookedRoomIds = [];

                foreach ($bookings as $b) {
                    // QloApps stores booking timestamps at check-in (12:00)
                    // and check-out (10:30). Calendar availability is a
                    // night/date contract, so comparing midnight timestamps
                    // shifts a 12:00 -> 10:30 booking onto the checkout day.
                    // Compare date-only values with an inclusive check-in and
                    // exclusive check-out boundary instead.
                    $bFrom = substr((string)$b['date_from'], 0, 10);
                    $bTo   = substr((string)$b['date_to'], 0, 10);
                    if ($dateKey >= $bFrom && $dateKey < $bTo) {
                        // Booking detail stores the product id; availability
                        // inventory is keyed by qlo room type id. Keep the
                        // explicit join alias as the source of truth, with a
                        // legacy fallback for test/older adapters.
                        $idRoomType = (int)($b['room_type_id'] ?? $b['id_room_type'] ?? $b['id_product'] ?? 0);
                        $occupied[$idRoomType] = ($occupied[$idRoomType] ?? 0) + 1;
                        if (!empty($b['id_room'])) {
                            $bookedRoomIds[$idRoomType][(int)$b['id_room']] = true;
                        }
                    }
                }

                foreach ($holds as $h) {
                    $hFrom = substr((string)$h['checkin'], 0, 10);
                    $hTo   = substr((string)$h['checkout'], 0, 10);
                    if ($dateKey >= $hFrom && $dateKey < $hTo) {
                        $idRoomType = (int)$h['id_room_type'];
                        $occupied[$idRoomType] = ($occupied[$idRoomType] ?? 0) + (int)($h['quantity'] ?? 1);
                    }
                }

                // Bloqueos: una habitación física bloqueada = 1 unidad del tipo
                // ocupada (set por id_room para no doblar solapes de fechas ni
                // de fuentes: manual_blocks + disable_dates).
                $blockedRoomIds = [];
                foreach ($disabled as $d) {
                    $dFrom = substr((string)$d['date_from'], 0, 10);
                    $dTo   = substr((string)$d['date_to'], 0, 10);
                    if ($dateKey >= $dFrom && $dateKey < $dTo) {
                        $blockedRoomIds[(int)$d['id_room_type']][(int)$d['id_room']] = true;
                    }
                }
                foreach ($manualBlocks as $m) {
                    if ($dateKey >= $m['checkin'] && $dateKey < $m['checkout']) {
                        $blockedRoomIds[$m['id_room_type']][$m['room_id']] = true;
                    }
                }
                foreach ($blockedRoomIds as $idRoomType => $roomSet) {
                    $occupied[$idRoomType] = ($occupied[$idRoomType] ?? 0) + count(array_diff_key($roomSet, $bookedRoomIds[$idRoomType] ?? []));
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
            throw new PmsUnavailableException('PMS calendar query failed.', 0, $e);
        }
    }

    /** Bootstrap the additive ledger once per adapter instance, never per read. */
    private function ensureRoomLedgerTable(): void {
        if ($this->roomLedgerChecked || !$this->pdo) {
            return;
        }
        $this->roomLedgerChecked = true;
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS provisional_booking_rooms (
                cart_id VARCHAR(64) NOT NULL,
                id_room_type INT NOT NULL,
                quantity INT NOT NULL DEFAULT 1,
                PRIMARY KEY (cart_id, id_room_type),
                INDEX idx_room_type (id_room_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            Logger::warning('QloAppAdapter: ledger migration deferred: ' . $e->getMessage());
        }
    }

    /**
     * Tasa de conversion USD -> PEN del PMS (qlo_currency, iso_code PEN).
     * Fuente unica coherente con el back-office; Config queda como FAIL-SAFE
     * si la consulta falla (nunca rompe la cotizacion).
     */
    public function getExchangeRatePEN(): float {
        if (!$this->pdo) {
            return (float)Config::get('EXCHANGE_RATE_USD_PEN', '3.80');
        }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT conversion_rate FROM qlo_currency WHERE iso_code = 'PEN' AND active = 1 LIMIT 1"
            );
            $stmt->execute();
            $rate = $stmt->fetchColumn();
            return $rate !== false && (float)$rate > 0
                ? (float)$rate
                : (float)Config::get('EXCHANGE_RATE_USD_PEN', '3.80');
        } catch (Throwable $e) {
            Logger::warning('QloAppAdapter: tasa PEN no disponible (' . $e->getMessage() . '); fallback Config.');
            return (float)Config::get('EXCHANGE_RATE_USD_PEN', '3.80');
        }
    }

    /**
     * Crea un cart/booking en QloApps con UNA o VARIAS habitaciones (multi-room).
     * $rooms = list<array{id_product:int, guests:int, price:float}> (price = aporte
     * total de esa habitacion: base x nights + cargo extra).
     * El XML agrupa por tipo de habitacion (id_product) con number_of_rooms=N y N
     * <room> hijos — schema verificado en el codigo fuente de QloApps
     * (WebserviceSpecificManagementBookings::formatRoomTypesInRequestData:
     * duplicados del mismo tipo+ fechas se fusionan sumando number_of_rooms).
     *
     * @param list<array{id_product:int, guests:int, price:float}> $rooms
     */
    public function createCartMulti(int $idHotel, array $rooms, string $checkIn, string $checkOut, string $guestName = '', string $guestEmail = '', string $guestPhone = '', float $totalPrice = 0): string
    {
        if (empty($this->apiKey) || empty($this->apiUrl)) {
            Logger::error('QloAppAdapter: QloApps API key or API URL is not configured. Falling back to local cart.');
            return 'USGAR-' . bin2hex(random_bytes(6));
        }

        [$firstName, $lastName] = self::pmsGuestNames($guestName);
        $firstName = htmlspecialchars($firstName, ENT_XML1);
        $lastName = htmlspecialchars($lastName, ENT_XML1);
        $safeEmail = htmlspecialchars($guestEmail ?: Config::get('DEFAULT_REPLY_EMAIL'), ENT_XML1);
        $phone = htmlspecialchars($guestPhone ?: Config::get('OTA_DEFAULT_PHONE', '000000000'), ENT_XML1);
        $currency = Config::get('MERCADO_PAGO_CURRENCY', 'USD');

        // Agrupar por tipo de habitacion (mismas fechas para todas).
        $grouped = [];
        foreach ($rooms as $r) {
            $pid = (int)$r['id_product'];
            if ($pid <= 0) {
                continue;
            }
            $grouped[$pid][] = $r;
        }

        $roomTypesXml = '';
        foreach ($grouped as $pid => $group) {
            $roomsXml = '';
            foreach ($group as $r) {
                $adults = max(1, (int)$r['guests']);
                $price = (float)$r['price'];
                $roomsXml .= "<room><adults>{$adults}</adults><child>0</child>"
                    . "<unit_price_without_tax>{$price}</unit_price_without_tax>"
                    . '<total_tax>0</total_tax></room>';
            }
            $roomTypesXml .= "<room_type>"
                . "<id_room_type>{$pid}</id_room_type>"
                . "<checkin_date>{$checkIn} 12:00:00</checkin_date>"
                . "<checkout_date>{$checkOut} 10:00:00</checkout_date>"
                . '<number_of_rooms>' . count($group) . '</number_of_rooms>'
                . "<rooms>{$roomsXml}</rooms>"
                . '</room_type>';
        }

        // payment_status valido para HOLD sin pagar: 3 = Awaiting
        // (WebserviceSpecificManagementBookings: API_BOOKING_PAYMENT_STATUS_*
        // 1=Completed, 2=Partial, 3=Awaiting; 0 => 400 "Estado de pago invalido").
        $xmlData = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<qloapps xmlns:xlink="http://www.w3.org/1999/xlink">
    <booking>
        <id_property>{$idHotel}</id_property>
        <currency>{$currency}</currency>
        <booking_status>1</booking_status>
        <payment_status>3</payment_status>
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
                {$roomTypesXml}
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

    public function createCart(int $idHotel, int $idProduct, string $checkIn, string $checkOut, int $guests = 1, float $totalPrice = 0, string $guestName = '', string $guestEmail = '', string $guestPhone = ''): string {
        return $this->createCartMulti($idHotel, [
            ['id_product' => $idProduct, 'guests' => $guests, 'price' => $totalPrice],
        ], $checkIn, $checkOut, $guestName, $guestEmail, $guestPhone, $totalPrice);
    }

    /** @param array<int|string,mixed>|null $roomLines */
    public function confirmOrder(
        string $cartId,
        float $totalPrice,
        string $guestName,
        string $guestEmail,
        ?int $idRoom = null,
        string $module = 'mercadopago',
        string $paymentLabel = 'Mercado Pago (Online)',
        ?array $roomLines = null
    ): ?string {
        if (!$this->pdo) {
            Logger::error("QloAppAdapter: PDO database connection offline. Cannot confirm order {$cartId}.");
            return null;
        }

        $fullCartId = str_starts_with($cartId, self::QLOAPPS_LOCAL_PREFIX) ? $cartId : self::QLOAPPS_LOCAL_PREFIX . $cartId;
        try {
            // Lock the local hold before looking for an existing PMS order.
            // This closes the race where two outbox deliveries both pass the
            // external-reference pre-check and insert duplicate orders.
            // Compose with a caller-owned transaction (panel/manual booking)
            // without attempting a nested PDO transaction. Direct outbox
            // delivery still owns and commits its short transaction here.
            $startedTransaction = false;
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $startedTransaction = true;
            }
            $stmt = $this->pdo->prepare("SELECT * FROM provisional_bookings WHERE cart_id = ? OR cart_id = ? FOR UPDATE");
            $stmt->execute([$cartId, $fullCartId]);
            $hold = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$hold) {
                if ($startedTransaction) {
                    $this->pdo->rollBack();
                }
                Logger::error("QloAppAdapter: No se encontró la reserva provisional local para {$cartId}");
                return null;
            }

            $existing = $this->pdo->prepare(
                'SELECT id_order, current_state FROM qlo_orders WHERE source = ? OR source = ? ORDER BY id_order DESC LIMIT 1'
            );
            $existing->execute([$fullCartId, $cartId]);
            $existingOrder = $existing->fetch(PDO::FETCH_ASSOC);
            if ($existingOrder && (int)($existingOrder['current_state'] ?? 0) === self::ORDER_STATE_PAID) {
                if ($startedTransaction) {
                    $this->pdo->commit();
                }
                return (string)$existingOrder['id_order'];
            }

            $idHotel = (int)($hold['id_hotel'] ?? Config::get('DEFAULT_HOTEL_ID', '1'));
            $idRoomType = (int)$hold['id_room_type'];
            $checkIn = (string)$hold['checkin'];
            $checkOut = (string)$hold['checkout'];

            $guestData = json_decode((string)($hold['guest_data'] ?? '{}'), true) ?? [];
            $guests = (int)($guestData['guests'] ?? 1);
            $phone = (string)($guestData['phone'] ?? Config::get('OTA_DEFAULT_PHONE', '000000000'));

            [$firstName, $lastName] = self::pmsGuestNames($guestName);

            // Canonical room lines are frozen in the hold. Expand quantity into
            // physical PMS rows so availability cannot undercount an A+A booking.
            $storedRoomLines = json_decode((string)($hold['room_data'] ?? '[]'), true);
            $sourceLines = is_array($roomLines) && $roomLines !== []
                ? $roomLines
                : (is_array($storedRoomLines) ? $storedRoomLines : []);
            if (isset($sourceLines['room_name'])) {
                $sourceLines = [$sourceLines];
            }
            if ($sourceLines === []) {
                $sourceLines = [[
                    'id_room_type' => $idRoomType,
                    'id_product' => $idRoomType,
                    'room_name' => 'Habitación USGAR',
                    'guests' => $guests,
                    'room_total' => $totalPrice,
                    'quantity' => 1,
                ]];
            }
            $canonicalLines = [];
            foreach ($sourceLines as $line) {
                if (!is_array($line)) continue;
                $quantity = max(1, (int)($line['quantity'] ?? 1));
                for ($unit = 0; $unit < $quantity; $unit++) {
                    $canonicalLines[] = [
                        'id_product' => max(1, (int)($line['id_product'] ?? $line['id_room_type'] ?? $idRoomType)),
                        'id_room_type' => max(1, (int)($line['id_room_type'] ?? $idRoomType)),
                        'room_name' => (string)($line['room_name'] ?? 'Habitación USGAR'),
                        'guests' => max(1, (int)($line['guests'] ?? $guests)),
                        'room_total' => (float)($line['room_total'] ?? 0),
                        'id_room' => isset($line['id_room']) ? (int)$line['id_room'] : null,
                    ];
                }
            }
            if ($canonicalLines === []) {
                if ($startedTransaction) {
                    $this->pdo->rollBack();
                }
                return null;
            }

            // Moneda de la orden: la pasarela cobra PEN (MERCADO_PAGO_CURRENCY).
            // La tienda QloApps opera en USD (PS_CURRENCY_DEFAULT=2) y el codigo
            // antiguo fijaba id_currency=2 creyendo que era PEN — las ordenes
            // nacian "USD 684" con montos PEN. Se resuelve la moneda PEN real
            // (id + conversion_rate de qlo_currency); el fallback conserva el
            // id 1 (PEN en la instalacion verificada).
            $penCurrencyId = 1;
            $penConversionRate = 1.0;
            // P2-3: constantes del adapter como variables interpolables
            // (los strings SQL de doble comilla no expanden self::CONST).
            $shopGroupId = self::SHOP_GROUP_ID;
            $shopId = self::SHOP_ID;
            $langId = self::LANG_ID;
            $checkinTime = self::CHECKIN_TIME;
            $checkoutTime = self::CHECKOUT_TIME;
            $orderStatePaid = self::ORDER_STATE_PAID;
            $hotelName = self::HOTEL_NAME;
            $hotelCity = self::HOTEL_CITY;
            $stmtCur = $this->pdo->prepare(
                "SELECT id_currency, conversion_rate FROM qlo_currency WHERE iso_code = 'PEN' AND active = 1 LIMIT 1"
            );
            $stmtCur->execute();
            $penCur = $stmtCur->fetch(PDO::FETCH_ASSOC);
            if ($penCur) {
                $penCurrencyId = (int)$penCur['id_currency'];
                $penConversionRate = (float)$penCur['conversion_rate'];
            }

            // 1. Cliente: buscar por email o insertar
            $stmtCust = $this->pdo->prepare("SELECT id_customer FROM qlo_customer WHERE email = ?");
            $stmtCust->execute([$guestEmail]);
            $idCustomer = (int)$stmtCust->fetchColumn();

            if ($idCustomer === 0) {
                $stmtInsCust = $this->pdo->prepare("
                    INSERT INTO qlo_customer (id_shop_group, id_shop, firstname, lastname, email, passwd, secure_key, active, is_guest, date_add, date_upd)
                    VALUES ({$shopGroupId}, {$shopId}, ?, ?, ?, ?, ?, 1, 1, NOW(), NOW())
                ");
                // passwd/secure_key de clientes guest: valores criptograficamente
                // aleatorios (md5(rand()) era un PRNG predecible para secretos
                // de acceso a ordenes de PrestaShop, CWE-338).
                // Formato 32-hex compatible con el schema de PrestaShop/QloApps.
                $guestPasswd = bin2hex(random_bytes(16));
                $guestSecureKey = bin2hex(random_bytes(16));
                $stmtInsCust->execute([$firstName, $lastName, $guestEmail, $guestPasswd, $guestSecureKey]);
                $idCustomer = (int)$this->pdo->lastInsertId();
            }

            // 2. Carrito QloApps (moneda PEN)
            $stmtCart = $this->pdo->prepare("
                INSERT INTO qlo_cart (id_shop_group, id_shop, id_lang, id_currency, id_customer, id_address_delivery, id_address_invoice, date_add, date_upd)
                VALUES ({$shopGroupId}, {$shopId}, {$langId}, {$penCurrencyId}, ?, 0, 0, NOW(), NOW())
            ");
            $stmtCart->execute([$idCustomer]);
            $idCart = (int)$this->pdo->lastInsertId();

            // 3. Referencia única de orden (9 caracteres alfanuméricos en mayúsculas)
            $ref = strtoupper(substr(bin2hex(random_bytes(5)), 0, 9));

            // 4. Orden QloApps (current_state = 2: Pago completo recibido, source = $fullCartId)
            // total_paid_real se escribe tambien: PrestaShop lo recalcula solo cuando
            // se anaden pagos desde el back-office y en una orden creada por SQL queda
            // en 0 ("Total pagado S/. 0").
            $stmtOrder = $this->pdo->prepare("
                INSERT INTO qlo_orders (
                    reference, id_shop_group, id_shop, id_carrier, id_lang, id_customer, id_cart, id_currency,
                    id_address_delivery, id_address_invoice, current_state, payment, total_paid, total_paid_real,
                    total_paid_tax_incl, total_paid_tax_excl, total_products, total_products_wt, conversion_rate, module, valid, source, date_add, date_upd
                ) VALUES (
                    ?, {$shopGroupId}, {$shopId}, 0, {$langId}, ?, ?, {$penCurrencyId},
                    0, 0, {$orderStatePaid}, ?, ?, ?,
                    ?, ?, ?, ?, {$penConversionRate}, ?, 1, ?, NOW(), NOW()
                )
            ");
            $stmtOrder->execute([$ref, $idCustomer, $idCart, $paymentLabel, $totalPrice, $totalPrice, $totalPrice, $totalPrice, $totalPrice, $totalPrice, $module, $fullCartId]);
            $idOrder = (int)$this->pdo->lastInsertId();

            // 4b. Transaccion de pago: PrestaShop calcula "Total pagado" y
            // "Monto adeudado" sumando qlo_order_payment (no orders.total_paid).
            // Sin esta fila el pedido sale "Total pagado S/. 0 / adeudado S/. <total>"
            // aunque current_state = 2 (Pago completo recibido).
            $stmtPayment = $this->pdo->prepare("
                INSERT INTO qlo_order_payment (
                    order_reference, id_currency, amount, payment_method, payment_type, conversion_rate, date_add
                ) VALUES (
                    ?, {$penCurrencyId}, ?, ?, 1, {$penConversionRate}, NOW()
                )
            ");
            $stmtPayment->execute([$ref, $totalPrice, $paymentLabel]);

            // 5-8. Persist one PMS line per requested unit. The order total is
            // expressed in the gateway currency (PEN), while room_data keeps
            // frozen line totals in the hotel currency (USD). Allocate the
            // total in integer cents so detail rows and the order header use
            // one currency and always add up exactly.
            $lineCount = count($canonicalLines);
            $totalCents = max(0, (int)round($totalPrice * 100));
            $lineWeights = array_map(
                static fn(array $line): int => max(0, (int)round((float)$line['room_total'] * 100)),
                $canonicalLines
            );
            $weightSum = array_sum($lineWeights);
            $allocatedCents = 0;

            // 4c. Asignacion fisica de habitacion heredada del hold del
            // webservice: QloApps ya asigno una habitacion concreta (id_room)
            // al booking del POST /api/bookings. Su consulta de disponibilidad
            // une booking_detail con room_information por id_room, asi que sin
            // ese id la reserva pagada no bloquearia la habitacion en el
            // calendario del PMS.
            $holdRoomsByProduct = [];
            if (ctype_digit($cartId)) {
                $stmtHoldRooms = $this->pdo->prepare(
                    "SELECT id_product, id_room FROM qlo_htl_booking_detail WHERE id_order = ? AND is_refunded = 0 ORDER BY id ASC"
                );
                $stmtHoldRooms->execute([(int)$cartId]);
                foreach ($stmtHoldRooms->fetchAll(PDO::FETCH_ASSOC) as $holdRoom) {
                    $holdRoomsByProduct[(int)$holdRoom['id_product']][] = (int)$holdRoom['id_room'];
                }
            }

            foreach ($canonicalLines as $lineIndex => $line) {
                $lineProduct = (int)$line['id_product'];
                $lineRoom = (int)($line['id_room'] ?? 0);
                if ($lineRoom === 0 && !empty($holdRoomsByProduct[$lineProduct])) {
                    $lineRoom = array_shift($holdRoomsByProduct[$lineProduct]);
                }
                $lineGuests = (int)$line['guests'];
                $isLastLine = $lineIndex === $lineCount - 1;
                if ($isLastLine) {
                    // Residual centavo(s) absorb proportional rounding so
                    // SUM(detail totals) === qlo_orders.total_paid exactly.
                    $lineCents = $totalCents - $allocatedCents;
                } elseif ($weightSum > 0) {
                    $lineCents = intdiv($totalCents * $lineWeights[$lineIndex], $weightSum);
                } else {
                    $lineCents = intdiv($totalCents, $lineCount);
                }
                $lineCents = max(0, $lineCents);
                $allocatedCents += $lineCents;
                $linePrice = $lineCents / 100;

            // 5. Nombre del producto de la habitación
            $stmtRoomName = $this->pdo->prepare("SELECT pl.name FROM qlo_product_lang pl WHERE pl.id_product = ? AND pl.id_lang = {$langId} LIMIT 1");
            $stmtRoomName->execute([$lineProduct]);
            $roomName = (string)$line['room_name'];
            if ($roomName === '') {
                $roomName = (string)($stmtRoomName->fetchColumn() ?: 'Habitación USGAR');
            }

            // 6. Detalle de orden
            $stmtDetail = $this->pdo->prepare("
                INSERT INTO qlo_order_detail (
                    id_order, id_shop, product_id, product_name, product_quantity, product_price,
                    total_price_tax_incl, total_price_tax_excl, unit_price_tax_incl, unit_price_tax_excl, is_booking_product
                ) VALUES (
                    ?, {$shopId}, ?, ?, 1, ?,
                    ?, ?, ?, ?, 1
                )
            ");
            $stmtDetail->execute([$idOrder, $lineProduct, $roomName, $linePrice, $linePrice, $linePrice, $linePrice, $linePrice]);
            $idOrderDetail = (int)$this->pdo->lastInsertId();

            // 7. Detalle de reserva en carrito (qlo_htl_cart_booking_data)
            $stmtBookingData = $this->pdo->prepare("
                INSERT INTO qlo_htl_cart_booking_data (
                    id_cart, id_guest, id_order, id_customer, id_currency, id_product, id_room, id_hotel,
                    quantity, booking_type, comment, is_back_order, extra_demands, date_from, date_to, adults, children, child_ages, date_add, date_upd
                ) VALUES (
                    ?, 0, ?, ?, {$penCurrencyId}, ?, {$lineRoom}, ?,
                    1, 1, '', 0, '[]', ?, ?, ?, 0, '[]', NOW(), NOW()
                )
            ");
            $stmtBookingData->execute([
                $idCart, $idOrder, $idCustomer, $lineProduct, $idHotel,
                $checkIn . ' 00:00:00', $checkOut . ' 00:00:00', $lineGuests
            ]);

            // 8. Detalle en qlo_htl_booking_detail para descuento inmediato de disponibilidad
            $stmtHtlDetail = $this->pdo->prepare("
                INSERT INTO qlo_htl_booking_detail (
                    id_product, id_order, id_order_detail, id_cart, id_room, id_hotel, id_customer,
                    booking_type, id_status, comment, check_in, check_out, planned_check_out, date_from, date_to,
                    total_price_tax_excl, total_price_tax_incl, total_paid_amount, is_back_order, hotel_name,
                    room_type_name, city, phone, email, adults, children, child_ages, is_refunded, is_cancelled, date_add, date_upd
                ) VALUES (
                    ?, ?, {$idOrderDetail}, ?, {$lineRoom}, ?, ?,
                    1, 1, '', ?, ?, ?, ?, ?,
                    ?, ?, ?, 0, '{$hotelName}',
                    ?, '{$hotelCity}', ?, ?, ?, 0, '[]', 0, 0, NOW(), NOW()
                )
            ");
            $stmtHtlDetail->execute([
                $lineProduct, $idOrder, $idCart, $idHotel, $idCustomer,
                $checkIn . ' ' . $checkinTime, $checkOut . ' ' . $checkoutTime, $checkOut . ' ' . $checkoutTime, $checkIn . ' ' . $checkinTime, $checkOut . ' ' . $checkoutTime,
                $linePrice, $linePrice, $linePrice, $roomName, $phone, $guestEmail, $lineGuests
            ]);
            }

            // 9. Historial de estado de orden (Estado 2 = Pago completo recibido)
            $stmtHistory = $this->pdo->prepare("
                INSERT INTO qlo_order_history (id_employee, id_order, id_order_state, date_add)
                VALUES (0, ?, {$orderStatePaid}, NOW())
            ");
            $stmtHistory->execute([$idOrder]);

            // 10. El hold del webservice (POST /api/bookings) queda superado por
            // esta orden pagada: se libera para no dejar la reserva duplicada en
            // el PMS (la habitacion ya quedo heredada en el paso 4c).
            if (ctype_digit($cartId)) {
                $this->releaseWebserviceHold((int)$cartId);
            }

            if ($startedTransaction) {
                $this->pdo->commit();
            }
            Logger::info("QloAppAdapter: Orden #{$idOrder} (Ref: {$ref}) creada exitosamente en QloApps via PDO atómico para Cart {$cartId}");
            return (string)$idOrder;

        } catch (Throwable $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Logger::error("QloAppAdapter: Excepción al crear orden QloApps via PDO para Cart {$cartId}: " . $e->getMessage());
            return null;
        }
    }


    /**
     * Libera el hold del webservice (POST /api/bookings) de un carrito.
     *
     * QloApps libera la habitacion cuando is_refunded = 1 (su consulta de
     * disponibilidad filtra is_refunded = 0) y marca la reserva como cancelada
     * con is_cancelled = 1 (semantica verificada en el codigo de QloApps
     * v1.7.0, 2026-10-20). La orden del hold pasa a "Cancelado"
     * (PS_OS_CANCELED de la tienda, leido de qlo_configuration).
     *
     * @return int reservas liberadas
     */
    private function releaseWebserviceHold(int $cartId): int {
        $shopGroupId = self::SHOP_GROUP_ID;
        $shopId = self::SHOP_ID;

        $stmtCancelHold = $this->pdo->prepare("
            UPDATE qlo_htl_booking_detail
            SET is_refunded = 1, is_cancelled = 1
            WHERE id_order = ? AND is_refunded = 0 AND is_cancelled = 0
        ");
        $stmtCancelHold->execute([$cartId]);
        $released = $stmtCancelHold->rowCount();

        // Estado de orden "Cancelado" (PS_OS_CANCELED de la tienda).
        $stmtCancelState = $this->pdo->prepare(
            "SELECT value FROM qlo_configuration WHERE name = 'PS_OS_CANCELED' AND id_shop_group = {$shopGroupId} AND id_shop = {$shopId} LIMIT 1"
        );
        $stmtCancelState->execute();
        $cancelledStateId = (int)$stmtCancelState->fetchColumn();
        if ($cancelledStateId > 0) {
            $stmtHoldOrder = $this->pdo->prepare(
                "UPDATE qlo_orders SET current_state = ? WHERE id_order = ? AND current_state = 1"
            );
            $stmtHoldOrder->execute([$cancelledStateId, $cartId]);
            if ($stmtHoldOrder->rowCount() > 0) {
                $stmtHoldHistory = $this->pdo->prepare("
                    INSERT INTO qlo_order_history (id_employee, id_order, id_order_state, date_add)
                    VALUES (0, ?, ?, NOW())
                ");
                $stmtHoldHistory->execute([$cartId, $cancelledStateId]);
            }
        } else {
            Logger::warning("QloAppAdapter: PS_OS_CANCELED no encontrado; el hold {$cartId} quedo liberado sin cambiar el estado de su orden");
        }

        return $released;
    }

    /**
     * Libera en el PMS los holds del webservice cuyas reservas locales ya no
     * estan activas (expiradas, canceladas o fallidas).
     *
     * Sin esto la habitacion queda bloqueada en el calendario de QloApps
     * hasta que su propio Cleaner purgue el carrito (verificado en produccion
     * el 2026-10-20: los holds caducados seguian con is_refunded = 0).
     * Idempotente: solo toca reservas que siguen bloqueando.
     *
     * @return int reservas liberadas
     */
    public function releaseStaleWebserviceHolds(): int {
        if (!$this->pdo) {
            return 0;
        }
        try {
            $dead = "'" . implode("','", [
                BookingStatus::Expired->value,
                BookingStatus::Cancelled->value,
                BookingStatus::Failed->value,
            ]) . "'";
            $stmt = $this->pdo->prepare("
                SELECT DISTINCT pb.cart_id
                FROM provisional_bookings pb
                INNER JOIN qlo_htl_booking_detail bd
                    ON bd.id_order = CAST(pb.cart_id AS UNSIGNED)
                   AND bd.is_refunded = 0 AND bd.is_cancelled = 0 AND bd.is_back_order = 0
                WHERE pb.status IN ({$dead})
                  AND pb.cart_id REGEXP '^[0-9]+$'
            ");
            $stmt->execute();

            $released = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cartId) {
                $released += $this->releaseWebserviceHold((int)$cartId);
            }
            if ($released > 0) {
                Logger::info("QloAppAdapter: Se liberaron {$released} reservas del PMS de reservas locales caducas.");
            }
            return $released;
        } catch (PDOException $e) {
            Logger::error('QloAppAdapter: Error al liberar los holds caducados: ' . $e->getMessage());
            return 0;
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

        if ($this->pdo) {
            try {
                $stmt = $this->pdo->prepare("
                    SELECT EXISTS(
                        SELECT 1
                        FROM qlo_orders
                        WHERE current_state = 2
                          AND (source = ? OR source = ?)
                    )
                ");
                $stmt->execute([$externalReference, $fullCartId]);
                return (int)$stmt->fetchColumn() === 1;

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

        if ($httpCode >= 400 || !is_string($response)) {
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
