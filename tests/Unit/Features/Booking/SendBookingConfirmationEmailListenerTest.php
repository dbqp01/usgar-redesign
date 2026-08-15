<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Booking;

use PHPUnit\Framework\TestCase;
use App\Features\Booking\Domain\Events\BookingPaidEvent;
use App\Features\Booking\Domain\Listeners\SendBookingConfirmationEmailListener;
use App\Features\Shared\Ports\MailerPortInterface;

/**
 * Tests del listener del voucher (2026-08-14): el email de confirmación se
 * envía al huésped cuando el pago se confirma, en su idioma, con los montos
 * congelados del hold. Hermético: MailerPortInterface mockeado (nunca SMTP).
 *
 * Reglas verificadas:
 *  - SMTP_HOST vacío -> fail-soft: NO llama al mailer (el outbox no reintenta).
 *  - Sin email de huésped -> NO llama al mailer.
 *  - Con SMTP configurado -> envía 1 correo con subject + código + monto PEN
 *    + equivalencia USD + nota de traslado (si special_requests).
 *  - Idioma: es -> subject en español (guest_data.locale).
 */
final class SendBookingConfirmationEmailListenerTest extends TestCase {
    /** @var array<int,array{to:string,subject:string,html:string,text:string}> */
    private array $sent = [];

    private function buildMailer(): MailerPortInterface {
        return new class($this->sent) implements MailerPortInterface {
            /** @param array<int,array{to:string,subject:string,html:string,text:string}> $sent */
            public function __construct(private array &$sent) {}

            public function send(string $to, string $subject, string $html, string $text): void {
                $this->sent[] = [
                    'to'      => $to,
                    'subject' => $subject,
                    'html'    => $html,
                    'text'    => $text,
                ];
            }
        };
    }

    private function setSmtpHost(string $value): void {
        putenv('SMTP_HOST=' . $value);
        $_ENV['SMTP_HOST'] = $value;
    }

    protected function tearDown(): void {
        putenv('SMTP_HOST');
        unset($_ENV['SMTP_HOST']);
        $this->sent = [];
    }

    private function buildEvent(array $guestData = [], array $roomData = []): BookingPaidEvent {
        return BookingPaidEvent::fromHold(
            'CART-42',
            'PAY-7',
            [
                'price_snapshot'          => 150.0,
                'price_snapshot_pen'      => 570.0,
                'exchange_rate_snapshot'  => 3.8,
                'checkin'                 => '2026-09-01',
                'checkout'                => '2026-09-03',
                'id_room_type'            => 2,
                'guest_data'              => array_merge([
                    'name'  => 'Ana Torres',
                    'email' => 'ana@example.com',
                    'phone' => '+51 999 000 111',
                    'locale' => 'en',
                ], $guestData),
                'room_data'               => array_merge([
                    ['room_name' => 'Deluxe', 'price_per_night' => 75.0, 'nights' => 2, 'guests' => 2],
                ], $roomData),
            ]
        );
    }

    public function testFailSoftWhenSmtpNotConfigured(): void {
        $this->setSmtpHost('');
        $listener = new SendBookingConfirmationEmailListener($this->buildMailer());
        $listener->handle($this->buildEvent());
        $this->assertCount(0, $this->sent, 'Sin SMTP_HOST no debe intentar enviar.');
    }

    public function testSkipsWhenGuestHasNoEmail(): void {
        $this->setSmtpHost('smtp.hostinger.com');
        $listener = new SendBookingConfirmationEmailListener($this->buildMailer());
        $listener->handle($this->buildEvent(['email' => '']));
        $this->assertCount(0, $this->sent, 'Sin email de huésped no debe enviar.');
    }

    public function testSendsVoucherInGuestLocale(): void {
        $this->setSmtpHost('smtp.hostinger.com');
        $listener = new SendBookingConfirmationEmailListener($this->buildMailer());
        $listener->handle($this->buildEvent(['locale' => 'es']));

        $this->assertCount(1, $this->sent);
        $email = $this->sent[0];
        $this->assertSame('ana@example.com', $email['to']);
        $this->assertStringContainsString('Tu reserva está confirmada', $email['subject']);
        $this->assertStringContainsString('CART-42', $email['subject']);
        $this->assertStringContainsString('CART-42', $email['html']);
        $this->assertStringContainsString('Ana Torres', $email['html']);
        $this->assertStringContainsString('S/ 570.00', $email['html']);
        $this->assertStringContainsString('USD 150.00', $email['html']);
        $this->assertStringContainsString('CART-42', $email['text']);
        $this->assertStringContainsString('ana@example.com', $email['to']);
    }

    public function testIncludesPickupNoteWhenRequested(): void {
        $this->setSmtpHost('smtp.hostinger.com');
        $listener = new SendBookingConfirmationEmailListener($this->buildMailer());
        $listener->handle($this->buildEvent([
            'locale'           => 'es',
            'special_requests' => 'Airport transfer: flight LA-2451 arriving 14:30',
        ]));

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('Traslado del aeropuerto solicitado', $this->sent[0]['html']);
        $this->assertStringContainsString('LA-2451', $this->sent[0]['html']);
        $this->assertStringContainsString('LA-2451', $this->sent[0]['text']);
    }

    public function testIgnoresOtherEvents(): void {
        $this->setSmtpHost('smtp.hostinger.com');
        $listener = new SendBookingConfirmationEmailListener($this->buildMailer());
        $other = $this->createMock(\App\Core\Events\EventInterface::class);
        $listener->handle($other);
        $this->assertCount(0, $this->sent);
    }
}
