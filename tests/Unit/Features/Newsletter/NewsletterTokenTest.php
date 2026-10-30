<?php
declare(strict_types=1);

namespace Tests\Unit\Features\Newsletter;

use App\Core\Config;
use App\Core\HttpException;
use App\Features\Newsletter\Domain\NewsletterToken;
use PHPUnit\Framework\TestCase;

/**
 * El token de la newsletter debe ser imposible de fabricar sin el secreto y
 * no debe servir para otro proposito (confirmar != darse de baja).
 */
final class NewsletterTokenTest extends TestCase {
    private string $previousSecret = '';

    protected function setUp(): void {
        $previous = Config::get('BOOKING_TOKEN_SECRET');
        $this->previousSecret = is_string($previous) ? $previous : '';
        Config::set('BOOKING_TOKEN_SECRET', 'unit-test-newsletter-secret');
    }

    protected function tearDown(): void {
        Config::set('BOOKING_TOKEN_SECRET', $this->previousSecret);
    }

    public function testDeriveIsDeterministicSha256Hex(): void {
        $first = NewsletterToken::derive(NewsletterToken::CONFIRM, 'guest@example.com');
        $second = NewsletterToken::derive(NewsletterToken::CONFIRM, 'guest@example.com');

        self::assertSame($first, $second);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $first);
    }

    public function testConfirmAndUnsubscribeTokensAreNotInterchangeable(): void {
        $confirm = NewsletterToken::derive(NewsletterToken::CONFIRM, 'guest@example.com');
        $unsubscribe = NewsletterToken::derive(NewsletterToken::UNSUBSCRIBE, 'guest@example.com');

        self::assertNotSame($confirm, $unsubscribe);
        self::assertTrue(NewsletterToken::verify(NewsletterToken::CONFIRM, 'guest@example.com', $confirm));
        self::assertFalse(NewsletterToken::verify(NewsletterToken::UNSUBSCRIBE, 'guest@example.com', $confirm));
        self::assertFalse(NewsletterToken::verify(NewsletterToken::CONFIRM, 'guest@example.com', $unsubscribe));
    }

    public function testVerifyRejectsTamperedTokenOtherEmailAndEmptyToken(): void {
        $token = NewsletterToken::derive(NewsletterToken::UNSUBSCRIBE, 'guest@example.com');

        self::assertFalse(NewsletterToken::verify(NewsletterToken::UNSUBSCRIBE, 'guest@example.com', $token . '0'));
        self::assertFalse(NewsletterToken::verify(NewsletterToken::UNSUBSCRIBE, 'other@example.com', $token));
        self::assertFalse(NewsletterToken::verify(NewsletterToken::UNSUBSCRIBE, 'guest@example.com', ''));
        self::assertFalse(NewsletterToken::verify(NewsletterToken::UNSUBSCRIBE, 'guest@example.com', 'not-hex'));
    }

    public function testEmailNormalizationAvoidsCaseAndWhitespaceBypass(): void {
        $token = NewsletterToken::derive(NewsletterToken::UNSUBSCRIBE, '  Guest@Example.COM ');

        self::assertSame('guest@example.com', NewsletterToken::normalize('  Guest@Example.COM '));
        self::assertTrue(NewsletterToken::verify(NewsletterToken::UNSUBSCRIBE, 'guest@example.com', $token));
    }

    public function testMissingSecretFailsClosed(): void {
        Config::set('BOOKING_TOKEN_SECRET', '');
        Config::set('CRON_SECRET', '');

        try {
            NewsletterToken::derive(NewsletterToken::CONFIRM, 'guest@example.com');
            self::fail('Sin secreto, derive() debe lanzar HttpException.');
        } catch (HttpException $e) {
            self::assertSame(503, $e->getStatusCode());
        }

        self::assertFalse(
            NewsletterToken::verify(NewsletterToken::CONFIRM, 'guest@example.com', str_repeat('a', 64)),
            'verify() no puede aceptar tokens si el secreto falta.'
        );
    }
}
