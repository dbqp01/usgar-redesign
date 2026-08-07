<?php
declare(strict_types=1);

namespace App\Features\Contact\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Validator;
use App\Core\HttpException;
use PDO;
use Throwable;

/**
 * Accion ADR: POST /api/contact
 * Guarda un mensaje del formulario de contacto en `provisional_contacts`.
 * Schema self-contained (sin migraciones formales en este proyecto,
 * misma convencion que SubscribeNewsletterAction):
 *
 *   CREATE TABLE IF NOT EXISTS provisional_contacts (
 *       id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *       name VARCHAR(100) NOT NULL,
 *       email VARCHAR(255) NOT NULL,
 *       subject VARCHAR(64) NOT NULL DEFAULT 'general',
 *       message TEXT NOT NULL,
 *       created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
 *   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
 *
 * Contrato de respuesta:
 *   - 200 { "success": true, "data": { "id": <int> } }
 *   - 422 { "success": false, "error": { "code": "VALIDATION_ERROR", "message": "..." } }
 *   - 500 { "success": false, "error": { "code": "SERVER_ERROR", "message": "..." } }
 */
class SubmitContactAction {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function __invoke(Request $request): void {
        $body = $request->getBody() ?? [];

        try {
            Validator::requireFields($body, ['name', 'email', 'message']);

            $name    = trim((string)$body['name']);
            $email   = Validator::email((string)$body['email']);
            $subject = trim((string)($body['subject'] ?? '')) ?: 'general';
            $message = trim((string)$body['message']);

            if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
                throw HttpException::badRequest('El nombre debe tener entre 2 y 100 caracteres.');
            }
            if (mb_strlen($subject) > 64) {
                throw HttpException::badRequest('El asunto no puede superar los 64 caracteres.');
            }
            if (mb_strlen($message) < 10 || mb_strlen($message) > 2000) {
                throw HttpException::badRequest('El mensaje debe tener entre 10 y 2000 caracteres.');
            }
        } catch (HttpException $e) {
            Response::error($e->getMessage(), 422, 'VALIDATION_ERROR');
            return;
        }

        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS provisional_contacts (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    email VARCHAR(255) NOT NULL,
                    subject VARCHAR(64) NOT NULL DEFAULT 'general',
                    message TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $stmt = $this->pdo->prepare(
                'INSERT INTO provisional_contacts (name, email, subject, message)
                 VALUES (:name, :email, :subject, :message)'
            );
            $stmt->execute([
                ':name'    => $name,
                ':email'   => $email,
                ':subject' => $subject,
                ':message' => $message,
            ]);

            Response::json(['success' => true, 'data' => ['id' => (int)$this->pdo->lastInsertId()]]);
        } catch (Throwable $e) {
            // Nunca loguear el contenido del mensaje (dato personal).
            Logger::error('[Contact] submit failed: ' . $e->getMessage());
            Response::error('Error interno al guardar el mensaje.', 500, 'SERVER_ERROR');
        }
    }
}
