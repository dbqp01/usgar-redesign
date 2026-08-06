<?php
declare(strict_types=1);

namespace App\Features\Newsletter\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Core\Logger;
use Throwable;

/**
 * Accion ADR: POST /api/newsletter
 * Suscribe un email a la newsletter. Crea la tabla si no existe
 * (schema self-contained, sin migraciones formales en este proyecto).
 * Inputs validados: email (filter_var), input sanitizado con prepared statement.
 */
class SubscribeNewsletterAction {
    public function __invoke(Request $request): void {
        $body = $request->getBody() ?? [];
        $email = trim((string)($body['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(['success' => false, 'error' => 'invalid_email'], 422);
            return;
        }

        $pdo = Database::getInstance()->getConnection();
        if (!$pdo) {
            Response::json(['success' => false, 'error' => 'service_unavailable'], 503);
            return;
        }

        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS newsletter_subscribers (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(255) NOT NULL,
                    ip VARCHAR(45) NOT NULL DEFAULT '',
                    source VARCHAR(64) NOT NULL DEFAULT 'web',
                    locale VARCHAR(8) NOT NULL DEFAULT 'en',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_newsletter_email (email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $locale = substr((string)($body['locale'] ?? 'en'), 0, 8);

            $stmt = $pdo->prepare(
                'INSERT INTO newsletter_subscribers (email, ip, source, locale)
                 VALUES (:email, :ip, :source, :locale)
                 ON DUPLICATE KEY UPDATE email = email'
            );
            $stmt->execute([
                ':email'  => $email,
                ':ip'     => $ip,
                ':source' => 'web',
                ':locale' => $locale,
            ]);

            Response::json(['success' => true, 'message' => 'subscribed']);
        } catch (Throwable $e) {
            Logger::error('[Newsletter] subscribe failed: ' . $e->getMessage());
            Response::json(['success' => false, 'error' => 'server_error'], 500);
        }
    }
}
