<?php
declare(strict_types=1);

namespace App\Features\Shared\Adapters;

use App\Core\Config;
use App\Features\Shared\Ports\MailerPortInterface;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PhpMailerException;
use RuntimeException;

/**
 * Adapter de correo transaccional vía PHPMailer + SMTP.
 *
 * Config (ver .env.example):
 *   SMTP_HOST       — servidor SMTP (Hostinger: smtp.hostinger.com)
 *   SMTP_PORT       — 587 por defecto (STARTTLS)
 *   SMTP_USER       — cuenta con permiso de envío (ej. no-reply@usgarhoteles.com)
 *   SMTP_PASS       — contraseña de la cuenta
 *   MAIL_FROM       — remitente visible (default no-reply@usgarhoteles.com)
 *   MAIL_FROM_NAME  — nombre del remitente (default "USGAR Hotels")
 *
 * Si SMTP_HOST está vacío el listener que lo usa se omite (email desactivado),
 * no se lanza: el outbox no debe reintentar un correo sin configuración.
 */
class PhpMailerAdapter implements MailerPortInterface {
    public function send(string $to, string $subject, string $html, string $text): void {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = (string)Config::get('SMTP_HOST', '');
            $mail->SMTPAuth   = true;
            $mail->Username   = (string)Config::get('SMTP_USER', '');
            $mail->Password   = (string)Config::get('SMTP_PASS', '');
            $mail->Port       = (int)Config::get('SMTP_PORT', 587);
            $mail->SMTPSecure = (string)Config::get('SMTP_SECURE', PHPMailer::ENCRYPTION_STARTTLS);
            $mail->Timeout    = (int)Config::get('SMTP_TIMEOUT', 15);
            $mail->CharSet    = PHPMailer::CHARSET_UTF8;

            $mail->setFrom(
                (string)Config::get('MAIL_FROM', 'no-reply@usgarhoteles.com'),
                (string)Config::get('MAIL_FROM_NAME', 'USGAR Hotels')
            );
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $text;

            $mail->send();
        } catch (PhpMailerException $e) {
            throw new RuntimeException('Fallo al enviar correo: ' . $e->getMessage(), 0, $e);
        }
    }
}
