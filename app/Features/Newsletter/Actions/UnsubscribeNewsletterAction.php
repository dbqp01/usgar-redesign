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
 * Accion ADR: GET/POST /api/newsletter/unsubscribe
 *
 * GET solo muestra la confirmacion (los escaneres/prefetch de correo no dan de
 * baja a nadie); POST aplica la baja. Ambos exigen el token HMAC del proposito
 * "unsubscribe" ligado al email: un tercero no puede darse de baja por otro.
 */
class UnsubscribeNewsletterAction {
    public function __construct(private ?NewsletterSubscriberRepository $subscribers = null) {
    }

    public function __invoke(Request $request): void {
        $isPost = $request->getMethod() === 'POST';
        $source = $isPost ? ($request->getBody() ?? []) : [];
        $email = NewsletterToken::normalize((string)($source['email'] ?? $request->getQuery('email', '')));
        $token = (string)($source['token'] ?? $request->getQuery('token', ''));
        $html = $request->isHtml();

        if ($email === '' || !NewsletterToken::verify(NewsletterToken::UNSUBSCRIBE, $email, $token)) {
            $this->reject($html);
            return;
        }

        if ($this->subscribers === null) {
            $this->unavailable($html);
            return;
        }

        try {
            $subscriber = $this->subscribers->findByEmail($email);
            $locale = NewsletterContent::normalizeLocale((string)($subscriber['locale'] ?? 'en'));

            if (!$isPost) {
                NewsletterContent::renderPage($locale, 'unsubscribe_ask', 200, ['email' => $email, 'token' => $token]);
                return;
            }

            $this->subscribers->markUnsubscribed($email, date('Y-m-d H:i:s'));
        } catch (Throwable $e) {
            Logger::error('[Newsletter] unsubscribe failed: ' . $e::class);
            $this->unavailable($html);
            return;
        }

        if ($html) {
            NewsletterContent::renderPage($locale, 'unsubscribe_ok', 200);
        } else {
            Response::success(['status' => 'unsubscribed']);
        }
    }

    private function reject(bool $html): void {
        if ($html) {
            NewsletterContent::renderPage('en', 'invalid', 400);
            return;
        }

        Response::error('This unsubscribe link is not valid.', 400, 'INVALID_TOKEN');
    }

    private function unavailable(bool $html): void {
        if ($html) {
            NewsletterContent::renderPage('en', 'invalid', 503);
            return;
        }

        Response::error('Newsletter service unavailable.', 503, 'SERVICE_UNAVAILABLE');
    }
}
