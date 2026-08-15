<?php
declare(strict_types=1);

namespace App\Features\Booking\Domain\Listeners;

use App\Core\Config;
use App\Core\Events\EventInterface;
use App\Core\Events\ListenerInterface;
use App\Core\Logger;
use App\Features\Booking\Domain\Events\BookingPaidEvent;
use App\Features\Shared\Ports\MailerPortInterface;
use DateTimeImmutable;

/**
 * Listener que envía el email de confirmación (voucher) al huésped cuando
 * el pago se confirma (BookingPaidEvent -> outbox -> cron process_outbox).
 *
 * 2026-08-14: no existía NINGÚN correo transaccional en el proyecto (ni
 * SMTP, ni listener) — el huésped nunca recibía su voucher. Este listener
 * construye el recibo HTML (estilos inline, compatibles con Gmail/Outlook)
 * con los datos congelados del hold (monto PEN cobrado + tasa) y lo envía
 * al email del huésped en su idioma (guest_data.locale, default 'en').
 *
 * Fail-soft si SMTP no está configurado (SMTP_HOST vacío): log + return —
 * el outbox NO debe reintentar un correo sin configuración. Fail-closed si
 * el envío falla con SMTP configurado: la excepción propaga y el outbox
 * reintenta con backoff (mismo patrón que ConfirmQloAppsOrderListener).
 */
class SendBookingConfirmationEmailListener implements ListenerInterface {
    private MailerPortInterface $mailer;

    public function __construct(MailerPortInterface $mailer) {
        $this->mailer = $mailer;
    }

    public function handle(EventInterface $event): void {
        if (!($event instanceof BookingPaidEvent)) {
            return;
        }

        $smtpHost = (string)Config::get('SMTP_HOST', '');
        if ($smtpHost === '') {
            Logger::info("SendBookingConfirmationEmailListener: SMTP_HOST vacío — email de confirmación desactivado (cart {$event->getCartId()}).");
            return;
        }

        $guestData = $event->getGuestData();
        $to = (string)($guestData['email'] ?? '');
        if ($to === '') {
            Logger::info("SendBookingConfirmationEmailListener: sin email de huésped — no se puede enviar voucher (cart {$event->getCartId()}).");
            return;
        }

        $locale = (string)($guestData['locale'] ?? 'en');
        if (!in_array($locale, ['en', 'es', 'fr', 'pt'], true)) {
            $locale = 'en';
        }
        $L = self::strings($locale);

        $subject = $L['subject'] . ' — ' . $event->getCartId();
        $html = $this->buildVoucherHtml($event, $L);
        $text = $this->buildVoucherText($event, $L);

        Logger::info("SendBookingConfirmationEmailListener: enviando voucher a {$to} (cart {$event->getCartId()}, payment {$event->getPaymentId()}).");
        // Excepción propaga: outbox reintenta con backoff (fail-closed).
        $this->mailer->send($to, $subject, $html, $text);
    }

