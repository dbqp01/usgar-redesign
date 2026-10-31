<?php
declare(strict_types=1);

namespace App\Features\Newsletter\Domain;

use App\Core\Config;

/**
 * Textos de la newsletter en los 4 idiomas del sitio: correo de confirmacion
 * (doble opt-in) y paginas HTML de confirmacion/baja servidas por la API.
 * El aviso del footer vive en src/i18n/*.json; esto cubre lo que ocurre
 * fuera del sitio (bandeja de entrada y enlaces del correo).
 */
class NewsletterContent {
    private const EMAIL = [
        'en' => [
            'subject'  => 'Confirm your USGAR newsletter subscription',
            'intro'    => 'Confirm your email to receive our monthly note from Cusco: open the link and confirm on the page.',
            'button'   => 'Confirm subscription',
            'ignore'   => "If you didn't request this, ignore this email: we won't subscribe you.",
            'fallback' => 'If the button does not work, copy this link into your browser:',
        ],
        'es' => [
            'subject'  => 'Confirma tu suscripción a la newsletter de USGAR',
            'intro'    => 'Confirma tu correo para recibir nuestra nota mensual desde Cusco: abre el enlace y confirma en la página.',
            'button'   => 'Confirmar suscripción',
            'ignore'   => 'Si no pediste esto, ignora este correo: no te suscribiremos.',
            'fallback' => 'Si el botón no funciona, copia este enlace en tu navegador:',
        ],
        'fr' => [
            'subject'  => 'Confirmez votre abonnement à la newsletter USGAR',
            'intro'    => 'Confirmez votre e-mail pour recevoir notre note mensuelle depuis Cusco : ouvrez le lien et confirmez sur la page.',
            'button'   => 'Confirmer l\'abonnement',
            'ignore'   => "Si vous n'avez pas demandé cela, ignorez cet e-mail : nous ne vous abonnerons pas.",
            'fallback' => 'Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :',
        ],
        'pt' => [
            'subject'  => 'Confirme a sua inscrição na newsletter do USGAR',
            'intro'    => 'Confirme o seu e-mail para receber a nossa nota mensal de Cusco: abra o link e confirme na página.',
            'button'   => 'Confirmar inscrição',
            'ignore'   => 'Se não pediu isto, ignore este e-mail: não vamos inscrevê-lo.',
            'fallback' => 'Se o botão não funcionar, copie este link no seu navegador:',
        ],
    ];

