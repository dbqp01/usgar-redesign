<?php
declare(strict_types=1);

namespace Tests\Unit\Features\Newsletter;

use App\Core\Config;
use App\Core\Request;
use App\Features\Newsletter\Actions\SubscribeNewsletterAction;
use App\Features\Newsletter\Domain\NewsletterSubscriberRepository;
use App\Features\Newsletter\Domain\NewsletterToken;
use App\Features\Shared\Ports\MailerPortInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RecordingMailer implements MailerPortInterface {
    /** @var list<array{to:string,subject:string,html:string,text:string}> */
    public array $sent = [];
    public bool $fail = false;

    public function send(string $to, string $subject, string $html, string $text): void {
        if ($this->fail) {
            throw new RuntimeException('SMTP unavailable');
        }
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text];
    }
}

/**
 * Contrato de POST /api/newsletter:
 * - consentimiento explicito (boolean true), nunca implicito ni "on"/"1";
 * - doble opt-in: se guarda evidencia y se envia confirmacion, NO se suscribe;
 * - respuesta identica para alta nueva y ya confirmada (anti-enumeracion);
 * - sin fuga del email en la respuesta.
 */
final class SubscribeNewsletterActionTest extends TestCase {
    private string $previousSecret = '';
    private RecordingMailer $mailer;
    private string $ip;

    protected function setUp(): void {
        $previous = Config::get('BOOKING_TOKEN_SECRET');
        $this->previousSecret = is_string($previous) ? $previous : '';
        Config::set('BOOKING_TOKEN_SECRET', 'unit-test-newsletter-secret');
        $this->mailer = new RecordingMailer();
        // IP unica por test: el rate limiter persiste en data/limits entre corridas.
        $this->ip = '10.' . random_int(1, 254) . '.' . random_int(1, 254) . '.' . random_int(1, 254);
        $_SERVER['REMOTE_ADDR'] = $this->ip;
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit/1.0';
    }

    protected function tearDown(): void {
        Config::set('BOOKING_TOKEN_SECRET', $this->previousSecret);
        unset($_SERVER['HTTP_USER_AGENT']);
    }

    /** @return array{status:int,json:array<string,mixed>|null,raw:string} */
    private function invoke(?NewsletterSubscriberRepository $repo, array $body): array {
        $action = new SubscribeNewsletterAction($repo, $this->mailer);

        ob_start();
        try {
            $action(new Request('POST', '/api/newsletter', ['content-type' => 'application/json'], $body));
        } finally {
            $raw = (string)ob_get_clean();
        }

        return [
            'status' => (int)http_response_code(),
            'json' => json_decode($raw, true),
            'raw' => $raw,
        ];
    }

    private function uniqueEmail(): string {
        return 'lead-' . bin2hex(random_bytes(6)) . '@example.com';
    }

    public function testInvalidEmailIsRejectedBeforeTouchStore(): void {
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->expects($this->never())->method('findByEmail');
        $repo->expects($this->never())->method('upsertPending');

        $result = $this->invoke($repo, ['email' => 'not-an-email', 'locale' => 'en', 'consent' => true]);

        self::assertSame(422, $result['status']);
        self::assertSame('INVALID_EMAIL', $result['json']['error']['code']);
        self::assertCount(0, $this->mailer->sent);
    }

    #[DataProvider('nonBooleanConsent')]
    public function testConsentMustBeAnExplicitBooleanTrue(mixed $consent): void {
        $email = $this->uniqueEmail();
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->expects($this->never())->method('upsertPending');

        $result = $this->invoke($repo, ['email' => $email, 'locale' => 'en', 'consent' => $consent]);

        self::assertSame(422, $result['status']);
        self::assertSame('CONSENT_REQUIRED', $result['json']['error']['code']);
        self::assertCount(0, $this->mailer->sent, 'Sin consentimiento explicito no puede salir ningun correo.');
    }

