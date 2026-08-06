<?php
declare(strict_types=1);

namespace App\Test\Fixtures;

use App\Core\Config;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use App\Core\Events\EventDispatcher;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\Net\MPResponse;
use PDO;
use PDOException;
use Throwable;

/**
 * Test doubles in-memory para la Wave 2 (concurrencia/transacciones).
 *
 * MANDATO r10: los test doubles SOLO cubren puertos propios del proyecto
 * (PaymentGatewayPortInterface) — NUNCA simulan resultados de la API real
 * de MercadoPago en tests de integracion. El FakeGateway de esta wave es un
 * doble del PORT inyectado en ProcessPaymentAction (patron existente del
 * repo), usado por los workers de carrera para contar llamadas, NO un mock
 * del comportamiento de MP.
 */

/**
 * Gateway fake que registra cada llamada a processPayment en un archivo de
 * log compartido (los workers son procesos PHP distintos) y responde segun
 * un escenario: approved / in_process / rejected (resultado directo) o
 * othe (lanza MPApiException, el rechazo real de MP llega por excepcion).
 */
final class FakeGateway implements PaymentGatewayPortInterface {
    private string $logPath;
    private string $scenario;
    private int $paymentIdCounter;

    public function __construct(string $logPath, string $scenario, int $paymentIdCounter = 0) {
        $this->logPath = $logPath;
        $this->scenario = $scenario;
        $this->paymentIdCounter = $paymentIdCounter;
    }

    public function processPayment(array $paymentData): ?array {
        // OTHE: el SDK real lanza MPApiException ante un rechazo (400 con
        // status_detail). El worker NO registra la llamada: el pago nunca
        // existio.
        if ($this->scenario === 'othe') {
            throw new MPApiException(
                'Api error. Check response for details',
                new MPResponse(400, ['status_detail' => 'cc_rejected_other_reason'])
            );
        }

        $this->recordCall((string)($paymentData['external_reference'] ?? $paymentData['cart_id'] ?? ''));

        // Pequeno retraso para forzar la superposicion de los dos procesos
        // durante la ventana del lock pesimista.
        usleep(800_000);

        $id = 900000000 + ($this->paymentIdCounter * 1000) + random_int(1, 999);

        return match ($this->scenario) {
            'in_process' => [
                'id' => $id,
                'status' => 'in_process',
                'status_detail' => 'pending_contingency',
            ],
            'rejected' => [
                'id' => $id,
                'status' => 'rejected',
                'status_detail' => 'cc_rejected_other_reason',
            ],
            default => [
                'id' => $id,
                'status' => 'approved',
                'status_detail' => 'accredited',
            ],
        };
    }

    public function verifyNotification(array $payload, array $headers = []): ?array {
        return null;
    }

    public function verifySignature(?string $signatureHeader, ?string $requestId, ?string $dataId): bool {
        return true;
    }

    public function getPaymentDetails(string $paymentId): ?array {
        return null;
    }

    public function refundPayment(string $paymentId, ?float $amount = null): bool {
        return true;
    }

    public function countCalls(): int {
        if (!is_file($this->logPath)) {
            return 0;
        }
        return count(array_filter(file($this->logPath, FILE_IGNORE_NEW_LINES)));
    }

    private function recordCall(string $cartId): void {
        $line = sprintf("%s|%s|%s\n", date('c'), $cartId, getmypid());
        file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
    }
}

/**
 * EventDispatcher no-op: evita escribir en event_outbox de la BD real desde
 * los workers (los listeners del cron no deben ver filas de prueba).
 */
final class NoopEventDispatcher extends EventDispatcher {
    public function dispatch(\App\Core\Events\EventInterface $event): void {
        // no-op deliberado para tests
    }
}

/**
 * Conexion PDO hacia la BD configurada en .env (solo lectura de config,
 * nunca imprime credenciales). Devuelve null si la BD no esta disponible.
 */
final class TestDb {
    public static function connect(): ?PDO {
        try {
            return new PDO(
                'mysql:host=' . Config::get('DB_HOST', '127.0.0.1')
                . ';port=' . Config::get('DB_PORT', '3306')
                . ';dbname=' . Config::get('DB_NAME', 'usgar_hotels')
                . ';charset=utf8mb4',
                Config::get('DB_USER', ''),
                Config::get('DB_PASS', ''),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Crea las tablas nuevas que la wave introduce (room_locks, payment_alerts)
     * SI no existen. Son tablas vacias nuevas — el deploy las crea igual via
     * ensureTablesExist; aqui se crean antes para que los workers NUNCA
     * disparen ensureTablesExist contra la BD real (que migraria
     * processed_payments en produccion fuera de la wave).
     */
    public static function ensureWave2Tables(PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS room_locks (
            room_id VARCHAR(64) PRIMARY KEY,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS payment_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cart_id VARCHAR(64) NOT NULL,
            payment_id VARCHAR(64) NOT NULL,
            alert_type VARCHAR(32) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_payment_alerts_cart (cart_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** Limpia TODAS las filas de prueba W2RACE-* dejadas por tests. */
    public static function cleanup(PDO $pdo): void {
        $pdo->exec("DELETE FROM provisional_bookings WHERE cart_id LIKE 'W2RACE-%'");
        $pdo->exec("DELETE FROM processed_payments WHERE payment_id LIKE 'W2RACE-%'");
        $pdo->exec("DELETE FROM room_locks WHERE room_id LIKE '999999:%'");
        $pdo->exec("DELETE FROM payment_alerts WHERE cart_id LIKE 'W2RACE-%'");
        $pdo->exec("DELETE FROM event_outbox WHERE payload LIKE '%W2RACE-%'");
    }

    public static function isAvailable(PDO $pdo): bool {
        try {
            $rows = $pdo->query('SHOW TABLES LIKE \'provisional_bookings\'')->fetchAll(PDO::FETCH_COLUMN);
            return in_array('provisional_bookings', $rows, true);
        } catch (PDOException $e) {
            return false;
        }
    }
}
