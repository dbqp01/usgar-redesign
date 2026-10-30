<?php
declare(strict_types=1);

namespace App\Features\Newsletter\Actions;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Features\Newsletter\Domain\NewsletterContent;
use App\Features\Newsletter\Domain\NewsletterSubscriberRepository;
use App\Features\Newsletter\Domain\NewsletterToken;
use App\Features\Shared\Ports\MailerPortInterface;
use Throwable;

/**
 * Accion ADR: POST /api/newsletter
 *
 * Doble opt-in con consentimiento explicito:
 *  1. exige `consent === true` (un checkbox sin marcar nunca suscribe);
 *  2. guarda la prueba (email, IP, user-agent, locale, fecha y version del aviso);
 *  3. envia un correo de confirmacion; hasta que el destinatario lo abra no
 *     recibe la newsletter. Un tercero que escriba el email de otra persona no
 *     puede suscribirla.
 *
 * La respuesta es identica para alta nueva y email ya confirmado: no revela
 * si la direccion esta en la lista.
 */
class SubscribeNewsletterAction {
    private const RATE_LIMIT_MAX = 5;
    private const RATE_LIMIT_WINDOW_SECONDS = 3600;

    /** Nullable: sin BD la accion responde 503 en vez de romper el autowiring. */
    public function __construct(
        private ?NewsletterSubscriberRepository $subscribers = null,
        private ?MailerPortInterface $mailer = null
    ) {
    }

    public function __invoke(Request $request): void {
        $body = $request->getBody() ?? [];
        $email = NewsletterToken::normalize((string)($body['email'] ?? ''));

        if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email.', 422, 'INVALID_EMAIL');
            return;
        }

        if (($body['consent'] ?? null) !== true) {
            Response::error('Explicit consent is required.', 422, 'CONSENT_REQUIRED');
            return;
        }

        $locale = NewsletterContent::normalizeLocale((string)($body['locale'] ?? 'en'));

        try {
            $allowed = RateLimiter::check(
                'newsletter:' . $request->getIp(),
                self::RATE_LIMIT_MAX,
                self::RATE_LIMIT_WINDOW_SECONDS
            );
        } catch (Throwable $e) {
            Logger::error('[Newsletter] rate limit storage unavailable: ' . $e::class);
            Response::error('Newsletter service unavailable.', 503, 'SERVICE_UNAVAILABLE');
            return;
        }

        if (!$allowed) {
            Response::error('Too many requests.', 429, 'RATE_LIMITED');
            return;
        }

        if ($this->subscribers === null || $this->mailer === null) {
            Response::error('Newsletter service unavailable.', 503, 'SERVICE_UNAVAILABLE');
            return;
        }

        try {
            $existing = $this->subscribers->findByEmail($email);
            if (($existing['status'] ?? '') === NewsletterSubscriberRepository::STATUS_CONFIRMED) {
                // Misma respuesta que un alta nueva: no se puede enumerar la lista.
                Response::success(['status' => 'pending', 'message' => 'confirmation_sent']);
                return;
            }

            $token = NewsletterToken::derive(NewsletterToken::CONFIRM, $email);
            $confirmUrl = rtrim((string)Config::get('SITE_URL', 'https://usgarhoteles.com'), '/')
                . '/api/newsletter/confirm?'
                . http_build_query(['email' => $email, 'token' => $token]);
            $mail = NewsletterContent::confirmationEmail($locale, $confirmUrl);
        } catch (HttpException $e) {
            Logger::error('[Newsletter] token configuration unavailable.');
            Response::error('Newsletter service unavailable.', 503, 'SERVICE_UNAVAILABLE');
            return;
        } catch (Throwable $e) {
            Logger::error('[Newsletter] subscribe lookup failed: ' . $e::class);
            Response::error('Newsletter service unavailable.', 503, 'SERVICE_UNAVAILABLE');
            return;
        }

        try {
            $this->subscribers->upsertPending(
                $email,
                $request->getIp(),
                $locale,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                date('Y-m-d H:i:s')
            );
        } catch (Throwable $e) {
            Logger::error('[Newsletter] subscribe failed: ' . $e::class);
            Response::error('Newsletter service unavailable.', 503, 'SERVICE_UNAVAILABLE');
            return;
        }

        try {
            $this->mailer->send($email, $mail['subject'], $mail['html'], $mail['text']);
        } catch (Throwable $e) {
            // Nunca dejar el email del suscriptor en los logs.
            Logger::error('[Newsletter] confirmation email failed: ' . str_replace($email, '[email]', $e->getMessage()));
            Response::error('Confirmation email could not be sent.', 503, 'NEWSLETTER_EMAIL_FAILED');
            return;
        }

        Response::success(['status' => 'pending', 'message' => 'confirmation_sent']);
    }
}
