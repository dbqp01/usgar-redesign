<?php
declare(strict_types=1);

namespace Tests\Unit\Features\Newsletter;

use App\Features\Newsletter\Domain\NewsletterContent;
use PHPUnit\Framework\TestCase;

/**
 * Los correos y paginas de la newsletter existen en los 4 idiomas y escapan
 * los datos que llegan por URL (email/token).
 */
final class NewsletterContentTest extends TestCase {
    /** @return array<string, array{string}> */
    public static function locales(): array {
        return ['en' => ['en'], 'es' => ['es'], 'fr' => ['fr'], 'pt' => ['pt']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function testEveryLocaleHasEmailAndPageCopy(string $locale): void {
        $mail = NewsletterContent::confirmationEmail($locale, 'https://usgarhoteles.com/api/newsletter/confirm?token=abc');
        self::assertNotSame('', $mail['subject']);
        self::assertStringContainsString('https://usgarhoteles.com/api/newsletter/confirm?token=abc', $mail['html']);
        self::assertStringContainsString('https://usgarhoteles.com/api/newsletter/confirm?token=abc', $mail['text']);

        ob_start();
        NewsletterContent::renderPage($locale, 'unsubscribe_ask', 200, ['email' => 'guest@example.com', 'token' => 'abc']);
        $html = (string)ob_get_clean();

        self::assertStringContainsString('lang="' . $locale . '"', $html);
        self::assertStringContainsString('name="token" value="abc"', $html);
        self::assertStringContainsString('guest@example.com', $html);
        self::assertStringNotContainsString(':email', $html, 'El placeholder debe sustituirse por el email escapado.');
    }

    public function testUnknownLocaleFallsBackToEnglish(): void {
        self::assertSame('en', NewsletterContent::normalizeLocale('de'));

        ob_start();
        NewsletterContent::renderPage('de', 'confirm_ok', 200);
        $html = (string)ob_get_clean();

        self::assertStringContainsString('Subscription confirmed', $html);
    }

    public function testPageEscapesInjectedValues(): void {
        ob_start();
        NewsletterContent::renderPage('en', 'unsubscribe_ask', 200, [
            'email' => '"><script>alert(1)</script>',
            'token' => '"><img src=x onerror=alert(1)>',
        ]);
        $html = (string)ob_get_clean();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
