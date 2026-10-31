<?php
declare(strict_types=1);

namespace Tests\Unit\Features\Newsletter;

use App\Core\Config;
use App\Core\Request;
use App\Features\Newsletter\Actions\ConfirmNewsletterAction;
use App\Features\Newsletter\Domain\NewsletterSubscriberRepository;
use App\Features\Newsletter\Domain\NewsletterToken;
use PHPUnit\Framework\TestCase;

/**
 * Alta en dos pasos: GET solo muestra la confirmacion (un escaner o prefetch
 * del correo no confirma a nadie) y POST aplica el doble opt-in. Ambos exigen
 * el token HMAC del proposito "confirm" ligado a ESE email.
 */
final class ConfirmNewsletterActionTest extends TestCase {
    private string $previousSecret = '';

    protected function setUp(): void {
        $previous = Config::get('BOOKING_TOKEN_SECRET');
        $this->previousSecret = is_string($previous) ? $previous : '';
        Config::set('BOOKING_TOKEN_SECRET', 'unit-test-newsletter-secret');
    }

    protected function tearDown(): void {
        Config::set('BOOKING_TOKEN_SECRET', $this->previousSecret);
    }

    /** @return array{status:int,json:array<string,mixed>|null,raw:string} */
    private function invoke(
        ?NewsletterSubscriberRepository $repo,
        string $method,
        string $email,
        string $token,
        bool $html,
        bool $formPost = false
    ): array {
        $action = new ConfirmNewsletterAction($repo);
        $headers = $html ? ['accept' => 'text/html'] : ['accept' => 'application/json'];
        $body = null;
        $path = '/api/newsletter/confirm';

        if ($method === 'GET') {
            $path .= '?' . http_build_query(['email' => $email, 'token' => $token]);
        } else {
            $body = ['email' => $email, 'token' => $token];
            if ($formPost) {
                $headers['content-type'] = 'application/x-www-form-urlencoded';
            }
        }

        ob_start();
        try {
            $action(new Request($method, $path, $headers, $body));
        } finally {
            $raw = (string)ob_get_clean();
        }

        return [
            'status' => (int)http_response_code(),
            'json' => json_decode($raw, true),
            'raw' => $raw,
        ];
    }

    public function testGetOnlyAsksForConfirmationAndNeverMutates(): void {
        $email = 'marie@example.com';
        $token = NewsletterToken::derive(NewsletterToken::CONFIRM, $email);
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->with($email)->willReturn(['status' => 'pending', 'locale' => 'fr']);
        $repo->expects($this->never())->method('markConfirmed');

        $result = $this->invoke($repo, 'GET', $email, $token, true);

        self::assertSame(200, $result['status']);
        self::assertStringContainsString('action="/api/newsletter/confirm"', $result['raw']);
        self::assertStringContainsString('method="post"', strtolower($result['raw']));
        self::assertStringContainsString('name="token" value="' . $token . '"', $result['raw']);
        self::assertStringContainsString('name="email" value="' . $email . '"', $result['raw']);
        self::assertStringContainsString('Confirmer l&#039;abonnement', $result['raw'], 'El formulario usa el idioma guardado.');
    }

    public function testPostWithSignedTokenConfirmsPendingSubscriber(): void {
        $email = 'marie@example.com';
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willReturn(['status' => 'pending', 'locale' => 'fr']);

        $confirmedAt = null;
        $repo->expects($this->once())->method('markConfirmed')
            ->willReturnCallback(function (string $storedEmail, string $at) use (&$confirmedAt, $email): bool {
                self::assertSame($email, $storedEmail);
                $confirmedAt = $at;
                return true;
            });

        $result = $this->invoke($repo, 'POST', $email, NewsletterToken::derive(NewsletterToken::CONFIRM, $email), true, true);

        self::assertSame(200, $result['status']);
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', (string)$confirmedAt);
        self::assertStringContainsString('Abonnement confirmé', $result['raw']);
        self::assertStringNotContainsString($email, $result['raw'], 'La pagina no repite el email en el HTML.');
    }

