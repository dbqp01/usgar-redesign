<?php
declare(strict_types=1);

namespace App\Features\Newsletter\Domain;

use PDO;
use RuntimeException;

/**
 * Persistencia de newsletter_subscribers (schema self-contained, misma
 * convencion que ProvisionalBookingRepository).
 *
 * La tabla nacio sin estado ni evidencia de consentimiento. ensureSchema()
 * agrega las columnas que falten y conserva a los suscriptores historicos:
 * quedan como 'confirmed' con consent_version='legacy' y consent_at NULL
 * (no se inventa una fecha que no existe).
 */
class NewsletterSubscriberRepository {
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    /** Version del aviso mostrado en el footer; cambiarlo invalida la evidencia anterior. */
    public const CONSENT_VERSION = '2026-10-29';

    private PDO $pdo;
    private bool $schemaReady = false;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function ensureSchema(): void {
        if ($this->schemaReady) {
            return;
        }

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS newsletter_subscribers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                ip VARCHAR(45) NOT NULL DEFAULT '',
                source VARCHAR(64) NOT NULL DEFAULT 'web',
                locale VARCHAR(8) NOT NULL DEFAULT 'en',
                user_agent VARCHAR(255) NOT NULL DEFAULT '',
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                consent_at DATETIME NULL,
                consent_version VARCHAR(16) NOT NULL DEFAULT '',
                confirmed_at DATETIME NULL,
                unsubscribed_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_newsletter_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $legacyMaxId = null;
        foreach ([
            'user_agent'      => "ALTER TABLE newsletter_subscribers ADD COLUMN user_agent VARCHAR(255) NOT NULL DEFAULT '' AFTER locale",
            'status'          => "ALTER TABLE newsletter_subscribers ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'pending' AFTER user_agent",
            'consent_at'      => 'ALTER TABLE newsletter_subscribers ADD COLUMN consent_at DATETIME NULL AFTER status',
            'consent_version' => "ALTER TABLE newsletter_subscribers ADD COLUMN consent_version VARCHAR(16) NOT NULL DEFAULT '' AFTER consent_at",
            'confirmed_at'    => 'ALTER TABLE newsletter_subscribers ADD COLUMN confirmed_at DATETIME NULL AFTER consent_version',
            'unsubscribed_at' => 'ALTER TABLE newsletter_subscribers ADD COLUMN unsubscribed_at DATETIME NULL AFTER confirmed_at',
        ] as $column => $alter) {
            if (!$this->columnExists($column)) {
                // Foto previa a la migracion: solo las filas historicas se
                // marcan legacy, nunca un alta concurrente de este deploy.
                if ($legacyMaxId === null && ($column === 'status' || $column === 'consent_at')) {
                    $maxStmt = $this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM newsletter_subscribers');
                    $legacyMaxId = $maxStmt === false ? 0 : (int)$maxStmt->fetchColumn();
                }

                $this->pdo->exec($alter);
            }
        }

        // Filas anteriores al consentimiento explicito: se mantienen suscritas,
        // marcadas como legacy y sin fecha fabricada.
        if ($legacyMaxId !== null) {
            $this->pdo->exec(
                "UPDATE newsletter_subscribers
                 SET status = 'confirmed', consent_version = 'legacy'
                 WHERE id <= {$legacyMaxId} AND consent_at IS NULL"
            );
        }

        $this->schemaReady = true;
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(
            'SELECT id, email, locale, status, consent_at, confirmed_at, unsubscribed_at
             FROM newsletter_subscribers WHERE email = :email LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function upsertPending(
        string $email,
        string $ip,
        string $locale,
        string $userAgent,
        string $consentAt
    ): void {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(
            'INSERT INTO newsletter_subscribers
                (email, ip, source, locale, user_agent, status, consent_at, consent_version)
             VALUES
                (:email, :ip, :source, :locale, :user_agent, :status, :consent_at, :consent_version)
             ON DUPLICATE KEY UPDATE
                ip = VALUES(ip),
                locale = VALUES(locale),
                user_agent = VALUES(user_agent),
                status = VALUES(status),
                consent_at = VALUES(consent_at),
                consent_version = VALUES(consent_version),
                confirmed_at = NULL,
                unsubscribed_at = NULL'
        );

        $ok = $stmt->execute([
            ':email'           => $email,
            ':ip'              => $ip,
            ':source'          => 'web',
            ':locale'          => $locale,
            ':user_agent'      => $userAgent,
            ':status'          => self::STATUS_PENDING,
            ':consent_at'      => $consentAt,
            ':consent_version' => self::CONSENT_VERSION,
        ]);

        if (!$ok) {
            throw new RuntimeException('No se pudo guardar la suscripcion.');
        }
    }

    public function markConfirmed(string $email, string $confirmedAt): bool {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(
            "UPDATE newsletter_subscribers
             SET status = :status, confirmed_at = :confirmed_at
             WHERE email = :email AND status = 'pending'"
        );
        $stmt->execute([
            ':status'       => self::STATUS_CONFIRMED,
            ':confirmed_at' => $confirmedAt,
            ':email'        => $email,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function markUnsubscribed(string $email, string $unsubscribedAt): bool {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(
            'UPDATE newsletter_subscribers
             SET status = :status, unsubscribed_at = :unsubscribed_at
             WHERE email = :email'
        );
        $stmt->execute([
            ':status'          => self::STATUS_UNSUBSCRIBED,
            ':unsubscribed_at' => $unsubscribedAt,
            ':email'           => $email,
        ]);

        return $stmt->rowCount() > 0;
    }

    private function columnExists(string $column): bool {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'newsletter_subscribers'
               AND COLUMN_NAME = '{$column}'"
        );
        $stmt->execute();

        return (int)$stmt->fetchColumn() > 0;
    }
}