    private const PAGE = [
        'en' => [
            'confirm_ask_heading'  => 'Confirm your subscription',
            'confirm_ask_body'     => 'Confirm that you want to receive the USGAR newsletter at :email. We only send it after this step.',
            'confirm_ok_heading'   => 'Subscription confirmed',
            'confirm_ok_body'      => "Thanks - you're on the list. You'll receive our monthly note from Cusco, and every email includes an unsubscribe link.",
            'invalid_heading'      => 'Link not valid',
            'invalid_body'         => 'This link is invalid, was already used or is no longer active.',
            'unsubscribe_heading'  => 'Unsubscribe',
            'unsubscribe_body'     => 'Stop receiving the USGAR newsletter at :email?',
            'unsubscribe_button'   => 'Unsubscribe',
            'unsubscribed_heading' => "You're unsubscribed",
            'unsubscribed_body'    => 'You will no longer receive the USGAR newsletter. You can subscribe again on our website at any time.',
        ],
        'es' => [
            'confirm_ask_heading'  => 'Confirma tu suscripción',
            'confirm_ask_body'     => 'Confirma que quieres recibir la newsletter de USGAR en :email. Solo la enviamos después de este paso.',
            'confirm_ok_heading'   => 'Suscripción confirmada',
            'confirm_ok_body'      => 'Gracias - ya estás en la lista. Recibirás nuestra nota mensual desde Cusco y cada correo incluye un enlace para darte de baja.',
            'invalid_heading'      => 'Enlace no válido',
            'invalid_body'         => 'Este enlace no es válido, ya se usó o dejó de estar activo.',
            'unsubscribe_heading'  => 'Darte de baja',
            'unsubscribe_body'     => '¿Dejar de recibir la newsletter de USGAR en :email?',
            'unsubscribe_button'   => 'Darme de baja',
            'unsubscribed_heading' => 'Baja confirmada',
            'unsubscribed_body'    => 'Ya no recibirás la newsletter de USGAR. Puedes volver a suscribirte en nuestra web cuando quieras.',
        ],
        'fr' => [
            'confirm_ask_heading'  => 'Confirmez votre abonnement',
            'confirm_ask_body'     => 'Confirmez que vous souhaitez recevoir la newsletter USGAR à :email. Nous ne l\'envoyons qu\'après cette étape.',
            'confirm_ok_heading'   => 'Abonnement confirmé',
            'confirm_ok_body'      => 'Merci - vous êtes inscrit. Vous recevrez notre note mensuelle depuis Cusco, et chaque e-mail contient un lien de désabonnement.',
            'invalid_heading'      => 'Lien non valide',
            'invalid_body'         => 'Ce lien est invalide, déjà utilisé ou inactif.',
            'unsubscribe_heading'  => 'Se désabonner',
            'unsubscribe_body'     => 'Ne plus recevoir la newsletter USGAR à :email ?',
            'unsubscribe_button'   => 'Se désabonner',
            'unsubscribed_heading' => 'Désabonnement confirmé',
            'unsubscribed_body'    => 'Vous ne recevrez plus la newsletter USGAR. Vous pouvez vous réabonner sur notre site à tout moment.',
        ],
        'pt' => [
            'confirm_ask_heading'  => 'Confirme a sua inscrição',
            'confirm_ask_body'     => 'Confirme que quer receber a newsletter do USGAR em :email. Só a enviamos depois deste passo.',
            'confirm_ok_heading'   => 'Inscrição confirmada',
            'confirm_ok_body'      => 'Obrigado - você está na lista. Vai receber a nossa nota mensal de Cusco, e cada e-mail inclui um link para cancelar a inscrição.',
            'invalid_heading'      => 'Link inválido',
            'invalid_body'         => 'Este link é inválido, já foi usado ou não está mais ativo.',
            'unsubscribe_heading'  => 'Cancelar inscrição',
            'unsubscribe_body'     => 'Deixar de receber a newsletter do USGAR em :email?',
            'unsubscribe_button'   => 'Cancelar inscrição',
            'unsubscribed_heading' => 'Inscrição cancelada',
            'unsubscribed_body'    => 'Você não vai mais receber a newsletter do USGAR. Pode voltar a inscrever-se no nosso site quando quiser.',
        ],
    ];

    public static function normalizeLocale(?string $locale): string {
        $locale = strtolower(trim((string)$locale));

        return isset(self::EMAIL[$locale]) ? $locale : 'en';
    }

