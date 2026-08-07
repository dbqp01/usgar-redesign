<?php
declare(strict_types=1);

namespace App\Test\Fixtures;

use App\Core\Events\EventInterface;
use App\Core\Events\ListenerInterface;
use App\Features\Shared\Ports\PmsPortInterface;
use DateTimeImmutable;
use Exception;
use PDO;

/**
 * Test doubles in-memory para la Wave 4 (C4 Outbox + sync PMS).
 *
 * MANDATO r10: los test doubles SOLO cubren puertos propios del proyecto
 * (PmsPortInterface) — sin red, nunca simulan resultados de la API real de
 * MercadoPago. Los listeners del outbox se ejercitan contra estos dobles que
 * CUENTAN llamadas (dedup) y permiten inyectar fallos (null de confirmOrder,
 * PMS caido).
 */

/**
 * Evento de prueba serializable (mismo contrato que EventInterface).
 * El cart_id actua como marcador aislado (prefijo W4TEST-) para limpieza
 * estricta de filas de event_outbox en la BD real.
 */
final class W4TestEvent implements EventInterface {
    private string $cartId;
    private string $name;

    public function __construct(string $cartId, string $name = 'booking.paid') {
        $this->cartId = $cartId;
        $this->name = $name;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getCartId(): string {
        return $this->cartId;
    }

    public function getPayload(): array {
        return ['cart_id' => $this->cartId, 'event' => $this->name];
    }

    public function getOccurredAt(): DateTimeImmutable {
        return new DateTimeImmutable();
    }
}

/**
 * Listener no-op: permite que EventDispatcher::dispatch tome la ruta del
 * outbox en tests (sin listeners registrados, dispatch retorna antes del
 * INSERT). Nunca se ejecuta: dispatch inserta en el outbox y retorna.
 */
final class W4NoopListener implements ListenerInterface {
    public function handle(EventInterface $event): void {
        // no-op deliberado
    }
}

/**
 * Doble del puerto PMS (QloApps) para tests de listeners.
 * Cuenta llamadas a confirmOrder / isOrderConfirmed y permite inyectar:
 *  - confirmResult = null  -> confirmOrder falla (listener debe lanzar)
 *  - dedupResult = true    -> la orden YA esta confirmada (dedup-skip)
 *  - dedupThrows = true    -> PMS caido en el pre-chequeo (fail-closed)
 */
final class W4PmsPortDouble implements PmsPortInterface {
    public int $confirmOrderCalls = 0;
    public int $dedupCalls = 0;
    public bool $dedupResult = false;
    public bool $dedupThrows = false;
    public mixed $confirmResult = null;
    /** @var array<int, array{0:string,1:float,2:string,3:string}> */
    public array $confirmArgs = [];

    public function getAvailableRooms(string $checkIn, string $checkOut, int $idHotel = 1): array {
        return [];
    }

    public function getAvailabilityCalendar(string $from, string $to, int $idHotel = 1): array {
        return [];
    }

    public function createCart(int $idHotel, int $idProduct, string $checkIn, string $checkOut, int $guests = 1, float $totalPrice = 0, string $guestName = '', string $guestEmail = '', string $guestPhone = ''): string {
        return 'W4CART';
    }

    public function extendCartSession(string $cartId): bool {
        return true;
    }

    public function confirmOrder(string $cartId, float $totalPrice, string $guestName, string $guestEmail): ?string {
        $this->confirmOrderCalls++;
        $this->confirmArgs[] = [$cartId, $totalPrice, $guestName, $guestEmail];
        return $this->confirmResult;
    }

    public function isOrderConfirmed(string $externalReference): bool {
        $this->dedupCalls++;
        if ($this->dedupThrows) {
            throw new Exception('PMS caido durante isOrderConfirmed (fail-closed).');
        }
        return $this->dedupResult;
    }
}

/**
 * Utilidades de BD para la Wave 4 (patron TestDb de la Wave 2, ampliado).
 * Filas aisladas con prefijo W4TEST-* + limpieza estricta setUp/tearDown.
 *
 * Los ids insertados se registran en memoria: la limpieza borra POR ID
 * (el marker del payload en base64 NO es fiable para LIKE — base64 puede
 * partir la subcadena en un limite de 3 bytes). El LIKE se conserva solo
 * como barrido best-effort para runs abortados de sesiones previas.
 */
final class W4Db {
    /** @var array<int, int> */
    private static array $insertedIds = [];

    /**
     * Inserta un evento de prueba en event_outbox.
     *
     * @param string|null $nextAtSql Expresion SQL interna (NOW(), NULL,
     *                               'NOW() - INTERVAL 1 MINUTE', ...) — nunca
     *                               input de usuario.
     */
    public static function insertOutboxEvent(
        PDO $pdo,
        string $cartId,
        string $status = 'PENDING',
        int $attempts = 0,
        ?string $nextAtSql = null
    ): int {
        $nextAtSql = $nextAtSql ?? 'NOW()';
        $event = new W4TestEvent($cartId);
        $payload = base64_encode(serialize($event));
        $stmt = $pdo->prepare(
            "INSERT INTO event_outbox (event_name, payload, status, attempts, next_attempt_at, created_at)
             VALUES (:name, :payload, :status, :attempts, {$nextAtSql}, NOW())"
        );
        $stmt->execute([
            ':name'     => $event->getName(),
            ':payload'  => $payload,
            ':status'   => $status,
            ':attempts' => $attempts,
        ]);
        $id = (int)$pdo->lastInsertId();
        self::$insertedIds[$id] = $id;
        return $id;
    }

    /** Limpia TODAS las filas de prueba W4TEST-* dejadas por tests. */
    public static function cleanup(PDO $pdo): void {
        if (!empty(self::$insertedIds)) {
            $ids = implode(',', self::$insertedIds);
            $pdo->exec("DELETE FROM event_outbox WHERE id IN ({$ids})");
        }
        self::$insertedIds = [];
        // Barrido best-effort para filas de runs abortados (otro proceso).
        $pdo->exec("DELETE FROM event_outbox WHERE payload LIKE '%W4TEST-%'");
        $pdo->exec("DELETE FROM provisional_bookings WHERE cart_id LIKE 'W4TEST-%'");
        $pdo->exec("DELETE FROM processed_payments WHERE cart_id LIKE 'W4TEST-%'");
        $pdo->exec("DELETE FROM payment_alerts WHERE cart_id LIKE 'W4TEST-%'");
    }
}