    /**
     * Construye el HTML del voucher (estilos inline: clientes de correo no
     * cargan hojas externas; <style> en <head> falla en Gmail).
     *
     * @param array<string, string> $L
     */
    private function buildVoucherHtml(BookingPaidEvent $event, array $L): string {
        $siteUrl = rtrim((string)Config::get('SITE_URL', 'https://usgarhoteles.com'), '/');
        $hotel  = 'USGAR Hotels — Boutique Hotel · Cusco, Peru';
        $cartId = htmlspecialchars($event->getCartId(), ENT_QUOTES, 'UTF-8');
        $guestName = htmlspecialchars((string)($event->getGuestData()['name'] ?? 'Guest'), ENT_QUOTES, 'UTF-8');
        $guestPhone = htmlspecialchars((string)($event->getGuestData()['phone'] ?? ''), ENT_QUOTES, 'UTF-8');

        $checkIn  = (new DateTimeImmutable($event->getCheckIn()))->format('M j, Y');
        $checkOut = (new DateTimeImmutable($event->getCheckOut()))->format('M j, Y');

        $amountPen = number_format($event->getAmountPen() / 100, 2);
        $amountUsd = number_format($event->getAmount(), 2);
        $rate      = number_format($event->getExchangeRate(), 2);

        // room_data: lista desde 2026-08-12 (multi-room); legacy = objeto único.
        $roomData = $event->getRoomData();
        if (isset($roomData['room_name'])) {
            $roomData = [$roomData];
        }
        $roomRows = '';
        $nights = 1;
        foreach ($roomData as $i => $r) {
            $nights = max($nights, (int)($r['nights'] ?? 1));
            $name = htmlspecialchars((string)($r['room_name'] ?? "Room " . ($i + 1)), ENT_QUOTES, 'UTF-8');
            $guests = (int)($r['guests'] ?? 0);
            $roomRows .= "<tr>"
                . "<td style=\"padding:8px 0;border-bottom:1px solid #eee;color:#1a1a1a;\">{$name}</td>"
                . "<td style=\"padding:8px 0;border-bottom:1px solid #eee;color:#666;text-align:center;\">{$guests}</td>"
                . "<td style=\"padding:8px 0;border-bottom:1px solid #eee;color:#666;text-align:center;\">{$nights}</td>"
                . "</tr>";
        }

        $pickupNote = '';
        $specialRequests = trim((string)($event->getGuestData()['special_requests'] ?? ''));
        if ($specialRequests !== '') {
            $pickup = htmlspecialchars($specialRequests, ENT_QUOTES, 'UTF-8');
            $pickupNote = "<p style=\"margin:16px 0 0;padding:12px 14px;background:#FDF6E3;border:1px solid #EAD9A8;border-radius:8px;color:#7A5C00;font-size:13px;line-height:1.5;\">✈️ {$L['transferNote']}: <strong>{$pickup}</strong></p>";
        }

        return <<<HTML
<div style="margin:0;padding:24px 12px;background:#F5F3EE;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;">
    <tr>
      <td style="background:#1A1A1A;border-radius:16px 16px 0 0;padding:28px 32px;text-align:center;">
        <div style="font-size:11px;letter-spacing:4px;color:#D4AF37;text-transform:uppercase;font-weight:600;">USGAR</div>
        <div style="font-size:13px;color:#9a9a9a;margin-top:4px;">Boutique Hotel · Cusco, Peru</div>
      </td>
    </tr>
    <tr>
      <td style="background:#FFFFFF;padding:36px 32px;border-radius:0 0 16px 16px;">
        <div style="text-align:center;margin-bottom:24px;">
          <div style="width:56px;height:56px;margin:0 auto 16px;background:#E8F7F0;border-radius:50%;display:flex;align-items:center;justify-content:center;">
            <div style="width:28px;height:14px;border-left:3px solid #1E9E6A;border-bottom:3px solid #1E9E6A;transform:rotate(-45deg) translateY(-4px);"></div>
          </div>
          <h1 style="margin:0;font-size:24px;color:#1a1a1a;">{$L['confirmedTitle']}</h1>
          <p style="margin:8px 0 0;color:#666;font-size:14px;line-height:1.6;">{$L['confirmedMessage']}</p>
          <div style="margin:20px auto 0;display:inline-block;background:#F7F5F0;border:1px dashed #C9C2B2;border-radius:10px;padding:12px 24px;">
            <div style="font-size:10px;letter-spacing:2px;color:#8a8a8a;text-transform:uppercase;">{$L['codeLabel']}</div>
            <div style="font-size:20px;font-weight:700;color:#065952;letter-spacing:1px;margin-top:2px;">{$cartId}</div>
          </div>
        </div>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
          <tr>
            <td style="padding:10px 12px;background:#F7F5F0;border-radius:8px;width:50%;">
              <div style="font-size:10px;letter-spacing:1px;color:#8a8a8a;text-transform:uppercase;">{$L['guestLabel']}</div>
              <div style="font-size:14px;color:#1a1a1a;font-weight:600;margin-top:2px;">{$guestName}</div>
              <div style="font-size:12px;color:#666;margin-top:2px;">{$guestPhone}</div>
            </td>
            <td style="width:12px;"></td>
            <td style="padding:10px 12px;background:#F7F5F0;border-radius:8px;width:50%;">
              <div style="font-size:10px;letter-spacing:1px;color:#8a8a8a;text-transform:uppercase;">{$L['datesLabel']}</div>
              <div style="font-size:14px;color:#1a1a1a;font-weight:600;margin-top:2px;">{$checkIn} → {$checkOut}</div>
              <div style="font-size:12px;color:#666;margin-top:2px;">{$nights} {$L['nightsLabel']}</div>
            </td>
          </tr>
        </table>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">
          <tr>
            <td style="font-size:10px;letter-spacing:1px;color:#8a8a8a;text-transform:uppercase;padding-bottom:4px;">{$L['roomsLabel']}</td>
            <td style="font-size:10px;letter-spacing:1px;color:#8a8a8a;text-transform:uppercase;text-align:center;padding-bottom:4px;">{$L['guestsLabel']}</td>
            <td style="font-size:10px;letter-spacing:1px;color:#8a8a8a;text-transform:uppercase;text-align:center;padding-bottom:4px;">{$L['nightsLabel']}</td>
          </tr>
          {$roomRows}
        </table>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;background:#F7F5F0;border-radius:12px;padding:16px 20px;">
          <tr>
            <td>
              <div style="font-size:11px;letter-spacing:1px;color:#8a8a8a;text-transform:uppercase;">{$L['chargedLabel']}</div>
              <div style="font-size:26px;font-weight:800;color:#065952;margin-top:2px;">S/ {$amountPen}</div>
              <div style="font-size:12px;color:#666;margin-top:2px;">≈ USD {$amountUsd} · {$L['rateLabel']} 1 USD = S/ {$rate}</div>
            </td>
          </tr>
        </table>

        {$pickupNote}

        <p style="margin:24px 0 0;color:#8a8a8a;font-size:13px;text-align:center;">{$L['thanks']}</p>
        <p style="margin:4px 0 0;color:#8a8a8a;font-size:12px;text-align:center;">{$hotel} · <a href="{$siteUrl}" style="color:#065952;text-decoration:none;">{$siteUrl}</a></p>
      </td>
    </tr>
  </table>
</div>
HTML;
    }

