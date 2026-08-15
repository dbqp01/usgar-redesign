<?php
declare(strict_types=1);

namespace App\Test\Fixtures;

require_once __DIR__ . '/W2TestDoubles.php';
require_once __DIR__ . '/W3WebhookFixtures.php';

use App\Core\Config;
use App\Core\Request;
use App\Core\BookingHoldToken;
use App\Core\BookingStatus;
use App\Core\Events\EventDispatcher;
use App\Core\Events\EventInterface;
use App\Features\Booking\Actions\ProcessPaymentAction;
use App\Features\Booking\Domain\Events\BookingPaidEvent;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Adapters\MercadoPagoAdapter;
use App\Features\Webhooks\Actions\HandleMercadoPagoWebhookAction;
use PDO;
use Throwable;

/**
 * Fixtures del F3 (Real QA E2E contra el SANDBOX REAL de MercadoPago — sin
 * mocks). Escenarios a-k del plan usgar-payment-hardening (Final verification
 * wave, F3).
 *
 * MANDATO r10: NO se simula ningun resultado de MP — los pagos se crean de
 * verdad con las tarjetas de prueba MPE (titular APRO/OTHE/CONT/FUND/SECU),
 * los webhooks se firman con HMAC-SHA256 REAL (secret real del repo, mismo
 * algoritmo del SDK WebhookSignatureValidator) y las firmas se validan con el
 * adapter real.
 *
 * REGLA DE SECRETOS: los valores reales del .env (token, webhook secret,
 * DB_PASS...) se leen aqui SOLO para restaurarlos en Config tras la
 * contaminacion de los tests unitarios (Config::set de las waves anteriores);
 * NUNCA se imprimen ni se escriben en el log de evidencia.
 */
final class F3RealSandboxFixtures {

    /** Log de evidencia: cada escenario append lineas con salidas reales. */
    public const EVIDENCE_LOG = __DIR__ . '/../f3-evidence.log';

    /** Tarjeta Visa MPE de prueba (doc MP test-cards): cvv 123, exp 11/30. */
    public const MPE_VISA = '4009175332806176';

    /**
     * Payments REALES existentes en el sandbox de la app "usgar test"
     * (8501374849722569), creados por corridas previas (2026-08-03/05) y
     * verificados legibles con el token del .env (getPaymentDetails). El
     * sandbox de HOY no permite crear payments nuevos (Card Token not found /
     * Invalid users involved / Payer email forbidden — verificado exhaustivo
     * con SDK + API cruda + tests/mp-test-payment.php), asi que los escenarios
     * E2E reales de webhook se ejercen sobre estos payments REALES.
     */
    public const PAY_APPROVED_WITH_REF = '1349988353'; // approved 150.50 PEN, ref=REF-1785973443
    public const PAY_APPROVED_NO_REF   = '1327783012'; // approved 50 PEN, ref=null (orphan)
    public const PAY_REJECTED_1        = '1327783824'; // rejected cc_rejected_other_reason, ref=USGAR-7c9c76f1bd16
    public const PAY_REJECTED_2        = '1349906085'; // rejected cc_rejected_other_reason, ref=USGAR-5e0a424f1076
    public const PAY_PENDING_PE        = '1349900853'; // pending pagoefectivo_atm 50 PEN, ref=null

    private static array $createdPaymentIds = [];
    private static array $fixtureCarts = [];

    // ---------------------------------------------------------- env real