    /** @return array<string, array{mixed}> */
    public static function nonBooleanConsent(): array {
        return [
            'missing' => [null],
            'false' => [false],
            'string true' => ['true'],
            'one' => [1],
            'checkbox on' => ['on'],
        ];
    }

    public function testExplicitConsentStoresProvableEvidenceAndSendsConfirmationOnly(): void {
        $email = $this->uniqueEmail();
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->with($email)->willReturn(null);

        $captured = [];
        $repo->expects($this->once())->method('upsertPending')
            ->willReturnCallback(function (string $storedEmail, string $ip, string $locale, string $userAgent, string $consentAt) use (&$captured): void {
                $captured = compact('storedEmail', 'ip', 'locale', 'userAgent', 'consentAt');
            });

        $result = $this->invoke($repo, ['email' => '  ' . strtoupper($email) . ' ', 'locale' => 'es', 'consent' => true]);

        self::assertSame(200, $result['status']);
        self::assertTrue($result['json']['success']);
        self::assertSame('pending', $result['json']['status']);
        self::assertStringNotContainsString($email, $result['raw'], 'La respuesta no debe confirmar si el email existe.');

        self::assertSame($email, $captured['storedEmail'], 'El email se normaliza antes de guardarse.');
        self::assertSame($this->ip, $captured['ip'], 'La IP del consentimiento queda como prueba.');
        self::assertSame('es', $captured['locale']);
        self::assertSame('PHPUnit/1.0', $captured['userAgent']);
        self::assertMatchesRegularExpression(
            '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/',
            $captured['consentAt'],
            'consent_at debe registrarse con fecha real.'
        );

        self::assertCount(1, $this->mailer->sent);
        $mail = $this->mailer->sent[0];
        self::assertSame($email, $mail['to']);
        self::assertStringContainsString('/api/newsletter/confirm?', $mail['html']);
        self::assertStringContainsString('email=' . rawurlencode($email), $mail['html']);
        self::assertStringContainsString('token=' . NewsletterToken::derive(NewsletterToken::CONFIRM, $email), $mail['html']);
        self::assertStringContainsString('no te suscribiremos', $mail['text'], 'El aviso debe explicar que ignorar el correo no suscribe.');
    }

    public function testUnknownLocaleFallsBackToEnglish(): void {
        $email = $this->uniqueEmail();
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willReturn(null);

        $locale = null;
        $repo->method('upsertPending')->willReturnCallback(function (...$args) use (&$locale): void {
            $locale = $args[2];
        });

        $this->invoke($repo, ['email' => $email, 'locale' => '../xx', 'consent' => true]);

        self::assertSame('en', $locale);
    }

    /**
     * Suscriptor historico: status 'confirmed' pero sin consent_at (migracion
     * legacy). No hay evidencia de consentimiento, asi que debe poder
     * reinscribirse y volver a confirmar por doble opt-in.
     */
    public function testLegacyConfirmedSubscriberWithoutConsentEvidenceCanReOptInThroughDoubleOptIn(): void {
        $email = $this->uniqueEmail();
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willReturn([
            'status' => 'confirmed',
            'consent_at' => null,
            'consent_version' => NewsletterSubscriberRepository::CONSENT_LEGACY,
            'locale' => 'es',
        ]);

        $captured = [];
        $repo->expects($this->once())->method('upsertPending')
            ->willReturnCallback(function (string $storedEmail, string $ip, string $locale, string $userAgent, string $consentAt) use (&$captured): void {
                $captured = compact('storedEmail', 'ip', 'locale', 'userAgent', 'consentAt');
            });

        $result = $this->invoke($repo, ['email' => $email, 'locale' => 'es', 'consent' => true]);

        self::assertSame(200, $result['status']);
        self::assertSame('pending', $result['json']['status'], 'Re-opt-in indistinguible de un alta nueva.');
        self::assertSame('confirmation_sent', $result['json']['message']);
        self::assertStringNotContainsString($email, $result['raw']);

        self::assertSame($email, $captured['storedEmail']);
        self::assertSame('es', $captured['locale']);
        self::assertMatchesRegularExpression(
            '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/',
            $captured['consentAt'],
            'El re-opt-in debe registrar consentimiento nuevo con fecha real.'
        );

        self::assertCount(1, $this->mailer->sent, 'Debe enviarse la confirmacion; no se le da por suscrito.');
        self::assertStringContainsString(
            '/api/newsletter/confirm?email=' . rawurlencode($email),
            $this->mailer->sent[0]['html']
        );
    }

