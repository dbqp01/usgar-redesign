<?php
declare(strict_types=1);

namespace Tests\Unit\Features\Newsletter;

use App\Core\Config;
use App\Core\Request;
use App\Features\Newsletter\Actions\ConfirmNewsletterAction;
use App\Features\Newsletter\Actions\SubscribeNewsletterAction;
use App\Features\Newsletter\Actions\UnsubscribeNewsletterAction;
use App\Features\Newsletter\Domain\NewsletterSubscriberRepository;
use App\Features\Newsletter\Domain\NewsletterToken;
use App\Features\Shared\Ports\MailerPortInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FlowMailer implements MailerPortInterface {
    /** @var list<array{to:string,subject:string,html:string,text:string}> */
    public array $sent = [];

    public function send(string $to, string $subject, string $html, string $text): void {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text];
    }
}

final class InMemoryNewsletterRepository extends NewsletterSubscriberRepository {
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    public function __construct() {
    }

    public function ensureSchema(): void {
    }

    public function findByEmail(string $email): ?array {
        return $this->rows[$email] ?? null;
    }

    public function upsertPending(string $email, string $ip, string $locale, string $userAgent, string $consentAt): void {
        $this->rows[$email] = [
            'email' => $email,
            'status' => self::STATUS_PENDING,
            'locale' => $locale,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'consent_at' => $consentAt,
            'consent_version' => self::CONSENT_VERSION,
            'confirmed_at' => null,
            'unsubscribed_at' => null,
        ];
    }

    public function markConfirmed(string $email, string $confirmedAt): bool {
        if (($this->rows[$email]['status'] ?? '') !== self::STATUS_PENDING) {
            return false;
        }
        $this->rows[$email]['status'] = self::STATUS_CONFIRMED;
        $this->rows[$email]['confirmed_at'] = $confirmedAt;
        return true;
    }

    public function markUnsubscribed(string $email, string $unsubscribedAt): bool {
        if (!isset($this->rows[$email])) {
            return false;
        }
        $this->rows[$email]['status'] = self::STATUS_UNSUBSCRIBED;
        $this->rows[$email]['unsubscribed_at'] = $unsubscribedAt;
        return true;
    }
}

/**
 * Recorrido completo demostrable, sin BD ni SMTP reales:
 * alta con consentimiento -> correo -> confirmacion -> baja -> enlace viejo
 * inerte -> nueva alta con nuevo doble opt-in.
 */
final class NewsletterFlowTest extends TestCase {
    private string $previousSecret = '';
    private string $previousSiteUrl = '';
    private InMemoryNewsletterRepository $repo;
    private FlowMailer $mailer;

    protected function setUp(): void {
        $previous = Config::get('BOOKING_TOKEN_SECRET');
        $this->previousSecret = is_string($previous) ? $previous : '';
        $this->previousSiteUrl = (string)Config::get('SITE_URL', 'https://usgarhoteles.com');
        Config::set('BOOKING_TOKEN_SECRET', 'unit-test-newsletter-secret');
        Config::set('SITE_URL', 'https://usgarhoteles.test');
        $_SERVER['REMOTE_ADDR'] = '10.' . random_int(1, 254) . '.' . random_int(1, 254) . '.' . random_int(1, 254);
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit-Flow/1.0';
        $this->repo = new InMemoryNewsletterRepository();
        $this->mailer = new FlowMailer();
    }

    protected function tearDown(): void {
        Config::set('BOOKING_TOKEN_SECRET', $this->previousSecret);
        Config::set('SITE_URL', $this->previousSiteUrl);
        unset($_SERVER['HTTP_USER_AGENT']);
    }

    /** @return array{status:int,json:array<string,mixed>|null,raw:string} */
    private function call(object $action, Request $request): array {
        ob_start();
        try {
            $action($request);
        } finally {
            $raw = (string)ob_get_clean();
        }

        return ['status' => (int)http_response_code(), 'json' => json_decode($raw, true), 'raw' => $raw];
    }