    /**
     * Lee un valor REAL del .env directamente (sin pasar por Config, que
     * puede estar contaminado por Config::set de otros tests).
     */
    public static function envValue(string $key): ?string {
        $lines = file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return null;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) === 2 && trim($parts[0]) === $key) {
                return trim($parts[1]);
            }
        }
        return null;
    }

    /**
     * Restaura en Config los valores REALES del .env. Los tests unitarios de
     * las waves 1-6 contaminan el singleton de Config con tokens/secrets fake
     * ('TEST-123456789', 'test-webhook-secret', 'test-booking-token-secret'...)
     * y la suite E2E real NECESITA el entorno real. Nunca imprime valores.
     */
    public static function restoreRealConfig(): void {
        foreach ([
            'MERCADO_PAGO_ACCESS_TOKEN',
            'MERCADO_PAGO_WEBHOOK_SECRET',
            'BOOKING_TOKEN_SECRET',
            'CRON_SECRET',
            'EXCHANGE_RATE_USD_PEN',
            'MP_BINARY_MODE',
            'MERCADO_PAGO_CURRENCY',
            'MP_STATEMENT_DESCRIPTOR',
            'DB_HOST',
            'DB_PORT',
            'DB_NAME',
            'DB_USER',
            'DB_PASS',
        ] as $key) {
            $value = self::envValue($key);
            if ($value !== null) {
                Config::set($key, $value);
            }
        }
        // Los webhooks de prueba con payload oficial + firma real no deben
        // invocar exit() (Response::json) dentro de la suite.
    }

    /** Conexion PDO a la BD real configurada en .env. */
    public static function connect(): ?PDO {
        return TestDb::connect();
    }

    // ------------------------------------------------------------- holds

    public static function newCartId(string $scenario): string {
        return 'F3-' . $scenario . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    /**
     * Crea un hold aislado F3-* directamente en la BD real.
     *
     * @return array<string, mixed> hold hidratado
     */
    public static function createHold(PDO $pdo, string $cartId, array $overrides = []): array {
        $repo = new ProvisionalBookingRepository($pdo);
        $defaults = [
            'cart_id'                 => $cartId,
            'user_id'                 => null,
            'id_hotel'                => 1,
            'id_room_type'            => 3,
            'guest_data'              => [
                'name'   => 'F3 Guest',
                // Doc MP test users: el e-mail de compradores de prueba debe
                // seguir test_payer_[0-9]{1,10}@testuser.com (MP rechaza otros
                // formatos: "payer.email must be a valid email").
                'email'  => 'test_payer_4@testuser.com',
                'phone'  => '+51999999999',
                'guests' => 2,
            ],
            'room_data'               => [
                'room_name'       => 'F3 Room',
                'price_per_night' => 75.0,
                'nights'          => 2,
            ],
            'price_snapshot'          => 150.0,
            'price_snapshot_pen'      => 281.25,   // PEN congelado al cotizar (todo 25/32)
            'exchange_rate_snapshot'  => 3.75,
            'checkin'                 => date('Y-m-d', strtotime('+30 days')),
            'checkout'                => date('Y-m-d', strtotime('+32 days')),
            'status'                  => BookingStatus::Pending->value,
            'expires_at'              => date('Y-m-d H:i:s', time() + 3600),
        ];
        $data = array_merge($defaults, $overrides);
        if (!$repo->create($data)) {
            throw new \RuntimeException('F3: no se pudo crear el hold ' . $cartId);
        }
        $hold = $repo->getByCartId($cartId);
        if ($hold === null) {
            throw new \RuntimeException('F3: hold ' . $cartId . ' no encontrado tras el insert');
        }
        return $hold;
    }

    // ------------------------------------------------- tarjeta de prueba

    /**
     * Tokeniza una tarjeta de prueba MPE (doc MP test-cards). El NOMBRE DEL
     * TITULAR decide el resultado: APRO/OTHE/CONT/FUND/SECU...
     */
    public static function cardToken(string $holderName, string $cardNumber = self::MPE_VISA): string {
        \MercadoPago\MercadoPagoConfig::setAccessToken((string)Config::get('MERCADO_PAGO_ACCESS_TOKEN'));
        $client = new \MercadoPago\Client\CardToken\CardTokenClient();
        $token = $client->create([
            'card_number'      => $cardNumber,
            'expiration_month' => 11,
            'expiration_year'  => 2030,
            'security_code'    => '123',
            'cardholder'       => [
                'name'           => $holderName,
                'identification' => [
                    'type'   => 'DNI',
                    'number' => '123456789',
                ],
            ],
        ]);
        return (string)$token->id;
    }

    // ----------------------------------------------- firma HMAC REAL

    /**
     * Header x-signature HMAC-SHA256 REAL con el secret REAL del repo.
     * Mismo algoritmo que el SDK WebhookSignatureValidator (W3WebhookFixtures):
     *   manifest = "id:{dataId};request-id:{requestId};ts:{tsMs};"
     * ts en MILISEGUNDOS (doc MP webhooks x-signature).
     */
    public static function signatureHeader(string $dataId, string $requestId): string {
        return W3WebhookFixtures::signatureHeader(
            $dataId,
            $requestId,
            null,
            (string)Config::get('MERCADO_PAGO_WEBHOOK_SECRET')
        );
    }

    // --------------------------------------------------- webhook real

    /**
     * Entrega un webhook FIRMADO REAL al handler real (adapter real + firma
     * real + fetch real de MP) dentro de la suite. Devuelve code/body/ms.
     *
     * @return array{code:int, body:string, elapsed_ms:float}
     */
    public static function deliverWebhook(PDO $pdo, string $paymentId, string $requestId): array {
        $adapter = new MercadoPagoAdapter();
        $repo = new ProvisionalBookingRepository($pdo);
        $dispatcher = new F3OutboxEventDispatcher($pdo);
        $action = new HandleMercadoPagoWebhookAction($pdo, $adapter, $repo, $dispatcher);

        $body = ['type' => 'payment', 'data' => ['id' => $paymentId]];
        $headers = [
            'x-signature'   => self::signatureHeader($paymentId, $requestId),
            'x-request-id'  => $requestId,
        ];
        $_GET['data_id'] = $paymentId; // MP envia ?data.id=... en el push real
        try {
            $request = new Request('POST', '/api/webhook', $headers, $body);
            ob_start();
            $t0 = microtime(true);
            $action($request);
            $elapsedMs = (microtime(true) - $t0) * 1000;
            $out = (string)ob_get_clean();
            return ['code' => http_response_code(), 'body' => $out, 'elapsed_ms' => $elapsedMs];
        } finally {
            unset($_GET['data_id']);
        }
    }

    // ------------------------------------------- process-payment real

    /**
     * Ejecuta ProcessPaymentAction real (lock + gateway real + txn real).
     *
     * @param array<string, mixed> $paymentData payment_data del cliente (token, method, payer...)
     * @param array<string, mixed> $hold
     * @return array{code:int, body:string, elapsed_ms:float}
     */
    public static function processPayment(PDO $pdo, string $cartId, array $paymentData, array $hold): array {
        $repo = new ProvisionalBookingRepository($pdo);
        $adapter = new MercadoPagoAdapter();
        $dispatcher = new F3OutboxEventDispatcher($pdo);
        $action = new ProcessPaymentAction($pdo, $adapter, $repo, $dispatcher);

        $email = (string)($hold['guest_data']['email'] ?? '');
        $accessToken = BookingHoldToken::derive($cartId, $email);

        $request = new Request('POST', '/api/process-payment', [], [
            'cart_id'      => $cartId,
            'access_token' => $accessToken,
            'payment_data' => $paymentData,
        ]);
        ob_start();
        $t0 = microtime(true);
        $action($request);
        $elapsedMs = (microtime(true) - $t0) * 1000;
        $out = (string)ob_get_clean();
        return ['code' => http_response_code(), 'body' => $out, 'elapsed_ms' => $elapsedMs];
    }

    // ----------------------------------- pago real directo (adapter)

    /**
     * Crea un pago REAL directo via MercadoPagoAdapter (sin hold): para los
     * escenarios orphan (i), concurrencia (f) y fraude (j). Registra el
     * payment id para la limpieza posterior.
     */
    public static function directPayment(string $externalRef, float $amount, string $holder = 'APRO'): array {
        $token = self::cardToken($holder);
        $adapter = new MercadoPagoAdapter();
        $result = $adapter->processPayment([
            'external_reference' => $externalRef,
            'transaction_amount' => $amount,
            'payment_method_id'  => 'visa',
            'token'              => $token,
            'installments'       => 1,
            'payer'              => [
                'email'          => 'test_payer_2@testuser.com',
                'identification' => ['type' => 'DNI', 'number' => '123456789'],
            ],
        ]);
        if ($result === null || !isset($result['id'])) {
            throw new \RuntimeException('F3: pago directo no creado para ' . $externalRef);
        }
        self::$createdPaymentIds[] = (string)$result['id'];
        return $result;
    }

    /** Guarda un payment id real para la limpieza posterior. */
    public static function trackPaymentId(string $paymentId): void {
        self::$createdPaymentIds[] = $paymentId;
    }

    /** Registra un cart de fixture (hold creado por la suite) para la limpieza. */
    public static function trackFixtureCart(string $cartId): void {
        self::$fixtureCarts[] = $cartId;
    }

    /**
     * Verifica que un payment real del sandbox existe y es legible con el
     * token del .env, devolviendo sus detalles normalizados (adapter real).
     */
    public static function probeRealPayment(string $paymentId): ?array {
        $adapter = new MercadoPagoAdapter();
        return $adapter->getPaymentDetails($paymentId);
    }

    // ------------------------------------------------------------- DB

    public static function fetchHold(PDO $pdo, string $cartId): ?array {
        return (new ProvisionalBookingRepository($pdo))->getByCartId($cartId);
    }

    public static function countProcessed(PDO $pdo, string $paymentId, ?string $eventType = null): int {
        $sql = 'SELECT COUNT(*) FROM processed_payments WHERE payment_id = :p'
            . ($eventType !== null ? ' AND event_type = :e' : '');
        $stmt = $pdo->prepare($sql);
        $params = [':p' => $paymentId];
        if ($eventType !== null) {
            $params[':e'] = $eventType;
        }
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public static function countOutboxByCart(PDO $pdo, string $cartId): int {
        return count(self::outboxIdsForCart($pdo, $cartId));
    }

    /**
     * Ids de filas del outbox cuyo evento (payload base64(serialize)) pertenece
     * al cart indicado. NO se usa LIKE sobre el payload: el cart id contiene
     * guiones que la codificacion base64 no preserva.
     *
     * @return int[]
     */
    private static function outboxIdsForCart(PDO $pdo, string $cartId, bool $prefix = false): array {
        $ids = [];
        $rows = $pdo->query('SELECT id, payload FROM event_outbox')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            try {
                $event = unserialize(base64_decode((string)$row['payload']));
                if (!$event instanceof EventInterface) {
                    continue;
                }
                $eventCart = (string)$event->getCartId();
                if ($prefix ? str_starts_with($eventCart, $cartId) : $eventCart === $cartId) {
                    $ids[] = (int)$row['id'];
                }
            } catch (Throwable $e) {
                continue;
            }
        }
        return $ids;
    }

    /**
     * Limpieza estricta de los fixtures F3-* (deja la BD como estaba):
     * holds (F3-% + carts de fixture), processed_payments (cart F3-% + payment
     * ids reales procesados por la suite), alertas, outbox (por evento
     * decodificado).
     */
    public static function cleanup(PDO $pdo): void {
        $pdo->exec("DELETE FROM provisional_bookings WHERE cart_id LIKE 'F3-%'");
        $pdo->exec("DELETE FROM processed_payments WHERE cart_id LIKE 'F3-%'");
        $pdo->exec("DELETE FROM payment_alerts WHERE cart_id LIKE 'F3-%'");

        // Payments reales conocidos del sandbox + carts de fixture: la suite
        // debe ser re-ejecutable (idempotente) incluso si una corrida anterior
        // quedo a medias.
        foreach ([self::PAY_APPROVED_WITH_REF, self::PAY_APPROVED_NO_REF, self::PAY_REJECTED_1, self::PAY_REJECTED_2, self::PAY_PENDING_PE] as $pid) {
            $pdo->prepare('DELETE FROM processed_payments WHERE payment_id = :p')->execute([':p' => $pid]);
        }
        $fixtureCarts = array_unique(array_merge(self::$fixtureCarts, ['REF-1785973443']));
        foreach ($fixtureCarts as $cartId) {
            $pdo->prepare('DELETE FROM provisional_bookings WHERE cart_id = :c')->execute([':c' => $cartId]);
            $pdo->prepare('DELETE FROM processed_payments WHERE cart_id = :c')->execute([':c' => $cartId]);
            $pdo->prepare('DELETE FROM payment_alerts WHERE cart_id = :c')->execute([':c' => $cartId]);
        }
        self::$fixtureCarts = [];

        $outboxIds = self::outboxIdsForCart($pdo, 'F3-', true);
        $outboxIds = array_merge($outboxIds, self::outboxIdsForCart($pdo, 'REF-1785973443'));
        foreach (array_unique($outboxIds) as $id) {
            $pdo->prepare('DELETE FROM event_outbox WHERE id = :id')->execute([':id' => $id]);
        }

        foreach (self::$createdPaymentIds as $pid) {
            $pdo->prepare('DELETE FROM processed_payments WHERE payment_id = :p')->execute([':p' => $pid]);
        }
        self::$createdPaymentIds = [];
    }

    // -------------------------------------------------------- evidencia

    public static function evidence(string $line): void {
        $stamp = date('Y-m-d H:i:s');
        file_put_contents(self::EVIDENCE_LOG, "[{$stamp}] {$line}" . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function clearEvidenceLog(): void {
        if (file_exists(self::EVIDENCE_LOG)) {
            unlink(self::EVIDENCE_LOG);
        }
    }

    /** Captura la respuesta de una accion ADR (mismo patron que los tests W3). */
    public static function captureResponse(callable $fn): array {
        ob_start();
        $fn();
        $body = (string)ob_get_clean();
        return ['code' => http_response_code(), 'body' => $body];
    }
}

/**
 * Dispatcher de la suite F3: escribe el outbox transaccional con la conexion
 * FRESCA de la suite (el MISMO INSERT que EventDispatcher::dispatch, mismo SQL
 * y schema reales). En produccion cada webhook es un proceso PHP NUEVO con
 * conexion fresca; en el proceso phpunit de la corrida completa el singleton
 * de Database queda stale tras ~150 tests ("MySQL server has gone away") y
 * no se puede reemplazar. Este doble usa la conexion del escenario, que es la
 * misma BD real.
 */
final class F3OutboxEventDispatcher extends EventDispatcher {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function dispatch(EventInterface $event): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO event_outbox (event_name, payload, status, attempts, next_attempt_at, created_at)
             VALUES (:event_name, :payload, 'PENDING', 0, NOW(), NOW())"
        );
        $stmt->execute([
            ':event_name' => $event->getName(),
            ':payload'    => base64_encode(serialize($event)),
        ]);
    }
}
