<?php
declare(strict_types=1);

// Recuperación de la reserva huérfana USGAR-d5fab1ddb3b0 (2026-08-15).
// El pago se aprobó (hold paid) pero el evento booking.paid NUNCA llegó al
// outbox (bug de wiring del EventDispatcher, fix dcb2c5c). Este script
// reconstruye el evento con los datos REALES del hold y lo inserta en
// event_outbox con el MISMO formato que EventDispatcher::dispatch — el cron
// process_outbox (5 min) lo entregará a QloApps (confirmOrder) y al email.
//
// Uso: php scripts/recover-orphan-booking.php <cart_id>
// Idempotente: no inserta si el evento ya está en el outbox (por cart_id
// deserializado) ni si la orden ya existe en QloApps (dedup del listener).

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Features\Booking\Domain\Events\BookingPaidEvent;
use PDO;

$cartId = $argv[1] ?? '';
if ($cartId === '') {
    fwrite(STDERR, "Uso: php scripts/recover-orphan-booking.php <cart_id>\n");
    exit(2);
}

$pdo = Database::getInstance()->getConnection();
if ($pdo === null) {
    fwrite(STDERR, "BD no conectada\n");
    exit(1);
}

// 1. Leer el hold (datos congelados: guest, room, PEN, tasas).
$stmt = $pdo->prepare("SELECT * FROM provisional_bookings WHERE cart_id = :c");
$stmt->execute([':c' => $cartId]);
$hold = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$hold) {
    fwrite(STDERR, "Hold no encontrado: {$cartId}\n");
    exit(1);
}
echo "Hold: status={$hold['status']} payment_id={$hold['payment_id']} " .
    "pen={$hold['price_snapshot_pen']} created={$hold['created_at']}\n";

if ($hold['status'] !== 'paid' || empty($hold['payment_id'])) {
    fwrite(STDERR, "ERROR: el hold no está paid o no tiene payment_id — no se recupera.\n");
    exit(1);
}

// 2. Dedup por payload: deserializar filas del outbox y buscar el cart_id.
$dup = 0;
foreach ($pdo->query("SELECT payload FROM event_outbox WHERE event_name = 'booking.paid' ORDER BY id DESC LIMIT 50") as $row) {
    $ev = @unserialize(base64_decode((string)$row['payload']), ['allowed_classes' => [BookingPaidEvent::class, DateTimeImmutable::class]]);
    if ($ev instanceof BookingPaidEvent && $ev->getCartId() === $cartId) {
        $dup++;
    }
}
if ($dup > 0) {
    echo "Ya existe {$dup} evento(s) para {$cartId} en el outbox — sin insertar (idempotente).\n";
    exit(0);
}

// 2.5 Normalizar JSON (el repo real las decodifica en getByCartIdForUpdate;
// el SELECT directo las trae como string).
$hold['guest_data'] = json_decode((string)($hold['guest_data'] ?? '[]'), true) ?: [];
$hold['room_data']  = json_decode((string)($hold['room_data'] ?? '[]'), true) ?: [];

// 3. Construir el evento con los datos del hold (mismo camino que el código).
$event = BookingPaidEvent::fromHold($cartId, (string)$hold['payment_id'], $hold);
$payload = base64_encode(serialize($event));

// 3.1 Roundtrip check: el cron deserializa con allowed_classes
// [BookingPaidEvent, DateTimeImmutable]; si esto no vuelve a ser un evento
// válido, NO insertar (evita basura en el outbox).
$check = @unserialize(base64_decode($payload), ['allowed_classes' => [BookingPaidEvent::class, DateTimeImmutable::class]]);
if (!$check instanceof BookingPaidEvent || $check->getCartId() !== $cartId) {
    fwrite(STDERR, "ERROR: roundtrip del payload falló — no se inserta.\n");
    exit(1);
}
echo "Roundtrip OK: evento válido para {$cartId} (amount_pen={$check->getAmountPen()}).\n";

// 4. INSERT con el formato exacto de EventDispatcher::dispatch.
$ins = $pdo->prepare(
    "INSERT INTO event_outbox (event_name, payload, status, attempts, next_attempt_at, created_at)
     VALUES ('booking.paid', :payload, 'PENDING', 0, NOW(), NOW())"
);
$ins->execute([':payload' => $payload]);
$id = (int)$pdo->lastInsertId();
echo "Evento booking.paid insertado en outbox (id={$id}, cart={$cartId}). El cron process_outbox lo entrega en <=5 min.\n";
exit(0);
