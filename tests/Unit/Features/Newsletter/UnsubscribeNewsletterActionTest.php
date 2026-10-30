<?php
declare(strict_types=1);

namespace Tests\Unit\Features\Newsletter;

use App\Core\Config;
use App\Core\Request;
use App\Features\Newsletter\Actions\UnsubscribeNewsletterAction;
use App\Features\Newsletter\Domain\NewsletterSubscriberRepository;
use App\Features\Newsletter\Domain\NewsletterToken;
use PHPUnit\Framework\TestCase;

/**
 * Baja efectiva y segura contra terceros:
 * - GET solo muestra la confirmacion (un prefetch de correo no da de baja);
 * - POST aplica la baja;
 * - ambas exigen token HMAC del proposito "unsubscribe" ligado al email.
 */
final class UnsubscribeNewsletterActionTest extends TestCase {
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
        bool $emailInBody = false
    ): array {
        $action = new UnsubscribeNewsletterAction($repo);
        $headers = $html ? ['accept' => 'text/html'] : ['accept' => 'application/json'];
        $body = null;
        $path = '/api/newsletter/unsubscribe';

        if ($method === 'GET') {
            $path .= '?' . http_build_query(['email' => $email, 'token' => $token]);
        } else {
            $body = ['email' => $email, 'token' => $token];
            if ($emailInBody) {
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
        $email = 'reader@example.com';
        $token = NewsletterToken::derive(NewsletterToken::UNSUBSCRIBE, $email);
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willReturn(['status' => 'confirmed', 'locale' => 'es']);
        $repo->expects($this->never())->method('markUnsubscribed');

        $result = $this->invoke($repo, 'GET', $email, $token, true);

        self::assertSame(200, $result['status']);
        self::assertStringContainsString('action="/api/newsletter/unsubscribe"', $result['raw']);
        self::assertStringContainsString('method="post"', strtolower($result['raw']));
        self::assertStringContainsString('name="token" value="' . $token . '"', $result['raw']);
        self::assertStringContainsString('name="email" value="' . $email . '"', $result['raw']);
        self::assertStringContainsString('Darte de baja', $result['raw']);
    }

    public function testPostWithSignedTokenAppliesUnsubscribeWithTimestamp(): void {
        $email = 'reader@example.com';
        $token = NewsletterToken::derive(NewsletterToken::UNSUBSCRIBE, $email);
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willReturn(['status' => 'confirmed', 'locale' => 'es']);

        $unsubscribedAt = null;
        $repo->expects($this->once())->method('markUnsubscribed')
            ->willReturnCallback(function (string $storedEmail, string $at) use (&$unsubscribedAt, $email): bool {
                self::assertSame($email, $storedEmail);
                $unsubscribedAt = $at;
                return true;
            });

        $result = $this->invoke($repo, 'POST', $email, $token, true, true);

        self::assertSame(200, $result['status']);
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', (string)$unsubscribedAt);
        self::assertStringContainsString('Baja confirmada', $result['raw']);
    }

    public function testThirdPartyWithoutTokenCannotUnsubscribe(): void {
        $email = 'reader@example.com';
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->expects($this->never())->method('findByEmail');
        $repo->expects($this->never())->method('markUnsubscribed');

        $result = $this->invoke($repo, 'POST', $email, str_repeat('0', 64), true);

        self::assertSame(400, $result['status']);
        self::assertStringContainsString('Link not valid', $result['raw']);
    }

    public function testTokenForAnotherEmailCannotUnsubscribe(): void {
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->expects($this->never())->method('markUnsubscribed');

        $result = $this->invoke(
            $repo,
            'POST',
            'victim@example.com',
            NewsletterToken::derive(NewsletterToken::UNSUBSCRIBE, 'attacker@example.com'),
            false
        );

        self::assertSame(400, $result['status']);
        self::assertSame('INVALID_TOKEN', $result['json']['error']['code']);
    }

    public function testConfirmationTokenCannotBeUsedToUnsubscribe(): void {
        $email = 'reader@example.com';
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->expects($this->never())->method('markUnsubscribed');

        $result = $this->invoke($repo, 'POST', $email, NewsletterToken::derive(NewsletterToken::CONFIRM, $email), false);

        self::assertSame(400, $result['status']);
        self::assertSame('INVALID_TOKEN', $result['json']['error']['code']);
    }

    public function testJsonClientGetsEffectiveUnsubscribeContract(): void {
        $email = 'reader@example.com';
        $token = NewsletterToken::derive(NewsletterToken::UNSUBSCRIBE, $email);
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willReturn(['status' => 'confirmed', 'locale' => 'pt']);
        $repo->expects($this->once())->method('markUnsubscribed');

        $result = $this->invoke($repo, 'POST', $email, $token, false);

        self::assertSame(200, $result['status']);
        self::assertTrue($result['json']['success']);
        self::assertSame('unsubscribed', $result['json']['status']);
    }

    public function testAlreadyUnsubscribedIsIdempotent(): void {
        $email = 'reader@example.com';
        $token = NewsletterToken::derive(NewsletterToken::UNSUBSCRIBE, $email);
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willReturn(['status' => 'unsubscribed', 'locale' => 'en']);
        $repo->expects($this->once())->method('markUnsubscribed');

        $result = $this->invoke($repo, 'POST', $email, $token, false);

        self::assertSame(200, $result['status']);
        self::assertSame('unsubscribed', $result['json']['status']);
    }

    public function testDatabaseOfflineReturnsServiceUnavailable(): void {
        $email = 'offline@example.com';
        $token = NewsletterToken::derive(NewsletterToken::UNSUBSCRIBE, $email);

        $result = $this->invoke(null, 'POST', $email, $token, false);

        self::assertSame(503, $result['status']);
        self::assertSame('SERVICE_UNAVAILABLE', $result['json']['error']['code']);
    }
}
