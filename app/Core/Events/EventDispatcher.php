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
                $stmt = $pdo->prepare("INSERT INTO event_outbox (event_name, payload, created_at) VALUES (:event_name, :payload, NOW())");
                $stmt->execute([
                    ':event_name' => $eventName,
                    ':payload'    => $payload,
                ]);
                return;
            } catch (Throwable $e) {
                Logger::error("EventDispatcher Error inserting into outbox: " . $e->getMessage());
                // Fallback to dispatchNow if outbox fails (e.g. table not created yet)
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
