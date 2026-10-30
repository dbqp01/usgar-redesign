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
 * Accion ADR: GET /api/newsletter/confirm?email=...&token=...
 *
 * Doble opt-in: el enlace solo llega al buzon suscrito. El token esta ligado
 * al email y al proposito "confirm"; sin el no se puede confirmar a un tercero.
 * Un enlace viejo no revive una baja (solo transiciona desde 'pending').
 */
class ConfirmNewsletterAction {
    public function __construct(private ?NewsletterSubscriberRepository $subscribers = null) {
    }

    public function __invoke(Request $request): void {
        $email = NewsletterToken::normalize((string)$request->getQuery('email', ''));
        $token = (string)$request->getQuery('token', '');
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
            $locale = (string)($subscriber['locale'] ?? 'en');

            if ($status === NewsletterSubscriberRepository::STATUS_UNSUBSCRIBED) {
                $this->reject($html, 'INVALID_TOKEN', $locale);
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
