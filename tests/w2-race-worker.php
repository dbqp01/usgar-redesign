<?php
declare(strict_types=1);

/**
 * Worker CLI de carrera (Wave 2, todos 8 y 10).
 *
 * Modo payment-race: ejecuta ProcessPaymentAction completo con un FakeGateway
 * (doble del PORT propio, no mock de MP) contra la BD real configurada en
 * .env. Dos procesos lanzados a la vez sobre el MISMO cart demuestran la
 * serializacion por lock pesimista: exactamente 1 llamada a la gateway.
 *
 * Modo hold-race: ejecuta el flujo de creacion de hold a nivel repositorio
 * (lockRoom -> getHoldCountForRoomForUpdate -> create) para la serializacion
 * de holds fantasma (todo 10).
 *
 * Uso:
 *   php tests/w2-race-worker.php payment-race <cartId> <accessToken> <scenario> <gatewayLog>
 *   php tests/w2-race-worker.php hold-race <cartId> <roomType> <checkIn> <checkOut> <hotelId> <roomLockId>
 *
 * Salida: "OK:<json>" o "ERR:<mensaje>" en stdout. Nunca imprime secretos.
 */

require_once __DIR__ . '/../vendor/autoload.php';

App\Core\Config::boot();

require_once __DIR__ . '/fixtures/W2TestDoubles.php';

use App\Core\Config;
use App\Core\Request;
use App\Core\BookingStatus;
use App\Features\Booking\Actions\ProcessPaymentAction;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Test\Fixtures\FakeGateway;
use App\Test\Fixtures\NoopEventDispatcher;
use App\Test\Fixtures\TestDb;

// Entorno deterministico de test (nunca toca credenciales reales de MP:
// el FakeGateway no usa el access token).
Config::set('EXCHANGE_RATE_USD_PEN', '3.80');
Config::set('MERCADO_PAGO_CURRENCY', 'PEN');
Config::set('BOOKING_TOKEN_SECRET', 'w2-race-test-secret');

$mode = $argv[1] ?? '';

$pdo = TestDb::connect();
if ($pdo === null) {
    echo 'ERR:no-db';
    exit(2);
}

try {
    if ($mode === 'payment-race') {
        [, , $cartId, $accessToken, $scenario, $gatewayLog] = $argv;

        $gateway = new FakeGateway($gatewayLog, $scenario);
        $repo = new ProvisionalBookingRepository($pdo);

        $action = new ProcessPaymentAction(
            $pdo,
            $gateway,
            $repo,
            new NoopEventDispatcher()
        );

        $request = new Request('POST', '/api/process-payment', [], [
            'cart_id'      => $cartId,
            'access_token' => $accessToken,
            'payment_data' => [
                'token'             => 'FAKE_TOKEN',
                'issuer_id'         => '310',
                'payment_method_id' => 'visa',
                'installments'      => 1,
                'payer'             => [
                    'email'          => 'race@test.com',
                    'identification' => ['type' => 'DNI', 'number' => '12345678'],
                ],
            ],
        ]);

        ob_start();
        $action($request);
        $body = (string) ob_get_clean();
        echo 'OK:' . $body;
        exit(0);
    }

    if ($mode === 'hold-race') {
        [, , $cartId, $roomType, $checkIn, $checkOut, $hotelId, $roomLockId] = $argv;

        $repo = new ProvisionalBookingRepository($pdo);

        $pdo->beginTransaction();
        if (!$repo->lockRoom($roomLockId)) {
            $pdo->rollBack();
            echo 'ERR:lock-room';
            exit(1);
        }
        $count = $repo->getHoldCountForRoomForUpdate((int)$roomType, $checkIn, $checkOut, (int)$hotelId);
        if ($count > 0) {
            $pdo->rollBack();
            echo 'ERR:room-unavailable';
            exit(1);
        }
        $ok = $repo->create([
            'cart_id'       => $cartId,
            'user_id'       => null,
            'id_hotel'      => (int)$hotelId,
            'id_room_type'  => (int)$roomType,
            'guest_data'    => ['name' => 'Race Guest', 'email' => 'race@test.com'],
            'room_data'     => ['room_name' => 'Race Room', 'price_per_night' => 100.0, 'nights' => 2],
            'price_snapshot' => 200.0,
            'checkin'       => $checkIn,
            'checkout'      => $checkOut,
            'status'        => BookingStatus::Pending->value,
            'expires_at'    => date('Y-m-d H:i:s', time() + 1800),
        ]);
        if (!$ok) {
            $pdo->rollBack();
            echo 'ERR:insert-hold';
            exit(1);
        }
        // expires_at por SQL (mismo servidor) para evitar skew de timezone
        // entre el PHP local y NOW() de MariaDB.
        $pdo->prepare("UPDATE provisional_bookings SET expires_at = NOW() + INTERVAL 30 MINUTE WHERE cart_id = :cart")
            ->execute([':cart' => $cartId]);
        $pdo->commit();
        echo 'OK:hold-created';
        exit(0);
    }

    echo 'ERR:unknown-mode';
    exit(2);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $ignore) {
        }
    }
    echo 'ERR:' . $e->getMessage();
    exit(1);
}