    public function testGetOnAlreadyConfirmedSubscriberShowsSuccessWithoutWriting(): void {
        $email = 'repeat@example.com';
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willReturn(['status' => 'confirmed', 'locale' => 'en']);
        $repo->expects($this->never())->method('markConfirmed');

        $result = $this->invoke($repo, 'GET', $email, NewsletterToken::derive(NewsletterToken::CONFIRM, $email), true);

        self::assertSame(200, $result['status']);
        self::assertStringContainsString('Subscription confirmed', $result['raw']);
        self::assertStringNotContainsString('method="post"', strtolower($result['raw']));
    }

    public function testPostOnAlreadyConfirmedIsIdempotentAndDoesNotWrite(): void {
        $email = 'repeat@example.com';
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willReturn(['status' => 'confirmed', 'locale' => 'en']);
        $repo->expects($this->never())->method('markConfirmed');

        $result = $this->invoke($repo, 'POST', $email, NewsletterToken::derive(NewsletterToken::CONFIRM, $email), false);

        self::assertSame(200, $result['status']);
        self::assertTrue($result['json']['success']);
        self::assertSame('confirmed', $result['json']['status']);
    }

    public function testJsonGetAsksForPostWithoutMutating(): void {
        $email = 'marie@example.com';
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willReturn(['status' => 'pending', 'locale' => 'en']);
        $repo->expects($this->never())->method('markConfirmed');

        $result = $this->invoke($repo, 'GET', $email, NewsletterToken::derive(NewsletterToken::CONFIRM, $email), false);

        self::assertSame(200, $result['status']);
        self::assertSame('confirmation_required', $result['json']['message']);
        self::assertSame('pending', $result['json']['status']);
    }

    public function testTamperedTokenCannotConfirm(): void {
        $email = 'victim@example.com';
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->expects($this->never())->method('findByEmail');
        $repo->expects($this->never())->method('markConfirmed');

        $result = $this->invoke($repo, 'POST', $email, str_repeat('f', 64), true);

        self::assertSame(400, $result['status']);
        self::assertStringContainsString('Link not valid', $result['raw']);
    }

    public function testUnsubscribeTokenCannotConfirmASubscription(): void {
        $email = 'wrong-purpose@example.com';
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->expects($this->never())->method('markConfirmed');

        $result = $this->invoke($repo, 'POST', $email, NewsletterToken::derive(NewsletterToken::UNSUBSCRIBE, $email), false);

        self::assertSame(400, $result['status']);
        self::assertSame('INVALID_TOKEN', $result['json']['error']['code']);
    }

    public function testUnknownEmailGetsGenericInvalidLink(): void {
        $email = 'gone@example.com';
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willReturn(null);
        $repo->expects($this->never())->method('markConfirmed');

        $result = $this->invoke($repo, 'POST', $email, NewsletterToken::derive(NewsletterToken::CONFIRM, $email), false);

        self::assertSame(400, $result['status']);
        self::assertSame('INVALID_TOKEN', $result['json']['error']['code']);
    }

    public function testStaleConfirmationCannotResurrectAnUnsubscribedAddress(): void {
        $email = 'left@example.com';
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willReturn(['status' => 'unsubscribed', 'locale' => 'en']);
        $repo->expects($this->never())->method('markConfirmed');

        $result = $this->invoke($repo, 'POST', $email, NewsletterToken::derive(NewsletterToken::CONFIRM, $email), true);

        self::assertSame(400, $result['status']);
        self::assertStringContainsString('Link not valid', $result['raw']);
    }

    public function testGetOnUnsubscribedAddressShowsNoConfirmationForm(): void {
        $email = 'left@example.com';
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willReturn(['status' => 'unsubscribed', 'locale' => 'en']);
        $repo->expects($this->never())->method('markConfirmed');

        $result = $this->invoke($repo, 'GET', $email, NewsletterToken::derive(NewsletterToken::CONFIRM, $email), true);

        self::assertSame(400, $result['status']);
        self::assertStringNotContainsString('method="post"', strtolower($result['raw']));
    }

    public function testDatabaseOfflineReturnsServiceUnavailable(): void {
        $email = 'offline@example.com';

        $result = $this->invoke(null, 'POST', $email, NewsletterToken::derive(NewsletterToken::CONFIRM, $email), false);

        self::assertSame(503, $result['status']);
        self::assertSame('SERVICE_UNAVAILABLE', $result['json']['error']['code']);
    }
}
