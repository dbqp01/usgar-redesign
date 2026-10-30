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
 * GET /api/newsletter/confirm: solo un token firmado para ESE email y ESE
 * proposito confirma la suscripcion. El doble opt-in es la prueba demostrable.
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
    private function invoke(?NewsletterSubscriberRepository $repo, string $email, string $token, bool $html): array {
        $action = new ConfirmNewsletterAction($repo);
        $path = '/api/newsletter/confirm?' . http_build_query(['email' => $email, 'token' => $token]);
        $headers = $html ? ['accept' => 'text/html'] : ['accept' => 'application/json'];

        ob_start();
        try {
            $action(new Request('GET', $path, $headers));
        } finally {
            $raw = (string)ob_get_clean();
        }

        return [
            'status' => (int)http_response_code(),
            'json' => json_decode($raw, true),
            'raw' => $raw,
        ];
    }

    public function testPendingSubscriberIsConfirmedWithTimestampAndLocalizedPage(): void {
        $email = 'marie@example.com';
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->with($email)->willReturn(['status' => 'pending', 'locale' => 'fr']);

        $confirmedAt = null;
        $repo->expects($this->once())->method('markConfirmed')
            ->willReturnCallback(function (string $storedEmail, string $at) use (&$confirmedAt, $email): bool {
                self::assertSame($email, $storedEmail);
                $confirmedAt = $at;
                return true;
            });

        $result = $this->invoke($repo, $email, NewsletterToken::derive(NewsletterToken::CONFIRM, $email), true);

        self::assertSame(200, $result['status']);
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', (string)$confirmedAt);
        self::assertStringContainsString('Abonnement confirmé', $result['raw'], 'La pagina usa el idioma guardado en la suscripcion.');
        self::assertStringNotContainsString($email, $result['raw'], 'La pagina no repite el email en el HTML.');
    }

    public function testAlreadyConfirmedLinkIsIdempotentAndDoesNotWriteAgain(): void {
        $email = 'repeat@example.com';
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willReturn(['status' => 'confirmed', 'locale' => 'en']);
        $repo->expects($this->never())->method('markConfirmed');

        $result = $this->invoke($repo, $email, NewsletterToken::derive(NewsletterToken::CONFIRM, $email), false);

        self::assertSame(200, $result['status']);
        self::assertTrue($result['json']['success']);
        self::assertSame('confirmed', $result['json']['status']);
    }

    public function testTamperedTokenCannotConfirm(): void {
        $email = 'victim@example.com';
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->expects($this->never())->method('findByEmail');
        $repo->expects($this->never())->method('markConfirmed');

        $result = $this->invoke($repo, $email, str_repeat('f', 64), true);

        self::assertSame(400, $result['status']);
        self::assertStringContainsString('Link not valid', $result['raw']);
    }

    public function testUnsubscribeTokenCannotConfirmASubscription(): void {
        $email = 'wrong-purpose@example.com';
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->expects($this->never())->method('markConfirmed');

        $result = $this->invoke($repo, $email, NewsletterToken::derive(NewsletterToken::UNSUBSCRIBE, $email), false);

        self::assertSame(400, $result['status']);
        self::assertSame('INVALID_TOKEN', $result['json']['error']['code']);
    }

    public function testUnknownEmailGetsGenericInvalidLink(): void {
        $email = 'gone@example.com';
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willReturn(null);
        $repo->expects($this->never())->method('markConfirmed');

        $result = $this->invoke($repo, $email, NewsletterToken::derive(NewsletterToken::CONFIRM, $email), false);

        self::assertSame(400, $result['status']);
        self::assertSame('INVALID_TOKEN', $result['json']['error']['code']);
    }

    public function testStaleConfirmationCannotResurrectAnUnsubscribedAddress(): void {
        $email = 'left@example.com';
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willReturn(['status' => 'unsubscribed', 'locale' => 'en']);
        $repo->expects($this->never())->method('markConfirmed');

        $result = $this->invoke($repo, $email, NewsletterToken::derive(NewsletterToken::CONFIRM, $email), true);

        self::assertSame(400, $result['status']);
        self::assertStringContainsString('Link not valid', $result['raw']);
    }

    public function testDatabaseOfflineReturnsServiceUnavailable(): void {
        $email = 'offline@example.com';

        $result = $this->invoke(null, $email, NewsletterToken::derive(NewsletterToken::CONFIRM, $email), false);

        self::assertSame(503, $result['status']);
        self::assertSame('SERVICE_UNAVAILABLE', $result['json']['error']['code']);
    }
}
