<?php
declare(strict_types=1);

namespace App\Features\Newsletter\Actions;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Features\Newsletter\Domain\NewsletterContent;
use App\Features\Newsletter\Domain\NewsletterSubscriberRepository;
use App\Features\Newsletter\Domain\NewsletterToken;
use Throwable;

/**
 * Accion ADR: GET/POST /api/newsletter/confirm
 *
 * Doble opt-in en dos pasos: el enlace del correo solo llega al buzon suscrito
 * y el token esta ligado al email y al proposito "confirm". GET muestra el
 * formulario (un escaner o prefetch del correo no confirma a nadie); POST
 * aplica la confirmacion. Un enlace viejo no revive una baja (solo
 * transiciona desde 'pending').
 */
class ConfirmNewsletterAction {
    public function __construct(private ?NewsletterSubscriberRepository $subscribers = null) {
    }

    public function __invoke(Request $request): void {
        $isPost = $request->getMethod() === 'POST';
        $source = $isPost ? ($request->getBody() ?? []) : [];
        $email = NewsletterToken::normalize((string)($source['email'] ?? $request->getQuery('email', '')));
        $token = (string)($source['token'] ?? $request->getQuery('token', ''));
        $html = $request->isHtml();

        if ($email === '' || !NewsletterToken::verify(NewsletterToken::CONFIRM, $email, $token)) {
            $this->reject($html, 'INVALID_TOKEN');
            return;
        }

        if ($this->subscribers === null) {
            $this->unavailable($html);
            return;
        }

        try {
            $subscriber = $this->subscribers->findByEmail($email);
            if ($subscriber === null) {
                $this->reject($html, 'INVALID_TOKEN');
                return;
            }

            $status = (string)($subscriber['status'] ?? '');
            $locale = NewsletterContent::normalizeLocale((string)($subscriber['locale'] ?? 'en'));

            if ($status === NewsletterSubscriberRepository::STATUS_UNSUBSCRIBED) {
                $this->reject($html, 'INVALID_TOKEN', $locale);
                return;
            }

            if (!$isPost) {
                if (!$html) {
                    // Sin mutacion: el cliente JSON debe repetir por POST.
                    if ($status === NewsletterSubscriberRepository::STATUS_CONFIRMED) {
                        Response::success(['status' => 'confirmed']);
                    } else {
                        Response::success(['status' => 'pending', 'message' => 'confirmation_required']);
                    }
                    return;
                }

                if ($status === NewsletterSubscriberRepository::STATUS_CONFIRMED) {
                    NewsletterContent::renderPage($locale, 'confirm_ok', 200);
                } else {
                    NewsletterContent::renderPage($locale, 'confirm_ask', 200, ['email' => $email, 'token' => $token]);
                }
                return;
            }

            if ($status === NewsletterSubscriberRepository::STATUS_PENDING) {
                $this->subscribers->markConfirmed($email, date('Y-m-d H:i:s'));
            }
        } catch (Throwable $e) {
            Logger::error('[Newsletter] confirm failed: ' . $e::class);
            $this->unavailable($html);
            return;
        }

        if ($html) {
            NewsletterContent::renderPage($locale, 'confirm_ok', 200);
        } else {
            Response::success(['status' => 'confirmed']);
        }
    }

    private function reject(bool $html, string $code, string $locale = 'en'): void {
        if ($html) {
            NewsletterContent::renderPage($locale, 'invalid', 400);
            return;
        }

        Response::error('This confirmation link is not valid.', 400, $code);
    }

    private function unavailable(bool $html): void {
        if ($html) {
            NewsletterContent::renderPage('en', 'invalid', 503);
            return;
        }

        Response::error('Newsletter service unavailable.', 503, 'SERVICE_UNAVAILABLE');
    }
}