    /** @return array{subject:string,html:string,text:string} */
    public static function confirmationEmail(string $locale, string $confirmUrl): array {
        $locale = self::normalizeLocale($locale);
        $copy = self::EMAIL[$locale];
        $url = htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8');

        $html = '<!doctype html><html lang="' . $locale . '"><body style="margin:0;padding:24px;background:#f6f4f7;font-family:Georgia,\'Times New Roman\',serif;color:#2E1A38;">'
            . '<div style="max-width:560px;margin:0 auto;background:#ffffff;padding:32px;border-radius:8px;">'
            . '<p style="margin:0 0 24px;font-family:Arial,sans-serif;font-size:11px;letter-spacing:0.2em;text-transform:uppercase;color:#7E628D;">USGAR Hotels</p>'
            . '<h1 style="margin:0 0 16px;font-size:24px;font-weight:400;">' . htmlspecialchars($copy['subject'], ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<p style="margin:0 0 24px;font-size:16px;line-height:1.6;">' . htmlspecialchars($copy['intro'], ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="margin:0 0 24px;"><a href="' . $url . '" style="display:inline-block;padding:14px 24px;background:#3D2B4A;color:#ffffff;text-decoration:none;border-radius:999px;font-family:Arial,sans-serif;font-size:13px;letter-spacing:0.08em;text-transform:uppercase;">' . htmlspecialchars($copy['button'], ENT_QUOTES, 'UTF-8') . '</a></p>'
            . '<p style="margin:0 0 12px;font-size:12px;line-height:1.6;color:#6b6470;">' . htmlspecialchars($copy['ignore'], ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="margin:0;font-size:12px;line-height:1.6;color:#6b6470;">' . htmlspecialchars($copy['fallback'], ENT_QUOTES, 'UTF-8') . '<br><a href="' . $url . '" style="color:#7E628D;word-break:break-all;">' . $url . '</a></p>'
            . '</div></body></html>';

        $text = $copy['subject'] . "\n\n"
            . $copy['intro'] . "\n\n"
            . $copy['button'] . ': ' . $confirmUrl . "\n\n"
            . $copy['ignore'] . "\n";

        return ['subject' => $copy['subject'], 'html' => $html, 'text' => $text];
    }

    /**
     * @param array{email?:string, token?:string} $vars
     */
    public static function renderPage(string $locale, string $variant, int $statusCode, array $vars = []): void {
        $locale = self::normalizeLocale($locale);
        $copy = self::PAGE[$locale];
        $siteUrl = rtrim((string)Config::get('SITE_URL', 'https://usgarhoteles.com'), '/');

        [$heading, $body] = match ($variant) {
            'confirm_ask'      => [$copy['confirm_ask_heading'], $copy['confirm_ask_body']],
            'confirm_ok'       => [$copy['confirm_ok_heading'], $copy['confirm_ok_body']],
            'unsubscribe_ask'  => [$copy['unsubscribe_heading'], $copy['unsubscribe_body']],
            'unsubscribe_ok'   => [$copy['unsubscribed_heading'], $copy['unsubscribed_body']],
            default            => [$copy['invalid_heading'], $copy['invalid_body']],
        };

        $email = htmlspecialchars((string)($vars['email'] ?? ''), ENT_QUOTES, 'UTF-8');
        $body = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
        if ($email !== '') {
            $body = str_replace(':email', '<strong>' . $email . '</strong>', $body);
        }

        $form = '';
        if ($variant === 'confirm_ask' || $variant === 'unsubscribe_ask') {
            $action = $variant === 'confirm_ask' ? '/api/newsletter/confirm' : '/api/newsletter/unsubscribe';
            $label = $variant === 'confirm_ask' ? self::EMAIL[$locale]['button'] : $copy['unsubscribe_button'];
            $form = '<form method="post" action="' . $action . '" style="margin:24px 0 0;">'
                . '<input type="hidden" name="email" value="' . $email . '">'
                . '<input type="hidden" name="token" value="' . htmlspecialchars((string)($vars['token'] ?? ''), ENT_QUOTES, 'UTF-8') . '">'
                . '<button type="submit" style="padding:14px 24px;background:#3D2B4A;color:#ffffff;border:0;border-radius:999px;font-family:Arial,sans-serif;font-size:13px;letter-spacing:0.08em;text-transform:uppercase;cursor:pointer;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>'
                . '</form>';
        }

        $html = '<!doctype html><html lang="' . $locale . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . ' - USGAR Hotels</title>'
            . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f6f4f7;font-family:Georgia,\'Times New Roman\',serif;color:#2E1A38;}main{max-width:520px;padding:40px 32px;background:#fff;border-radius:8px;}p.brand{font-family:Arial,sans-serif;font-size:11px;letter-spacing:.2em;text-transform:uppercase;color:#7E628D;margin:0 0 24px;}h1{font-size:26px;font-weight:400;margin:0 0 16px;}p{font-size:16px;line-height:1.6;margin:0 0 20px;}a{color:#7E628D;}</style>'
            . '</head><body><main><p class="brand">USGAR Hotels</p><h1>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h1><p>' . $body . '</p>' . $form
            . '<p style="margin:24px 0 0;font-size:12px;"><a href="' . htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8') . '">usgarhoteles.com</a></p>'
            . '</main></body></html>';

        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: text/html; charset=utf-8');
            header('X-Robots-Tag: noindex, nofollow');
            header('Cache-Control: no-store');
        }

        echo $html;
    }
}