    public function testFullDoubleOptInAndEffectiveUnsubscribeFlow(): void {
        $email = 'flow-' . bin2hex(random_bytes(4)) . '@example.com';
        $subscribe = new SubscribeNewsletterAction($this->repo, $this->mailer);

        $signup = $this->call($subscribe, new Request('POST', '/api/newsletter', ['content-type' => 'application/json'], [
            'email' => $email,
            'locale' => 'en',
            'consent' => true,
        ]));

        self::assertSame(200, $signup['status']);
        self::assertSame('pending', $this->repo->rows[$email]['status']);
        self::assertNotNull($this->repo->rows[$email]['consent_at']);
        self::assertSame($_SERVER['REMOTE_ADDR'], $this->repo->rows[$email]['ip']);
        self::assertCount(1, $this->mailer->sent);

        // El enlace del correo es el que confirma (no se fabrica el token a mano).
        self::assertSame(1, preg_match('/href="([^"]*\\/api\\/newsletter\\/confirm\\?[^"]*)"/', $this->mailer->sent[0]['html'], $match));
        parse_str((string)parse_url(html_entity_decode($match[1]), PHP_URL_QUERY), $query);
        self::assertSame($email, $query['email']);
        $confirmToken = (string)$query['token'];

        $confirm = $this->call(new ConfirmNewsletterAction($this->repo), new Request(
            'GET',
            '/api/newsletter/confirm?' . http_build_query(['email' => $email, 'token' => $confirmToken]),
            ['accept' => 'application/json']
        ));
        self::assertSame(200, $confirm['status']);
        self::assertSame('confirmed', $this->repo->rows[$email]['status']);
        self::assertNotNull($this->repo->rows[$email]['confirmed_at']);

        // GET de baja: solo pregunta, no muta (prefetch de correo seguro).
        $unsubscribeAction = new UnsubscribeNewsletterAction($this->repo);
        $unsubscribeToken = NewsletterToken::derive(NewsletterToken::UNSUBSCRIBE, $email);
        $ask = $this->call($unsubscribeAction, new Request(
            'GET',
            '/api/newsletter/unsubscribe?' . http_build_query(['email' => $email, 'token' => $unsubscribeToken]),
            ['accept' => 'text/html']
        ));
        self::assertSame(200, $ask['status']);
        self::assertStringContainsString('method="post"', strtolower($ask['raw']));
        self::assertSame('confirmed', $this->repo->rows[$email]['status'], 'GET no da de baja.');

        $left = $this->call($unsubscribeAction, new Request(
            'POST',
            '/api/newsletter/unsubscribe',
            ['accept' => 'application/json'],
            ['email' => $email, 'token' => $unsubscribeToken]
        ));
        self::assertSame(200, $left['status']);
        self::assertSame('unsubscribed', $this->repo->rows[$email]['status']);
        self::assertNotNull($this->repo->rows[$email]['unsubscribed_at']);

        // Un enlace de confirmacion viejo no puede revivir la baja.
        $stale = $this->call(new ConfirmNewsletterAction($this->repo), new Request(
            'GET',
            '/api/newsletter/confirm?' . http_build_query(['email' => $email, 'token' => $confirmToken]),
            ['accept' => 'application/json']
        ));
        self::assertSame(400, $stale['status']);
        self::assertSame('unsubscribed', $this->repo->rows[$email]['status']);

        // Volver a suscribirse exige un consentimiento nuevo y otro opt-in.
        $again = $this->call($subscribe, new Request('POST', '/api/newsletter', ['content-type' => 'application/json'], [
            'email' => $email,
            'locale' => 'en',
            'consent' => true,
        ]));
        self::assertSame(200, $again['status']);
        self::assertSame('pending', $this->repo->rows[$email]['status']);
        self::assertNull($this->repo->rows[$email]['confirmed_at']);
        self::assertCount(2, $this->mailer->sent, 'La nueva alta vuelve a pedir confirmacion.');
    }
}
