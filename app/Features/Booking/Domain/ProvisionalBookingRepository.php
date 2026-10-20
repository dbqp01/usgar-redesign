<?php
declare(strict_types=1);

namespace App\Features\Booking\Domain;

use PDO;
use PDOException;
use Throwable;
use App\Core\Logger;
use App\Core\Database;
use App\Core\Config;
use App\Core\BookingStatus;

/**
 * Repositorio de reservas provisionales (Holds temporales de 15 minutos).
 */
class ProvisionalBookingRepository {
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): bool {
        try {
            return $this->doInsert($data);
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::create Error: ' . $e->getMessage());
            if ($this->pdo && (str_contains($e->getMessage(), '1146') || str_contains($e->getMessage(), '42S02') || str_contains($e->getMessage(), "doesn't exist"))) {
                Logger::info('ProvisionalBookingRepository: Creando tablas provisional_bookings y processed_payments automáticamente...');
                $this->ensureTablesExist();
                try {
                    return $this->doInsert($data);
                } catch (PDOException $ex) {
                    Logger::error('ProvisionalBookingRepository::create Retry Error: ' . $ex->getMessage());
                    return false;
                }
            }
            return false;
        }
    }

    /**
     * Ejecuta el INSERT de un hold con sus valores normalizados.
     *
     * @param array<string, mixed> $data
     */
    private function doInsert(array $data): bool {
        $startedTransaction = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $startedTransaction = true;
        }
        try {
        $stmt = $this->pdo->prepare("
            INSERT INTO provisional_bookings (
                cart_id, user_id, id_hotel, id_room_type, guest_data, room_data,
                price_snapshot, price_snapshot_pen, exchange_rate_snapshot,
                checkin, checkout, status, expires_at
            ) VALUES (
                :cart_id, :user_id, :id_hotel, :id_room_type, :guest_data, :room_data,
                :price_snapshot, :price_snapshot_pen, :exchange_rate_snapshot,
                :checkin, :checkout, :status, :expires_at
            )
        ");

        $ok = $stmt->execute([
            ':cart_id'                => $data['cart_id'],
            ':user_id'                => $data['user_id'] ?? null,
            ':id_hotel'               => $data['id_hotel'] ?? Config::get('DEFAULT_HOTEL_ID', '1'),
            ':id_room_type'           => $data['id_room_type'],
            ':guest_data'             => json_encode($data['guest_data'] ?? [], JSON_THROW_ON_ERROR),
            ':room_data'              => json_encode($data['room_data'] ?? [], JSON_THROW_ON_ERROR),
            ':price_snapshot'         => $data['price_snapshot'],
            ':price_snapshot_pen'     => $data['price_snapshot_pen'] ?? null,
            ':exchange_rate_snapshot' => $data['exchange_rate_snapshot'] ?? null,
            ':checkin'                => $data['checkin'],
            ':checkout'               => $data['checkout'],
            ':status'                 => $data['status'] ?? BookingStatus::Pending->value,
            ':expires_at'             => $data['expires_at'],
        ]);
        if (!$ok) {
            throw new PDOException('No se pudo insertar provisional_booking');
        }

        // Ledger normalizado por tipo de habitación. Los consumidores pueden
        // representar reservas mixtas (A+B) y cantidades (A+A) sin romper
        // filas legacy, que no tienen entradas en este ledger.
        $quantities = $data['room_quantities'] ?? [];
        if (is_array($quantities) && $quantities !== []) {
            $ledger = $this->pdo->prepare(
                'INSERT INTO provisional_booking_rooms (cart_id, id_room_type, quantity) VALUES (:cart_id, :id_room_type, :quantity)'
            );
            foreach ($quantities as $roomType => $quantity) {
                $quantity = (int)$quantity;
                if ($quantity < 1) {
                    continue;
                }
                $ledger->execute([
                    ':cart_id' => $data['cart_id'],
                    ':id_room_type' => (int)$roomType,
                    ':quantity' => $quantity,
                ]);
            }
        }
        if ($startedTransaction) {
            $this->pdo->commit();
        }
        return true;
        } catch (Throwable $e) {
            if ($startedTransaction && $this->isTransactionActive()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * PDO transaction state is mutable outside this method (for example after
     * a driver error), so callers must not let PHPStan memoize its result.
     *
     * @phpstan-impure
     */
    private function isTransactionActive(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function ensureTablesExist(): bool {
        if (!$this->pdo) return false;
        // MySQL ejecuta DDL con COMMIT implícito. Nunca auto-migrar mientras
        // el caller mantiene locks de una unidad de negocio; devolver false
        // fuerza al caller a abortar/reintentar fuera de la transacción.
        if ($this->pdo->inTransaction()) {
            Logger::error('ProvisionalBookingRepository::ensureTablesExist: migracion diferida porque hay una transaccion activa.');
            return false;
        }
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS provisional_bookings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    cart_id VARCHAR(64) UNIQUE NOT NULL,
                    user_id INT NULL,
                    id_hotel INT DEFAULT 1,
                    id_room_type INT NOT NULL,
                    guest_data TEXT,
                    room_data TEXT,
                    price_snapshot DECIMAL(10,2) NOT NULL,
                    price_snapshot_pen DECIMAL(12,2) NULL,
                    exchange_rate_snapshot DECIMAL(12,4) NULL,
                    checkin DATE NOT NULL,
                    checkout DATE NOT NULL,
                    status VARCHAR(32) DEFAULT 'pending',
                    expires_at DATETIME NOT NULL,
                    reconcile_claimed_until DATETIME NULL,
                    reconcile_claim_token VARCHAR(64) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_hotel_room_status_dates (id_hotel, id_room_type, status, checkin, checkout)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS provisional_booking_rooms (
                    cart_id VARCHAR(64) NOT NULL,
                    id_room_type INT NOT NULL,
                    quantity INT NOT NULL DEFAULT 1,
                    PRIMARY KEY (cart_id, id_room_type),
                    INDEX idx_room_type (id_room_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS processed_payments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    payment_id VARCHAR(64) NOT NULL,
                    cart_id VARCHAR(64) NOT NULL,
                    status VARCHAR(32) NOT NULL,
                    event_type VARCHAR(32) NULL,
                    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_payment_event (payment_id, event_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS room_locks (
                    room_id VARCHAR(64) PRIMARY KEY,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS payment_alerts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    cart_id VARCHAR(64) NOT NULL,
                    payment_id VARCHAR(64) NOT NULL,
                    alert_type VARCHAR(32) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_payment_alerts_cart (cart_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // Auto-heal: garantizar columna payment_id (necesaria para
            // attachPaymentId y la reconciliacion de pagos). Migracion
            // documentada en docs/refactoring/CRON.md.
            if (!$this->columnExists('provisional_bookings', 'payment_id')) {
                $this->pdo->exec("ALTER TABLE provisional_bookings ADD COLUMN payment_id VARCHAR(64) NULL AFTER status");
                Logger::info('ProvisionalBookingRepository: Columna payment_id creada automaticamente en provisional_bookings.');
            }
            if (!$this->columnExists('provisional_bookings', 'payment_idempotency_key')) {
                $this->pdo->exec("ALTER TABLE provisional_bookings ADD COLUMN payment_idempotency_key VARCHAR(64) NULL AFTER payment_id");
                Logger::info('ProvisionalBookingRepository: Columna payment_idempotency_key creada automaticamente en provisional_bookings.');
            }

            // Auto-heal: PEN y tasa CONGELADOS al cotizar. El WRITE lo hace
            // CreateBookingAction; aqui solo se verifica. Sin estas columnas,
            // BookingPaidEvent::fromHold derivaria con la tasa ACTUAL (falso
            // fraude por descalce).
            if (!$this->columnExists('provisional_bookings', 'price_snapshot_pen')) {
                $this->pdo->exec("ALTER TABLE provisional_bookings ADD COLUMN price_snapshot_pen DECIMAL(12,2) NULL AFTER price_snapshot");
                Logger::info('ProvisionalBookingRepository: Columna price_snapshot_pen creada automaticamente en provisional_bookings.');
            }
            if (!$this->columnExists('provisional_bookings', 'exchange_rate_snapshot')) {
                $this->pdo->exec("ALTER TABLE provisional_bookings ADD COLUMN exchange_rate_snapshot DECIMAL(12,4) NULL AFTER price_snapshot_pen");
                Logger::info('ProvisionalBookingRepository: Columna exchange_rate_snapshot creada automaticamente en provisional_bookings.');
            }

            // Auto-heal: processed_payments con event_type + indice unico
            // compuesto (payment_id, event_type). Desbloquea los refunds: un
            // evento refunded del mismo payment_id ya no colisiona con el
            // registro approved.
            $this->migrateProcessedPaymentsEventType();

            // Auto-heal: extend_count limita las extensiones del hold (el tope
            // por expires_at fallaba con extensiones inmediatas — cada una
            // resetea expires_at a +TTL).
            if (!$this->columnExists('provisional_bookings', 'extend_count')) {
                $this->pdo->exec("ALTER TABLE provisional_bookings ADD COLUMN extend_count INT NOT NULL DEFAULT 0 AFTER expires_at");
                Logger::info('ProvisionalBookingRepository: Columna extend_count creada automaticamente en provisional_bookings.');
            }
            if (!$this->columnExists('provisional_bookings', 'reconcile_claimed_until')) {
                $this->pdo->exec("ALTER TABLE provisional_bookings ADD COLUMN reconcile_claimed_until DATETIME NULL AFTER extend_count");
            }
            if (!$this->columnExists('provisional_bookings', 'reconcile_claim_token')) {
                $this->pdo->exec("ALTER TABLE provisional_bookings ADD COLUMN reconcile_claim_token VARCHAR(64) NULL AFTER reconcile_claimed_until");
            }
            return true;
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::ensureTablesExist Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verifica si una columna existe en una tabla (informacion_schema).
     * Los literales de tabla/columna son constantes internas (sin input de usuario).
     * Metadata changes after ALTER TABLE and can also be performed by another
     * worker, therefore this lookup is intentionally impure.
     *
     * @phpstan-impure
     */
    private function columnExists(string $table, string $column): bool {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = '{$table}'
               AND COLUMN_NAME = '{$column}'"
        );
        $stmt->execute();
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Migracion idempotente de processed_payments:
     *   1. ADD COLUMN event_type (si falta)
     *   2. backfill -> 'approved' (NULL nunca colisiona en indices unicos MySQL)
     *   3. swap del indice legacy UNIQUE(payment_id) -> UNIQUE(payment_id,
     *      event_type) en UNA sentencia atomica (sin ventana sin indice entre
     *      DROP y ADD).
     * Validacion previa del indice legacy desde information_schema: SOLO se
     * dropea si es EXACTAMENTE single-column UNIQUE(payment_id); si la
     * validacion falla -> FAIL-CLOSED (abortar, nunca dropear a ciegas).
     * Retry x3 ante metadata-lock timeout (1205/1213).
     */
    private function migrateProcessedPaymentsEventType(): void {
        if (!$this->columnExists('processed_payments', 'event_type')) {
            $this->pdo->exec("ALTER TABLE processed_payments ADD COLUMN event_type VARCHAR(32) NULL AFTER status");
            Logger::info('ProvisionalBookingRepository: Columna event_type creada automaticamente en processed_payments.');
        }

        $this->pdo->exec("UPDATE processed_payments SET event_type = 'approved' WHERE event_type IS NULL");

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $info = $this->resolveProcessedPaymentIndexes();
            if ($info['failClosed']) {
                Logger::error('ProvisionalBookingRepository: migracion event_type ABORTADA (fail-closed).');
                return;
            }
            if ($info['hasComposite']) {
                return; // ya migrado (boot idempotente)
            }

            $sql = $info['legacy'] !== null
                ? "ALTER TABLE processed_payments DROP INDEX `{$info['legacy']}`, ADD UNIQUE KEY uk_payment_event (payment_id, event_type)"
                : "ALTER TABLE processed_payments ADD UNIQUE KEY uk_payment_event (payment_id, event_type)";

            try {
                $this->pdo->exec($sql);
                Logger::info('ProvisionalBookingRepository: indice uk_payment_event (payment_id, event_type) garantizado.');
                return;
            } catch (PDOException $e) {
                $driverCode = is_array($e->errorInfo ?? null) ? (int)($e->errorInfo[1] ?? 0) : (int)$e->getCode();
                $isLockTimeout = in_array($driverCode, [1205, 1213], true)
                    || str_contains($e->getMessage(), '1205')
                    || str_contains($e->getMessage(), '1213');
                $isAlreadyDropped = $driverCode === 1091
                    || str_contains($e->getMessage(), '1091')
                    || str_contains($e->getMessage(), 'check that column/key exists');
                if ($isLockTimeout && $attempt < 3) {
                    usleep(300_000 * $attempt); // backoff corto
                    continue;
                }
                if ($isAlreadyDropped) {
                    continue; // "ya dropeado": re-resolver en el siguiente intento (ruta plain ADD)
                }
                Logger::error('ProvisionalBookingRepository: migracion event_type fallo: ' . $e->getMessage());
                return;
            }
        }
        Logger::error('ProvisionalBookingRepository: migracion event_type no completo tras 3 intentos (lock timeout persistente).');
    }

    /**
     * Resuelve los indices UNIQUE de processed_payments desde information_schema.
     *
     * @return array{hasComposite: bool, legacy: ?string, failClosed: bool}
     */
    private function resolveProcessedPaymentIndexes(): array {
        $stmt = $this->pdo->query(
            "SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'processed_payments'
             GROUP BY INDEX_NAME, NON_UNIQUE"
        );
        if ($stmt === false) {
            // No se puede inspeccionar: FAIL-CLOSED, nunca dropear el indice.
            return ['hasComposite' => false, 'legacy' => null, 'failClosed' => true];
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $hasComposite = false;
        $legacy = null;

        foreach ($rows as $row) {
            $cols = explode(',', (string)($row['cols'] ?? ''));
            $isUnique = (int)($row['NON_UNIQUE'] ?? 1) === 0;
            if (!$isUnique) {
                continue;
            }
            if ($cols === ['payment_id', 'event_type']) {
                $hasComposite = true;
                continue;
            }
            if ($cols === ['payment_id']) {
                $legacy = (string)($row['INDEX_NAME'] ?? '');
                continue;
            }
            // Cualquier otro indice UNIQUE que involucre payment_id -> la
            // invariante no es la esperada: FAIL-CLOSED, nunca dropear.
            if (in_array('payment_id', $cols, true)) {
                Logger::error(
                    'ProvisionalBookingRepository: indice unico inesperado '
                    . ($row['INDEX_NAME'] ?? '?') . ' (' . implode(',', $cols) . ') en processed_payments.'
                );
                return ['hasComposite' => false, 'legacy' => null, 'failClosed' => true];
            }
        }

        return ['hasComposite' => $hasComposite, 'legacy' => $legacy, 'failClosed' => false];
    }

    /**
     * @return array<string, mixed>|null
     * @throws PDOException When the database query fails. A database outage
     *   must remain distinguishable from a missing hold so webhook callers
     *   can return 500 and Mercado Pago can retry the notification.
     */
    public function getByCartId(string $cartId, bool $forUpdate = false): ?array {
        try {
            $sql = "SELECT * FROM provisional_bookings WHERE cart_id = :cart_id LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':cart_id' => $cartId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ? $this->hydrateRow($row) : null;
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::getByCartId Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Obtiene una reserva provisional con bloqueo pesimista (FOR UPDATE) dentro de una transaccion activa.
     *
     * @return array<string, mixed>|null
     */
    public function getByCartIdForUpdate(string $cartId): ?array {
        return $this->getByCartId($cartId, true);
    }

    /**
     * Decodifica los campos JSON de una fila de hold.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateRow(array $row): array {
        if (!empty($row['guest_data']) && is_string($row['guest_data'])) {
            $row['guest_data'] = json_decode($row['guest_data'], true) ?: [];
        }
        if (!empty($row['room_data']) && is_string($row['room_data'])) {
            $row['room_data'] = json_decode($row['room_data'], true) ?: [];
        }
        return $row;
    }

    /**
     * Verifica si un (payment_id, event_type) ya fue procesado en la tabla de
     * idempotencia. Debe ejecutarse DENTRO de la transaccion del webhook.
     * FAIL-CLOSED: ante error de BD lanza PDOException (el webhook responde
     * 500 y MP reintenta) — nunca devuelve false por error, que reprocesaria
     * un pago duplicado.
     *
     * @throws PDOException
     */
    public function isPaymentProcessed(string $paymentId, string $eventType = 'approved'): bool {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM processed_payments
             WHERE payment_id = :payment_id AND event_type = :event_type
             FOR UPDATE"
        );
        $stmt->execute([':payment_id' => $paymentId, ':event_type' => $eventType]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Registra un pago procesado en la tabla de idempotencia processed_payments.
     * El tercer parametro es el TIPO DE EVENTO: 'approved',
     * 'refunded', 'rejected', 'orphan', 'fraud_review' — el indice unico
     * compuesto (payment_id, event_type) permite que un refund del mismo
     * payment_id coexista con su approved. INSERT ... ON DUPLICATE KEY como
     * cinturon-y-tirantes ante entregas concurrentes del mismo evento.
     */
    public function markPaymentProcessed(string $paymentId, string $cartId, string $eventType = 'approved'): bool {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO processed_payments (payment_id, cart_id, status, event_type)
                VALUES (:payment_id, :cart_id, :status, :event_type)
                ON DUPLICATE KEY UPDATE status = VALUES(status), event_type = VALUES(event_type)
            ");
            return $stmt->execute([
                ':payment_id' => $paymentId,
                ':cart_id'    => $cartId,
                ':status'     => $eventType,
                ':event_type' => $eventType,
            ]);
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::markPaymentProcessed Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Transiciones de status ATOMICAS POR-TARGET:
     * NUNCA WHERE solo por cart_id (resucitaria holds expirados y dejaria
     * dinero capturado sin reserva). Si dos actores corren concurrentes
     * (webhook vs cron), gana el primer UPDATE atomico y el segundo matchea
     * 0 filas. Targets no declarados -> fail-closed (false + log).
     */
    public function updateStatus(string $cartId, string $status): bool {
        $transient = "'" . implode("','", [
            BookingStatus::Pending->value,
            BookingStatus::ManualReview->value,
            BookingStatus::FraudReview->value,
        ]) . "'";

        $guards = [
            BookingStatus::Paid->value        => "status IN ({$transient})",
            BookingStatus::FraudReview->value => "status IN ({$transient})",
            BookingStatus::ManualReview->value => "status IN ({$transient})",
            BookingStatus::ExpiredPaid->value => "status IN ('" . BookingStatus::Expired->value . "')",
            // Pago final-rechazado/cancelado: desde pending
            // (caso normal) y desde expired (el pago tardo mas que el TTL del
            // hold y el cron ya lo marco — la UI debe mostrar "rechazado", no
            // "expirado"; failed es terminal, sin dinero ni inventario).
            // NUNCA desde paid/expired_paid (dinero capturado) ni desde
            // manual/fraud review (los resuelve RetryManualReviewAction).
            BookingStatus::Failed->value      => "status IN ('" . BookingStatus::Pending->value . "','" . BookingStatus::Expired->value . "')",
        ];

        if (!isset($guards[$status])) {
            Logger::error("ProvisionalBookingRepository::updateStatus: transicion hacia '{$status}' no declarada (fail-closed).");
            return false;
        }

        try {
            $stmt = $this->pdo->prepare(
                "UPDATE provisional_bookings SET status = :status WHERE cart_id = :cartId AND " . $guards[$status]
            );
            return $stmt->execute([':status' => $status, ':cartId' => $cartId])
                && $stmt->rowCount() === 1;
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::updateStatus Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Registra una alerta de pago para resolucion manual (pago approved
     * sobre hold expirado -> expired_paid; la habitacion pudo re-venderse).
     * Log + tabla payment_alerts.
     */
    public function recordAlert(string $cartId, string $paymentId, string $alertType): bool {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO payment_alerts (cart_id, payment_id, alert_type) VALUES (:cart_id, :payment_id, :alert_type)"
            );
            $ok = $stmt->execute([
                ':cart_id'     => $cartId,
                ':payment_id'  => $paymentId,
                ':alert_type'  => $alertType,
            ]);
            Logger::error("ProvisionalBookingRepository::recordAlert ALERTA {$alertType}: cart={$cartId} payment={$paymentId}");
            return $ok;
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::recordAlert Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Applies a reconciliation transition only while this worker still owns
     * the lease. The token condition fences a worker whose external lookup
     * outlived its lease and was reclaimed by another worker.
     */
    public function updateStatusWithReconcileClaim(string $cartId, string $status, string $claimToken): bool {
        $transient = "'" . implode("','", [
            BookingStatus::Pending->value,
            BookingStatus::ManualReview->value,
            BookingStatus::FraudReview->value,
        ]) . "'";
        $guards = [
            BookingStatus::Paid->value        => "status IN ({$transient})",
            BookingStatus::FraudReview->value => "status IN ({$transient})",
            BookingStatus::ManualReview->value => "status IN ({$transient})",
            BookingStatus::ExpiredPaid->value => "status IN ('" . BookingStatus::Expired->value . "')",
            BookingStatus::Failed->value      => "status IN ('" . BookingStatus::Pending->value . "','" . BookingStatus::Expired->value . "')",
        ];
        if ($claimToken === '' || !isset($guards[$status])) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE provisional_bookings
                 SET status = :status, reconcile_claimed_until = NULL, reconcile_claim_token = NULL
                 WHERE cart_id = :cartId
                   AND reconcile_claim_token = :claimToken
                   AND reconcile_claimed_until > NOW()
                   AND {$guards[$status]}"
            );
            return $stmt->execute([
                ':status' => $status,
                ':cartId' => $cartId,
                ':claimToken' => $claimToken,
            ]) && $stmt->rowCount() === 1;
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::updateStatusWithReconcileClaim Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Releases a reconciliation lease after a terminal/idempotent skip.
     * The worker may safely return the row to the queue when the payment was
     * already recorded; retaining the ten-minute lease only delays cleanup.
     */
    public function releaseReconcileClaim(string $cartId, string $claimToken): bool {
        if ($cartId === '' || $claimToken === '') {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE provisional_bookings
                 SET reconcile_claimed_until = NULL, reconcile_claim_token = NULL
                 WHERE cart_id = :cartId AND reconcile_claim_token = :claimToken'
            );
            return $stmt->execute([
                ':cartId' => $cartId,
                ':claimToken' => $claimToken,
            ]) && $stmt->rowCount() === 1;
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::releaseReconcileClaim Error: ' . $e->getMessage());
            return false;
        }
    }

    public function extend(string $cartId, string $newExpiration): bool {
        try {
            // Tope por CONTEO de extensiones: sin limite, un bot con el token
            // podia acaparar la habitacion indefinidamente. Un tope temporal
            // por expires_at falla con extensiones inmediatas (cada una
            // resetea expires_at a +TTL); el contador es exacto.
            // El frontend extiende 1 vez para cubrir el pago en vuelo; 3 es
            // margen de sobra (MAX_HOLD_EXTENSIONS).
            $maxExtensions = (int)Config::get('BOOKING_HOLD_MAX_EXTENSIONS', '3');
            $stmt = $this->pdo->prepare("
                UPDATE provisional_bookings 
                SET expires_at = :newExp, extend_count = extend_count + 1
                WHERE cart_id = :cartId AND status = '".BookingStatus::Pending->value."'
                  AND expires_at > NOW()
                  AND extend_count < {$maxExtensions}
            ");
            // rowCount > 0: sin esto, un UPDATE que matchea 0 filas (limite
            // alcanzado) devuelve true y el action respondia success sin
            // haber extendido nada.
            return $stmt->execute([':newExp' => $newExpiration, ':cartId' => $cartId]) && $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::extend Error: ' . $e->getMessage());
            return false;
        }
    }

    public function cleanExpiredCarts(): int {
        try {
            // FROM-set explicito: incluye manual_review y fraud_review
            // para que un hold en fraude no bloquee la habitacion
            // indefinidamente; NUNCA por cart_id solo, y NUNCA afecta
            // paid/expired_paid (dinero capturado sin reserva).
            $transient = "'" . implode("','", [
                BookingStatus::Pending->value,
                BookingStatus::ManualReview->value,
                BookingStatus::FraudReview->value,
            ]) . "'";
            $stmt = $this->pdo->prepare("
                UPDATE provisional_bookings 
                SET status = '" . BookingStatus::Expired->value . "' 
                WHERE status IN ({$transient}) AND expires_at < NOW()
            ");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::cleanExpiredCarts Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Serializa la creacion de holds de una habitacion.
     *
     * MECANISMO: get-or-create de la fila en room_locks
     * (INSERT ... ON DUPLICATE KEY UPDATE = no-op) seguido de SELECT ... FOR
     * UPDATE sobre ESA fila — el objetivo de lock SIEMPRE existe, incluso
     * para habitaciones sin holds. Debe ejecutarse DENTRO de la transaccion
     * que verifica disponibilidad e inserta el hold (nunca autocommit): la
     * serializacion depende de mantener el row-lock hasta el commit.
     * NUNCA SELECT FOR UPDATE sobre filas de fechas (rango vacio = no
     * bloquea nada).
     */
    public function lockRoom(string $roomId): bool {
        if (!$this->pdo) return false;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $stmt = $this->pdo->prepare(
                    "INSERT INTO room_locks (room_id) VALUES (:room_id) ON DUPLICATE KEY UPDATE room_id = room_id"
                );
                $stmt->execute([':room_id' => $roomId]);

                $lock = $this->pdo->prepare(
                    "SELECT room_id FROM room_locks WHERE room_id = :room_id FOR UPDATE"
                );
                $lock->execute([':room_id' => $roomId]);
                $lock->fetch();
                return true;
            } catch (PDOException $e) {
                // Tabla aun no creada (primer boot): crear y reintentar una vez.
                if ($attempt === 1 && (str_contains($e->getMessage(), '1146') || str_contains($e->getMessage(), '42S02') || str_contains($e->getMessage(), "doesn't exist"))) {
                    $this->ensureTablesExist();
                    continue;
                }
                Logger::error('ProvisionalBookingRepository::lockRoom Error: ' . $e->getMessage());
                return false;
            }
        }
        return false;
    }

    /**
     * Cuenta holds paid/pending-no-expirados que solapan el rango de fechas.
     * $createdAfter (opcional): limita al conteo a holds creados DESPUÉS de un
     * timestamp — el re-check de CreateBookingAction lo usa para no volver a
     * restar los holds que getAvailableRooms YA descontó (doble conteo).
     * SIN FOR UPDATE: el lock de serializacion es la fila de room_locks
     * tomada con lockRoom(); un FOR UPDATE sobre el rango de fechas no
     * bloquearia nada si el rango esta vacio.
     */
    public function getHoldCountForRoomForUpdate(int $idRoomType, string $checkIn, string $checkOut, int $idHotel, ?string $createdAfter = null): int {
        if (!$this->pdo) {
            // Fail-closed: an unavailable database must never look like zero
            // holds, otherwise CreateBookingAction could accept a booking with
            // unknown inventory and overbook the room type.
            throw new PDOException('No hay conexion de base de datos para verificar disponibilidad.');
        }
        try {
            $sql = "
                SELECT COALESCE(SUM(CASE WHEN pbr.quantity IS NULL THEN 1 ELSE pbr.quantity END), 0)
                FROM provisional_bookings pb
                LEFT JOIN provisional_booking_rooms pbr
                  ON pbr.cart_id = pb.cart_id AND pbr.id_room_type = :idRoomTypeJoin
                WHERE pb.id_hotel = :idHotel
                  AND ((pbr.id_room_type IS NOT NULL) OR (pbr.id_room_type IS NULL AND pb.id_room_type = :idRoomType))
                  AND (pb.status = '".BookingStatus::Paid->value."' OR (pb.status = '".BookingStatus::Pending->value."' AND pb.expires_at > NOW()))
                  AND pb.checkin < :checkout
                  AND pb.checkout > :checkin
            ";
            $params = [
                ':idHotel'    => $idHotel,
                ':idRoomType' => $idRoomType,
                ':idRoomTypeJoin' => $idRoomType,
                ':checkin'    => $checkIn,
                ':checkout'   => $checkOut,
            ];
            if ($createdAfter !== null) {
                // >= para no perder holds commiteados en el mismo segundo que la
                // lectura inicial (created_at == lectura): un falso rechazo en
                // una ventana de 1s es preferible a un hold no contado (overbooking).
                // ponytail: el re-check solo cubre la ventana lectura->lock (~ms).
                $sql .= " AND created_at >= :createdAfter";
                $params[':createdAfter'] = $createdAfter;
            }
            $stmt = $this->pdo->prepare($sql);
            if ($stmt === false) {
                throw new PDOException('No se pudo preparar el recuento de disponibilidad.');
            }
            $stmt->execute($params);
            $count = $stmt->fetchColumn();
            if ($count === false) {
                throw new PDOException('No se pudo leer el recuento de disponibilidad.');
            }
            return (int)$count;
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::getHoldCountForRoomForUpdate Error: ' . $e->getMessage());
            // Fail-closed: un recuento desconocido no puede tratarse como
            // cero dentro de la transaccion de serializacion, porque permitiria
            // crear un hold aunque exista inventario ya reservado.
            throw $e;
        }
    }

    /**
     * Reservas provisionales pendientes con payment_id registrado (para reconciliacion).
     * Devuelve solo holds aun no expirados.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPendingHoldsWithPaymentId(): array {
        if (!$this->pdo) return [];
        // Reconciliation is the first worker path that reads the claim columns
        // on older installations. Ensure the auto-migration has run before the
        // SELECT; a failed migration must be visible in logs rather than look
        // like an empty queue.
        if (!$this->ensureTablesExist()) {
            Logger::error('ProvisionalBookingRepository: claim schema unavailable; reconciliation deferred.');
            return [];
        }
        $startedTransaction = false;
        $claimToken = bin2hex(random_bytes(16));
        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $startedTransaction = true;
            }
            // Incluye 'expired' y elimina el filtro de expiración — un hold
            // expirado con pago approved y webhook perdido quedaba huérfano
            // para siempre (nadie lo reconciliaba). El action decide:
            // pending -> paid, expired -> expired_paid + alerta.
            $stmt = $this->pdo->prepare("
                SELECT * FROM provisional_bookings
                WHERE status IN ('".BookingStatus::Pending->value."','".BookingStatus::Expired->value."')
                  AND payment_id IS NOT NULL
                  AND payment_id <> ''
                  AND (reconcile_claimed_until IS NULL OR reconcile_claimed_until < NOW())
                ORDER BY created_at ASC, id ASC
                LIMIT 50
                FOR UPDATE SKIP LOCKED
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                if ($startedTransaction) {
                    $this->pdo->commit();
                }
                return [];
            }
            $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $claim = $this->pdo->prepare("UPDATE provisional_bookings SET reconcile_claimed_until = DATE_ADD(NOW(), INTERVAL 10 MINUTE), reconcile_claim_token = ? WHERE id IN ({$placeholders})");
            $claim->execute(array_merge([$claimToken], $ids));
            $claimed = $this->pdo->prepare("SELECT * FROM provisional_bookings WHERE reconcile_claim_token = ? ORDER BY created_at ASC, id ASC");
            $claimed->execute([$claimToken]);
            $rows = $claimed->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$row) {
                $row = $this->hydrateRow($row);
            }
            if ($startedTransaction) {
                $this->pdo->commit();
            }
            return $rows;
        } catch (PDOException $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Logger::error('ProvisionalBookingRepository::getPendingHoldsWithPaymentId Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Registra el payment_id de MercadoPago sobre un hold pendiente.
     * La columna se auto-migra durante el bootstrap de reservas o antes del
     * primer intento de pago en instalaciones antiguas.
     */
    public function attachPaymentId(string $cartId, string $paymentId): bool {
        if ($cartId === '' || $paymentId === '') {
            Logger::error('ProvisionalBookingRepository::attachPaymentId: cart_id y payment_id son obligatorios.');
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("UPDATE provisional_bookings
                SET payment_id = :payment_id_set
                WHERE cart_id = :cartId
                  AND (payment_id IS NULL OR payment_id = '' OR payment_id = :payment_id_existing)");
            if (!$stmt->execute([
                ':payment_id_set' => $paymentId,
                ':payment_id_existing' => $paymentId,
                ':cartId' => $cartId,
            ])) {
                return false;
            }

            // MySQL puede devolver rowCount() = 0 cuando el valor ya era el
            // mismo. Es un resultado idempotente, no un fallo: confirmar la
            // misma notificacion no debe provocar un recovery falso.
            if ($stmt->rowCount() === 1) {
                return true;
            }

            $verify = $this->pdo->prepare(
                'SELECT payment_id FROM provisional_bookings WHERE cart_id = :cartId LIMIT 1'
            );
            if (!$verify->execute([':cartId' => $cartId])) {
                return false;
            }
            $current = $verify->fetchColumn();
            return $current !== false && hash_equals($paymentId, trim((string)$current));
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::attachPaymentId Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Persiste la clave de idempotencia del intento antes de llamar a la
     * pasarela. La fila debe estar bloqueada por el caller; la clave queda
     * durable aunque la transaccion del cobro posterior haga rollback.
     */
    public function setPaymentIdempotencyKey(string $cartId, string $key): bool {
        try {
            // ProcessPaymentAction invokes this method before opening its
            // payment transaction. Auto-heal only here so legacy installs get
            // the column on their first payment attempt, while never running
            // DDL inside an active transaction (MySQL implicitly commits DDL).
            if (!$this->ensurePaymentIdempotencyKeyColumn()) {
                return false;
            }
            $stmt = $this->pdo->prepare("UPDATE provisional_bookings
                SET payment_idempotency_key = :key
                WHERE cart_id = :cartId
                  AND status = 'pending'
                  AND (payment_id IS NULL OR payment_id = '')
                  AND (payment_idempotency_key IS NULL OR payment_idempotency_key = '')");
            return $stmt->execute([':key' => $key, ':cartId' => $cartId])
                && $stmt->rowCount() === 1;
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::setPaymentIdempotencyKey Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Ensures the durable payment idempotency column exists before a payment
     * attempt. This is intentionally narrower than ensureTablesExist() for
     * the hot path; if the legacy payment_id column is also missing, the full
     * schema healer adds it first and then the idempotency column.
     */
    private function ensurePaymentIdempotencyKeyColumn(): bool
    {
        if ($this->pdo->inTransaction()) {
            Logger::error('ProvisionalBookingRepository: no se puede ejecutar DDL de idempotencia dentro de una transaccion.');
            return false;
        }

        if ($this->columnExists('provisional_bookings', 'payment_idempotency_key')) {
            return true;
        }

        if (!$this->columnExists('provisional_bookings', 'payment_id')) {
            return $this->ensureTablesExist()
                && $this->columnExists('provisional_bookings', 'payment_idempotency_key');
        }

        try {
            $this->pdo->exec(
                "ALTER TABLE provisional_bookings ADD COLUMN payment_idempotency_key VARCHAR(64) NULL AFTER payment_id"
            );
            Logger::info('ProvisionalBookingRepository: Columna payment_idempotency_key creada automaticamente en provisional_bookings.');
            return true;
        } catch (PDOException $e) {
            // A concurrent worker may have added it after our metadata check.
            if ($this->columnExists('provisional_bookings', 'payment_idempotency_key')) {
                return true;
            }
            Logger::error('ProvisionalBookingRepository::ensurePaymentIdempotencyKeyColumn Error: ' . $e->getMessage());
            return false;
        }
    }

    /** Limpia la clave solo tras un rechazo inequívoco de Mercado Pago. */
    public function clearPaymentIdempotencyKey(string $cartId): bool {
        try {
            $stmt = $this->pdo->prepare("UPDATE provisional_bookings
                SET payment_idempotency_key = NULL
                WHERE cart_id = :cartId AND (payment_id IS NULL OR payment_id = '')");
            return $stmt->execute([':cartId' => $cartId]);
        } catch (PDOException $e) {
            Logger::error('ProvisionalBookingRepository::clearPaymentIdempotencyKey Error: ' . $e->getMessage());
            return false;
        }
    }
}