    public function testAlreadyConfirmedSubscriberDoesNotReceiveAnotherEmailNorLeaksStatus(): void {
        $email = $this->uniqueEmail();
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willReturn(['status' => 'confirmed', 'consent_at' => '2026-10-01 09:00:00', 'locale' => 'en']);
        $repo->expects($this->never())->method('upsertPending');

        $result = $this->invoke($repo, ['email' => $email, 'locale' => 'en', 'consent' => true]);

        self::assertSame(200, $result['status']);
        self::assertTrue($result['json']['success']);
        self::assertSame('pending', $result['json']['status'], 'Respuesta indistinguible de un alta nueva.');
        self::assertCount(0, $this->mailer->sent);
    }

    public function testUnsubscribedSubscriberCanExplicitlyResubscribeThroughDoubleOptIn(): void {
        $email = $this->uniqueEmail();
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willReturn(['status' => 'unsubscribed', 'locale' => 'en']);
        $repo->expects($this->once())->method('upsertPending');

        $result = $this->invoke($repo, ['email' => $email, 'locale' => 'en', 'consent' => true]);

        self::assertSame(200, $result['status']);
        self::assertCount(1, $this->mailer->sent, 'La nueva alta exige confirmar otra vez.');
    }

    public function testMailFailureReturnsServiceUnavailableAndNeverFakesSuccess(): void {
        $email = $this->uniqueEmail();
        $this->mailer->fail = true;
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willReturn(null);
        $repo->expects($this->once())->method('upsertPending');

        $result = $this->invoke($repo, ['email' => $email, 'locale' => 'en', 'consent' => true]);

        self::assertSame(503, $result['status']);
        self::assertFalse($result['json']['success']);
        self::assertSame('NEWSLETTER_EMAIL_FAILED', $result['json']['error']['code']);
        self::assertStringNotContainsString($email, $result['raw']);
    }

    public function testLookupFailureReturnsServiceUnavailableWithoutMail(): void {
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willThrowException(new RuntimeException('database down'));

        $result = $this->invoke($repo, ['email' => $this->uniqueEmail(), 'locale' => 'en', 'consent' => true]);

        self::assertSame(503, $result['status']);
        self::assertSame('SERVICE_UNAVAILABLE', $result['json']['error']['code']);
        self::assertCount(0, $this->mailer->sent);
    }

    public function testRateLimitStopsConfirmationEmailAbuse(): void {
        $email = $this->uniqueEmail();
        $repo = $this->createMock(NewsletterSubscriberRepository::class);
        $repo->method('findByEmail')->willReturn(['status' => 'confirmed', 'locale' => 'en']);

        for ($i = 0; $i < 5; $i++) {
            self::assertSame(200, $this->invoke($repo, ['email' => $email, 'locale' => 'en', 'consent' => true])['status']);
        }

        $result = $this->invoke($repo, ['email' => $email, 'locale' => 'en', 'consent' => true]);

        self::assertSame(429, $result['status']);
        self::assertSame('RATE_LIMITED', $result['json']['error']['code']);
    }

    public function testDatabaseOfflineReturnsServiceUnavailable(): void {
        $result = $this->invoke(null, ['email' => $this->uniqueEmail(), 'locale' => 'en', 'consent' => true]);

        self::assertSame(503, $result['status']);
        self::assertSame('SERVICE_UNAVAILABLE', $result['json']['error']['code']);
        self::assertCount(0, $this->mailer->sent);
    }
}
