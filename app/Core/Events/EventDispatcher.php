<?php
declare(strict_types=1);

namespace App\Core\Events;

use App\Core\Logger;
use Throwable;

/**
 * Despachador central de eventos internos de dominio.
 * Permite registrar listeners y despachar eventos de forma desacoplada.
 */
class EventDispatcher {
    /** @var array<string, array<ListenerInterface>> */
    private array $listeners = [];

    private static ?self $instance = null;

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function subscribe(string $eventName, ListenerInterface $listener): void {
        $this->listeners[$eventName][] = $listener;
    }

    public function dispatch(EventInterface $event): void {
        $eventName = $event->getName();
        if (empty($this->listeners[$eventName])) {
            return;
        }

        $pdo = \App\Core\Database::getInstance()->getConnection();
        if ($pdo !== null) {
            $payload = base64_encode(serialize($event));
            try {
                // TODO 18 (Wave 4): el INSERT del evento en event_outbox corre
                // DENTRO de la transaccion del llamador (webhook) cuando hay una
                // activa — el patron transactional-outbox (microservices.io):
                // el mensaje se persiste en la MISMA txn que el cambio de
                // negocio; si el commit se confirma, el evento YA esta en el
                // outbox (no se pierde en la ventana commit->ACK). Sin txn
                // activa -> autocommit propio (back-compat para
                // ReconcilePaymentsAction). El evento FRESCO fija
                // next_attempt_at = NOW() (todo 19): sin esto, `next_attempt_at
                // NULL <= NOW()` es NULL/false en SQL y el cron nunca lo
                // procesaria.
                $stmt = $pdo->prepare("
                    INSERT INTO event_outbox (event_name, payload, status, attempts, next_attempt_at, created_at)
                    VALUES (:event_name, :payload, 'PENDING', 0, NOW(), NOW())
                ");
                $stmt->execute([
                    ':event_name' => $eventName,
                    ':payload'    => $payload,
                ]);
                return;
            } catch (Throwable $e) {
                Logger::error("EventDispatcher Error inserting into outbox: " . $e->getMessage());
                // Fallback: tabla legacy sin las columnas attempts/next_attempt_at
                // (antes del auto-heal del cron process_outbox). Reintenta con el
                // INSERT basico; el backfill del cron (todo 19) fija
                // next_attempt_at luego.
                try {
                    $stmt = $pdo->prepare("INSERT INTO event_outbox (event_name, payload, created_at) VALUES (:event_name, :payload, NOW())");
                    $stmt->execute([
                        ':event_name' => $eventName,
                        ':payload'    => $payload,
                    ]);
                    return;
                } catch (Throwable $e2) {
                    Logger::error("EventDispatcher Error inserting into outbox (legacy): " . $e2->getMessage());
                }
            }
        }

        $this->dispatchNow($event);
    }

    public function dispatchNow(EventInterface $event): void {
        $eventName = $event->getName();
        $lastError = null;
        foreach ($this->listeners[$eventName] ?? [] as $listener) {
            try {
                $listener->handle($event);
            } catch (Throwable $e) {
                Logger::error("EventDispatcher Error handling event {$eventName}: " . $e->getMessage());
                $lastError = $e;
            }
        }

        if ($lastError !== null) {
            throw $lastError;
        }
    }
}