    /**
     * @param array<string, string> $L
     */
    private function buildVoucherText(BookingPaidEvent $event, array $L): string {
        $guestName = (string)($event->getGuestData()['name'] ?? 'Guest');
        $amountPen = number_format($event->getAmountPen() / 100, 2);
        $amountUsd = number_format($event->getAmount(), 2);

        $lines = [
            $L['subject'],
            '',
            $L['confirmedTitle'],
            $L['codeLabel'] . ': ' . $event->getCartId(),
            '',
            $L['guestLabel'] . ': ' . $guestName,
            $L['datesLabel'] . ': ' . $event->getCheckIn() . ' -> ' . $event->getCheckOut(),
            $L['chargedLabel'] . ': S/ ' . $amountPen . ' (≈ USD ' . $amountUsd . ')',
        ];

        $specialRequests = trim((string)($event->getGuestData()['special_requests'] ?? ''));
        if ($specialRequests !== '') {
            $lines[] = '';
            $lines[] = $L['transferNote'] . ': ' . $specialRequests;
        }

        $lines[] = '';
        $lines[] = $L['thanks'];
        $lines[] = 'USGAR Hotels — Boutique Hotel · Cusco, Peru';
        $lines[] = (string)Config::get('SITE_URL', 'https://usgarhoteles.com');

        return implode("\n", $lines);
    }

    /**
     * Textos del voucher por idioma del huésped (default 'en').
     *
     * @return array<string, string>
     */
    private static function strings(string $locale): array {
        $base = [
            'subject'         => 'Your reservation is confirmed',
            'confirmedTitle'  => 'Reservation confirmed',
            'confirmedMessage'=> 'Thank you for booking with us. Your payment was successful and your stay is confirmed.',
            'codeLabel'       => 'Reservation code',
            'guestLabel'      => 'Guest',
            'datesLabel'      => 'Stay dates',
            'roomsLabel'      => 'Room',
            'guestsLabel'     => 'Guests',
            'nightsLabel'     => 'nights',
            'chargedLabel'    => 'Charged (paid)',
            'rateLabel'       => 'exchange rate',
            'transferNote'    => 'Airport transfer requested',
            'thanks'          => 'Thank you — we look forward to welcoming you!',
        ];

        if ($locale === 'es') {
            return [
                'subject'          => 'Tu reserva está confirmada',
                'confirmedTitle'   => 'Reserva confirmada',
                'confirmedMessage' => 'Gracias por reservar con nosotros. Tu pago fue exitoso y tu estadía está confirmada.',
                'codeLabel'        => 'Código de reserva',
                'guestLabel'       => 'Huésped',
                'datesLabel'       => 'Fechas de estadía',
                'roomsLabel'       => 'Habitación',
                'guestsLabel'      => 'Huéspedes',
                'nightsLabel'      => 'noches',
                'chargedLabel'     => 'Cobrado (pagado)',
                'rateLabel'        => 'tipo de cambio',
                'transferNote'     => 'Traslado del aeropuerto solicitado',
                'thanks'           => '¡Gracias — esperamos recibirte!',
            ];
        }
        if ($locale === 'fr') {
            return [
                'subject'          => 'Votre réservation est confirmée',
                'confirmedTitle'   => 'Réservation confirmée',
                'confirmedMessage' => 'Merci d\'avoir réservé chez nous. Votre paiement a été effectué et votre séjour est confirmé.',
                'codeLabel'        => 'Code de réservation',
                'guestLabel'       => 'Client',
                'datesLabel'       => 'Dates du séjour',
                'roomsLabel'       => 'Chambre',
                'guestsLabel'      => 'Clients',
                'nightsLabel'      => 'nuits',
                'chargedLabel'     => 'Facturé (payé)',
                'rateLabel'        => 'taux de change',
                'transferNote'     => 'Transfert aéroport demandé',
                'thanks'           => 'Merci — nous avons hâte de vous accueillir !',
            ];
        }
        if ($locale === 'pt') {
            return [
                'subject'          => 'Sua reserva está confirmada',
                'confirmedTitle'   => 'Reserva confirmada',
                'confirmedMessage' => 'Obrigado por reservar conosco. Seu pagamento foi aprovado e sua estadia está confirmada.',
                'codeLabel'        => 'Código da reserva',
                'guestLabel'       => 'Hóspede',
                'datesLabel'       => 'Datas da estadia',
                'roomsLabel'       => 'Quarto',
                'guestsLabel'      => 'Hóspedes',
                'nightsLabel'      => 'noites',
                'chargedLabel'     => 'Cobrado (pago)',
                'rateLabel'        => 'taxa de câmbio',
                'transferNote'     => 'Transfer do aeroporto solicitado',
                'thanks'           => 'Obrigado — esperamos recebê-lo!',
            ];
        }

        return $base;
    }
}
