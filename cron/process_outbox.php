<?php
declare(strict_types=1);

// Bootstrap compartido: autoloaders, Config, Container, bindings y listeners de dominio.
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Core\Logger;
use App\Core\Events\EventDispatcher;
use App\Core\Events\EventInterface;

$pdo = Database::getInstance()->getConnection();
if ($pdo === null) {
    echo "No database connection.\n";
    exit(1);
}

// 1. Crear tabla si no existe
$createTableSql = "
    CREATE TABLE IF NOT EXISTS event_outbox (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        event_name VARCHAR(255) NOT NULL,
        payload TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        processed_at DATETIME NULL DEFAULT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'PENDING'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

try {
    $pdo->exec($createTableSql);
} catch (Throwable $e) {
    echo "Error creating table: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Procesar eventos PENDING
try {
    $stmt = $pdo->prepare("SELECT * FROM event_outbox WHERE status = 'PENDING' ORDER BY id ASC LIMIT 50");
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($events)) {
        echo "No events to process.\n";
        exit(0);
    }

    $dispatcher = EventDispatcher::getInstance();

    foreach ($events as $row) {
        $id = $row['id'];
        $payloadRaw = $row['payload'];
        $eventName = $row['event_name'];

        try {
            $eventObj = unserialize(base64_decode($payloadRaw));
            if ($eventObj instanceof EventInterface) {
                // Dispatch now
                $dispatcher->dispatchNow($eventObj);

                // Marcar como procesado
                $updateStmt = $pdo->prepare("UPDATE event_outbox SET status = 'COMPLETED', processed_at = NOW() WHERE id = :id");
                $updateStmt->execute([':id' => $id]);
                echo "Processed event $id ($eventName) successfully.\n";
            } else {
                throw new \Exception("Unserialized payload is not an EventInterface");
            }
        } catch (Throwable $e) {
            Logger::error("Error processing event ID {$id}: " . $e->getMessage());
            $failStmt = $pdo->prepare("UPDATE event_outbox SET status = 'FAILED', processed_at = NOW() WHERE id = :id");
            $failStmt->execute([':id' => $id]);
            echo "Failed event $id: " . $e->getMessage() . "\n";
        }
    }

} catch (Throwable $e) {
    Logger::error("Cron process_outbox Error: " . $e->getMessage());
    echo "Cron process_outbox Error: " . $e->getMessage() . "\n";
    exit(1);
}
