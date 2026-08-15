<?php
declare(strict_types=1);

namespace App\Features\Shared\Ports;

/**
 * Puerto de envío de correo transaccional (DIP).
 *
 * Implementado por PhpMailerAdapter (SMTP vía Config). Lanza RuntimeException
 * si el envío falla — el outbox (cron process_outbox) reintenta con backoff,
 * mismo patrón fail-closed que ConfirmQloAppsOrderListener.
 */
interface MailerPortInterface {
    /**
     * Envía un correo HTML con alternativa en texto plano.
     *
     * @param string $to      Destinatario (email del huésped).
     * @param string $subject Asunto.
     * @param string $html    Cuerpo HTML (estilos inline: clientes de correo).
     * @param string $text    Alternativa texto plano.
     *
     * @throws \RuntimeException si el envío falla (SMTP caído, auth, etc.).
     */
    public function send(string $to, string $subject, string $html, string $text): void;
}
