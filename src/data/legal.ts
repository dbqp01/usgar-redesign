// src/data/legal.ts
// Contenido legal del sitio en 4 idiomas: privacidad (Ley 29733 + su reglamento
// DS 016-2024-JUS + GDPR) y términos (Ley 29571 / INDECOPI + DS 001-2015-MINCETUR).
// Cada afirmación de esta copia se contrasta con el código (cookies, encargados,
// cifrado, tipo de cambio) y con la norma vigente: ver tests/Unit/legal.test.ts.
// Nota: el texto ES lleva tildes (documento legal); el resto del sitio usa ASCII
// por convención de los JSON de i18n.
import { siteSettings } from "./settings";

export type LegalLocale = "en" | "es" | "fr" | "pt";

export interface LegalSection {
  title: string;
  body: string[];
}

export interface LegalMeta {
  title: string;
  updatedLabel: string;
  updatedDate: string;
}

// Fecha de la última corrección de fondo (hechos, no maquetación).
const UPDATED = {
  en: "October 29, 2026",
  es: "29 de octubre de 2026",
  fr: "29 octobre 2026",
  pt: "29 de outubro de 2026",
} as const;

export const legalMeta: Record<"privacy" | "terms", Record<LegalLocale, LegalMeta>> = {
  privacy: {
    en: { title: "Privacy Policy", updatedLabel: "Last updated", updatedDate: UPDATED.en },
    es: { title: "Política de Privacidad", updatedLabel: "Última actualización", updatedDate: UPDATED.es },
    fr: { title: "Politique de confidentialité", updatedLabel: "Dernière mise à jour", updatedDate: UPDATED.fr },
    pt: { title: "Política de Privacidade", updatedLabel: "Última atualização", updatedDate: UPDATED.pt },
  },
  terms: {
    en: { title: "Terms of Service", updatedLabel: "Last updated", updatedDate: UPDATED.en },
    es: { title: "Términos y Condiciones", updatedLabel: "Última actualización", updatedDate: UPDATED.es },
    fr: { title: "Conditions générales", updatedLabel: "Dernière mise à jour", updatedDate: UPDATED.fr },
    pt: { title: "Termos e Condições", updatedLabel: "Última atualização", updatedDate: UPDATED.pt },
  },
};

export const privacySections: Record<LegalLocale, LegalSection[]> = {
  en: [
    {
      title: "1. Who we are",
      body: [
        `USGAR Hotels, 759 Calle Hospital, Centro de Cusco, Cusco, Peru, operates this website and decides how the personal data described in this policy is used. For anything related to this policy or your data, write to ${siteSettings.email}.`,
      ],
    },
    {
      title: "2. Data we collect",
      body: [
        "Booking: name, email address, phone number, check-in and check-out dates, number of guests, room and rate chosen, language, special requests and, if you ask for the airport transfer, your flight details. We need this data to hold the room, process the payment and manage your stay.",
        "Contact form: name, email address, subject and message, so that we can answer your request.",
        "Account, if you create one: email address, first and last name, phone number and, when you sign in with Google, Microsoft or Facebook, the profile photo and account identifier those services share with us. If you set a password, we store only a hash of it.",
        "Newsletter, if you subscribe: email address, language, the IP address and browser identifier of the subscription request, and the date and version of the consent notice you accepted.",
        "Payments: the card number, expiry date and security code are entered on MercadoPago's platform and go directly to MercadoPago. We receive the payment result, the amount, the payment identifier and the payer name and email. We never see or store your full card number.",
        "Technical data: IP address, browser information and security logs, used to keep the site online and prevent abuse.",
      ],
    },
    {
      title: "3. Why we use your data",
      body: [
        "To perform the booking contract: hold the room, charge the stay, send the confirmation and pre-arrival information, and register your stay in our property management system.",
        "To comply with Peruvian law, including tax and lodging records.",
        "With your consent, to send the newsletter. We only send it after you confirm your address by email, and you can withdraw consent at any time with the unsubscribe link or by writing to us.",
        "For our legitimate interest in keeping the website secure, preventing fraud and limiting abusive requests.",
        "If you create an account, to authenticate you and show your bookings.",
      ],
    },
    {
      title: "4. Cookies and local storage",
      body: [
        "Strictly necessary cookies: usgar_session keeps you signed in and authorises booking actions (HttpOnly, 30 days); usgar_csrf protects forms against cross-site request forgery (readable by our JavaScript, 30 days); usgar_panel and usgar_panel_csrf are used only by hotel staff in the internal panel (12 hours); usgar_auth_provider and usgar_auth_redirect (5 minutes) plus PHPSESSID (until you close the browser) exist only while you sign in with a social account.",
        "The site also stores your theme preference and the acknowledgement of the cookie notice in local storage, and a preloader flag, a summary of your profile and the access token of a booking in progress in session storage. Session storage is erased when you close the tab.",
        "Third parties: when you pay, MercadoPago loads its payment form and may set its own cookies under its privacy policy. The map loads tiles from CARTO and OpenStreetMap, which receive your IP address.",
        "We do not use analytics, advertising or marketing cookies. Strictly necessary cookies do not require consent under the GDPR and the ePrivacy Directive; we show a notice that you can acknowledge.",
      ],
    },
    {
      title: "5. Who receives your data",
      body: [
        "MercadoPago, for payments. QloApps, the property management system where we record your reservation, at cms.usgarhoteles.com. Hostinger, which hosts the website and sends our email. Google, Microsoft or Facebook, only if you choose to sign in with them. CARTO and OpenStreetMap, which serve the map tiles. Public authorities, when the law requires it.",
        `We do not sell your data. Some providers may process data outside Peru. For information about international transfers, write to ${siteSettings.email}.`,
      ],
    },
    {
      title: "6. Your rights",
      body: [
        "Peru: under Law No. 29733 and its regulation (Supreme Decree No. 016-2024-JUS) you can request information, access, rectification, cancellation or opposition concerning your data. We will respond within the applicable legal deadlines. You can also complain to the National Authority for Personal Data Protection (ANPD).",
        "European Union / EEA: the GDPR also gives you the right to erasure, restriction, portability and objection, to withdraw consent, and to complain to your local supervisory authority.",
        `Requests are free. Write to ${siteSettings.email} and identify yourself; we may ask for proof of identity. We may keep data that the law requires us to keep.`,
      ],
    },
    {
      title: "7. Retention and security",
      body: [
        "We retain booking, payment, contact, account and newsletter records. Retention depends on the purpose, applicable obligations and any outstanding requests or disputes. After you unsubscribe, your newsletter record remains marked as unsubscribed. Contact us for information about your records or to exercise your rights.",
        "We use HTTPS with HSTS, signed session tokens (HMAC-SHA256) in HttpOnly, Secure and SameSite=Lax cookies, CSRF tokens in forms, hashed passwords, access controls and request rate limits.",
      ],
    },
  ],
  es: [
    {
      title: "1. Quiénes somos",
      body: [
        `USGAR Hotels, 759 Calle Hospital, Centro de Cusco, Cusco, Perú, opera este sitio web y decide cómo se usan los datos personales descritos en esta política. Para cualquier consulta sobre esta política o tus datos, escríbenos a ${siteSettings.email}.`,
      ],
    },
    {
      title: "2. Datos que recopilamos",
      body: [
        "Reserva: nombre, correo electrónico, teléfono, fechas de entrada y salida, número de huéspedes, habitación y tarifa elegidas, idioma, pedidos especiales y, si solicitas el traslado desde el aeropuerto, los datos de tu vuelo. Necesitamos estos datos para bloquear la habitación, procesar el pago y gestionar tu estadía.",
        "Formulario de contacto: nombre, correo electrónico, asunto y mensaje, para poder responderte.",
        "Cuenta, si la creas: correo electrónico, nombres y apellidos, teléfono y, si ingresas con Google, Microsoft o Facebook, la foto de perfil y el identificador de cuenta que esos servicios comparten con nosotros. Si defines una contraseña, guardamos solo un hash.",
        "Newsletter, si te suscribes: correo electrónico, idioma, dirección IP e identificador del navegador de la solicitud, y la fecha y versión del aviso de consentimiento que aceptaste.",
        "Pagos: el número de tarjeta, la fecha de vencimiento y el código de seguridad se ingresan en la plataforma de MercadoPago y van directamente a MercadoPago. Recibimos el resultado del pago, el monto, el identificador del pago y el nombre y correo del pagador. Nunca vemos ni almacenamos el número completo de tu tarjeta.",
        "Datos técnicos: dirección IP, información del navegador y registros de seguridad, usados para mantener el sitio disponible y prevenir abusos.",
      ],
    },
    {
      title: "3. Para qué usamos tus datos",
      body: [
        "Para ejecutar el contrato de reserva: bloquear la habitación, cobrar la estadía, enviar la confirmación y la información previa a la llegada, y registrar tu estadía en nuestro sistema de gestión hotelera.",
        "Para cumplir obligaciones legales peruanas, incluidos los registros tributarios y de hospedaje.",
        "Con tu consentimiento, para enviarte la newsletter. Solo la enviamos después de que confirmes tu dirección por correo, y puedes retirar el consentimiento en cualquier momento con el enlace de baja o escribiéndonos.",
        "Por interés legítimo, para mantener la seguridad del sitio, prevenir fraudes y limitar solicitudes abusivas.",
        "Si creas una cuenta, para autenticarte y mostrarte tus reservas.",
      ],
    },
    {
      title: "4. Cookies y almacenamiento local",
      body: [
        "Cookies estrictamente necesarias: usgar_session mantiene tu sesión iniciada y autoriza las acciones de reserva (HttpOnly, 30 días); usgar_csrf protege los formularios contra la falsificación de solicitudes (legible por nuestro JavaScript, 30 días); usgar_panel y usgar_panel_csrf los usa solo el personal del hotel en el panel interno (12 horas); usgar_auth_provider y usgar_auth_redirect (5 minutos) y PHPSESSID (hasta que cierras el navegador) existen solo mientras ingresas con una cuenta social.",
        "El sitio también guarda tu preferencia de tema y la confirmación del aviso de cookies en almacenamiento local, y una marca del preloader, un resumen de tu perfil y el token de acceso de una reserva en curso en almacenamiento de sesión. El almacenamiento de sesión se borra al cerrar la pestaña.",
        "Terceros: al pagar, MercadoPago carga su formulario de pago y puede establecer sus propias cookies, regidas por su política de privacidad. El mapa carga teselas de CARTO y OpenStreetMap, que reciben tu dirección IP.",
        "No usamos cookies de analítica, publicidad ni marketing. Las cookies estrictamente necesarias no requieren consentimiento según el GDPR y la Directiva ePrivacy; mostramos un aviso que puedes confirmar.",
      ],
    },
    {
      title: "5. Quién recibe tus datos",
      body: [
        "MercadoPago, para los pagos. QloApps, el sistema de gestión hotelera donde registramos tu reserva, en cms.usgarhoteles.com. Hostinger, que aloja el sitio web y envía nuestro correo. Google, Microsoft o Facebook, solo si eliges ingresar con ellos. CARTO y OpenStreetMap, que sirven las teselas del mapa. Autoridades públicas, cuando la ley lo exige.",
        `No vendemos tus datos. Algunos proveedores pueden tratarlos fuera del Perú. Para consultar sobre transferencias internacionales, escríbenos a ${siteSettings.email}.`,
      ],
    },
    {
      title: "6. Tus derechos",
      body: [
        "Perú: conforme a la Ley N. 29733 y su reglamento (Decreto Supremo N. 016-2024-JUS), puedes solicitar información, acceso, rectificación, cancelación u oposición respecto a tus datos. Responderemos dentro de los plazos legales aplicables. También puedes reclamar ante la Autoridad Nacional de Protección de Datos Personales (ANPD).",
        "Unión Europea / EEE: el GDPR también te da derecho a la supresión, limitación, portabilidad y oposición, a retirar el consentimiento y a reclamar ante tu autoridad de control.",
        `Las solicitudes son gratuitas. Escríbenos a ${siteSettings.email} identificándote; podemos pedirte prueba de identidad. Podemos conservar los datos que la ley nos obliga a mantener.`,
      ],
    },
    {
      title: "7. Conservación y seguridad",
      body: [
        "Conservamos registros de reservas, pagos, contacto, cuentas y newsletter. La conservación depende de la finalidad, las obligaciones aplicables y las solicitudes o controversias pendientes. Al darte de baja, tu registro de newsletter permanece marcado como dado de baja. Contáctanos para consultar sobre tus datos o ejercer tus derechos.",
        "Usamos HTTPS con HSTS, tokens de sesión firmados (HMAC-SHA256) en cookies HttpOnly, Secure y SameSite=Lax, tokens CSRF en los formularios, contraseñas con hash, control de accesos y límites de peticiones.",
      ],
    },
  ],
  fr: [
    {
      title: "1. Qui sommes-nous",
      body: [
        `USGAR Hotels, 759 Calle Hospital, Centro de Cusco, Cusco, Pérou, exploite ce site et décide de l'usage des données personnelles décrites dans cette politique. Pour toute question sur cette politique ou vos données, écrivez-nous à ${siteSettings.email}.`,
      ],
    },
    {
      title: "2. Données que nous collectons",
      body: [
        "Réservation : nom, adresse e-mail, téléphone, dates d'arrivée et de départ, nombre de voyageurs, chambre et tarif choisis, langue, demandes particulières et, si vous demandez la navette aéroport, les informations de votre vol. Ces données sont nécessaires pour bloquer la chambre, traiter le paiement et gérer votre séjour.",
        "Formulaire de contact : nom, adresse e-mail, objet et message, afin de répondre à votre demande.",
        "Compte, si vous en créez un : adresse e-mail, prénom et nom, téléphone et, si vous vous connectez avec Google, Microsoft ou Facebook, la photo de profil et l'identifiant de compte transmis par ces services. Si vous définissez un mot de passe, nous n'en stockons qu'un hachage.",
        "Newsletter, si vous vous abonnez : adresse e-mail, langue, adresse IP et identifiant du navigateur de la demande d'abonnement, ainsi que la date et la version de l'avis de consentement accepté.",
        "Paiements : le numéro de carte, la date d'expiration et le code de sécurité sont saisis sur la plateforme de MercadoPago et transmis directement à MercadoPago. Nous recevons le résultat du paiement, le montant, l'identifiant du paiement ainsi que le nom et l'e-mail du payeur. Nous ne voyons ni ne stockons jamais le numéro complet de votre carte.",
        "Données techniques : adresse IP, informations sur le navigateur et journaux de sécurité, utilisés pour maintenir le site disponible et prévenir les abus.",
      ],
    },
    {
      title: "3. Pourquoi nous utilisons vos données",
      body: [
        "Pour exécuter le contrat de réservation : bloquer la chambre, encaisser le séjour, envoyer la confirmation et les informations avant l'arrivée, et enregistrer votre séjour dans notre système de gestion hôtelière.",
        "Pour respecter nos obligations légales péruviennes, notamment fiscales et d'enregistrement des voyageurs.",
        "Avec votre consentement, pour envoyer la newsletter. Nous ne l'envoyons qu'après confirmation de votre adresse par e-mail ; vous pouvez retirer votre consentement à tout moment via le lien de désabonnement ou en nous écrivant.",
        "Pour notre intérêt légitime à sécuriser le site, prévenir la fraude et limiter les demandes abusives.",
        "Si vous créez un compte, pour vous authentifier et afficher vos réservations.",
      ],
    },
    {
      title: "4. Cookies et stockage local",
      body: [
        "Cookies strictement nécessaires : usgar_session maintient votre session et autorise les actions de réservation (HttpOnly, 30 jours) ; usgar_csrf protège les formulaires contre la falsification de requêtes (lisible par notre JavaScript, 30 jours) ; usgar_panel et usgar_panel_csrf ne servent qu'au personnel de l'hôtel dans le panneau interne (12 heures) ; usgar_auth_provider et usgar_auth_redirect (5 minutes) ainsi que PHPSESSID (jusqu'à la fermeture du navigateur) n'existent que pendant une connexion via un compte social.",
        "Le site stocke aussi votre préférence de thème et l'accusé de réception de l'avis cookies dans le stockage local, ainsi qu'un indicateur de préchargement, un résumé de votre profil et le jeton d'accès d'une réservation en cours dans le stockage de session. Le stockage de session est effacé à la fermeture de l'onglet.",
        "Tiers : lors du paiement, MercadoPago charge son formulaire et peut déposer ses propres cookies, régis par sa politique de confidentialité. La carte charge des tuiles depuis CARTO et OpenStreetMap, qui reçoivent votre adresse IP.",
        "Nous n'utilisons pas de cookies d'analyse, de publicité ou de marketing. Les cookies strictement nécessaires ne nécessitent pas de consentement au titre du RGPD et de la directive ePrivacy ; nous affichons un avis que vous pouvez confirmer.",
      ],
    },
    {
      title: "5. Qui reçoit vos données",
      body: [
        "MercadoPago, pour les paiements. QloApps, le système de gestion hôtelière où nous enregistrons votre réservation, sur cms.usgarhoteles.com. Hostinger, qui héberge le site et envoie nos e-mails. Google, Microsoft ou Facebook, uniquement si vous choisissez de vous connecter avec eux. CARTO et OpenStreetMap, qui fournissent les tuiles de la carte. Les autorités publiques, lorsque la loi l'exige.",
        `Nous ne vendons pas vos données. Certains prestataires peuvent les traiter hors du Pérou. Pour toute question sur les transferts internationaux, écrivez-nous à ${siteSettings.email}.`,
      ],
    },
    {
      title: "6. Vos droits",
      body: [
        "Pérou : en vertu de la loi n° 29733 et de son règlement (décret suprême n° 016-2024-JUS), vous pouvez demander l'information, l'accès, la rectification, l'annulation ou l'opposition concernant vos données. Nous répondrons dans les délais légaux applicables. Vous pouvez aussi saisir l'Autorité nationale de protection des données personnelles (ANPD).",
        "Union européenne / EEE : le RGPD vous donne aussi le droit à l'effacement, à la limitation, à la portabilité et à l'opposition, le droit de retirer votre consentement et celui de saisir votre autorité de contrôle.",
        `Les demandes sont gratuites. Écrivez-nous à ${siteSettings.email} en vous identifiant ; nous pouvons demander une preuve d'identité. Nous pouvons conserver les données que la loi nous oblige à garder.`,
      ],
    },
    {
      title: "7. Conservation et sécurité",
      body: [
        "Nous conservons les données de réservation, paiement, contact, compte et newsletter. Leur conservation dépend de la finalité, des obligations applicables et des demandes ou litiges en cours. Après désabonnement, votre inscription reste marquée comme résiliée. Contactez-nous pour vous informer sur vos données ou exercer vos droits.",
        "Nous utilisons HTTPS avec HSTS, des jetons de session signés (HMAC-SHA256) dans des cookies HttpOnly, Secure et SameSite=Lax, des jetons CSRF dans les formulaires, des mots de passe hachés, des contrôles d'accès et une limitation du débit des requêtes.",
      ],
    },
  ],
  pt: [
    {
      title: "1. Quem somos",
      body: [
        `A USGAR Hotels, 759 Calle Hospital, Centro de Cusco, Cusco, Peru, opera este site e decide como são usados os dados pessoais descritos nesta política. Para qualquer questão sobre esta política ou os seus dados, escreva para ${siteSettings.email}.`,
      ],
    },
    {
      title: "2. Dados que coletamos",
      body: [
        "Reserva: nome, e-mail, telefone, datas de entrada e saída, número de hóspedes, quarto e tarifa escolhidos, idioma, pedidos especiais e, se solicitar o transporte do aeroporto, os dados do seu voo. Precisamos destes dados para bloquear o quarto, processar o pagamento e gerir a sua estadia.",
        "Formulário de contato: nome, e-mail, assunto e mensagem, para podermos responder ao seu pedido.",
        "Conta, se criar uma: e-mail, nome e sobrenome, telefone e, se entrar com Google, Microsoft ou Facebook, a foto de perfil e o identificador de conta que esses serviços compartilham conosco. Se definir uma senha, guardamos apenas um hash.",
        "Newsletter, se assinar: e-mail, idioma, endereço IP e identificador do navegador do pedido de assinatura, e a data e versão do aviso de consentimento aceito.",
        "Pagamentos: o número do cartão, a validade e o código de segurança são introduzidos na plataforma da MercadoPago e vão diretamente para a MercadoPago. Recebemos o resultado do pagamento, o valor, o identificador do pagamento e o nome e e-mail do pagador. Nunca vemos nem armazenamos o número completo do seu cartão.",
        "Dados técnicos: endereço IP, informações do navegador e registros de segurança, usados para manter o site disponível e prevenir abusos.",
      ],
    },
    {
      title: "3. Para que usamos seus dados",
      body: [
        "Para executar o contrato de reserva: bloquear o quarto, cobrar a estadia, enviar a confirmação e as informações antes da chegada, e registrar a sua estadia no nosso sistema de gestão hoteleira.",
        "Para cumprir obrigações legais peruanas, incluindo registros fiscais e de hospedagem.",
        "Com o seu consentimento, para enviar a newsletter. Só a enviamos depois que você confirmar o endereço por e-mail, e você pode retirar o consentimento a qualquer momento pelo link de cancelamento ou escrevendo para nós.",
        "Por interesse legítimo, para manter a segurança do site, prevenir fraudes e limitar pedidos abusivos.",
        "Se criar uma conta, para autenticar você e mostrar as suas reservas.",
      ],
    },
    {
      title: "4. Cookies e armazenamento local",
      body: [
        "Cookies estritamente necessários: usgar_session mantém a sessão iniciada e autoriza as ações de reserva (HttpOnly, 30 dias); usgar_csrf protege os formulários contra falsificação de pedidos (legível pelo nosso JavaScript, 30 dias); usgar_panel e usgar_panel_csrf são usados apenas pelo pessoal do hotel no painel interno (12 horas); usgar_auth_provider e usgar_auth_redirect (5 minutos), além do PHPSESSID (até fechar o navegador), existem apenas durante a entrada com uma conta social.",
        "O site também guarda a sua preferência de tema e a confirmação do aviso de cookies no armazenamento local, e uma marca do preloader, um resumo do seu perfil e o token de acesso de uma reserva em andamento no armazenamento de sessão. O armazenamento de sessão é apagado ao fechar a aba.",
        "Terceiros: ao pagar, a MercadoPago carrega o seu formulário e pode definir os seus próprios cookies, regidos pela sua política de privacidade. O mapa carrega blocos da CARTO e do OpenStreetMap, que recebem o seu endereço IP.",
        "Não usamos cookies de análise, publicidade ou marketing. Os cookies estritamente necessários não exigem consentimento ao abrigo do RGPD e da Diretiva ePrivacy; mostramos um aviso que você pode confirmar.",
      ],
    },
    {
      title: "5. Quem recebe seus dados",
      body: [
        "MercadoPago, para os pagamentos. QloApps, o sistema de gestão hoteleira onde registramos a sua reserva, em cms.usgarhoteles.com. Hostinger, que hospeda o site e envia os nossos e-mails. Google, Microsoft ou Facebook, apenas se escolher entrar com eles. CARTO e OpenStreetMap, que fornecem os blocos do mapa. Autoridades públicas, quando a lei exige.",
        `Não vendemos os seus dados. Alguns fornecedores podem tratá-los fora do Peru. Para informações sobre transferências internacionais, escreva para ${siteSettings.email}.`,
      ],
    },
    {
      title: "6. Seus direitos",
      body: [
        "Peru: de acordo com a Lei n.º 29733 e seu regulamento (Decreto Supremo n.º 016-2024-JUS), você pode solicitar informação, acesso, retificação, cancelamento ou oposição em relação aos seus dados. Responderemos nos prazos legais aplicáveis. Você também pode reclamar à Autoridade Nacional de Proteção de Dados Pessoais (ANPD).",
        "União Europeia / EEE: o RGPD também garante o direito de exclusão, limitação, portabilidade e oposição, de retirar o consentimento e de reclamar junto da sua autoridade de controle.",
        `Os pedidos são gratuitos. Escreva para ${siteSettings.email} identificando-se; podemos pedir prova de identidade. Podemos conservar os dados que a lei nos obriga a manter.`,
      ],
    },
    {
      title: "7. Conservação e segurança",
      body: [
        "Conservamos registros de reservas, pagamentos, contato, contas e newsletter. A conservação depende da finalidade, das obrigações aplicáveis e de solicitações ou disputas pendentes. Após o cancelamento, seu registro da newsletter permanece marcado como cancelado. Entre em contato para consultar seus dados ou exercer seus direitos.",
        "Usamos HTTPS com HSTS, tokens de sessão assinados (HMAC-SHA256) em cookies HttpOnly, Secure e SameSite=Lax, tokens CSRF nos formulários, senhas com hash, controle de acessos e limites de pedidos.",
      ],
    },
  ],
};

export const termsSections: Record<LegalLocale, LegalSection[]> = {
  en: [
    {
      title: "1. Bookings and payment",
      body: [
        "Reservations are made on this website at the published rates, shown in US dollars (USD). The charge is made in Peruvian soles (PEN) at the exchange rate we set for your booking when you quote it. That rate is fixed for the booking and is the one used for the payment. If your card is in another currency, your bank or card issuer may apply its own exchange rate or fees.",
        "Payment is processed by MercadoPago. Your reservation is confirmed when the payment is approved and you receive the confirmation email. The room is held for a limited time while you pay; if the hold expires, the reservation is not confirmed.",
        "Non-refundable rates are marked during booking. Extra guests beyond the room capacity must be registered and may incur the extra-guest charge shown during booking.",
      ],
    },
    {
      title: "2. Check-in and check-out",
      body: [
        `Check-in is available from ${siteSettings.checkinTime} and check-out until ${siteSettings.checkoutTime}. Early check-in and late check-out depend on availability and can be requested at reception.`,
        "Free luggage storage is available before check-in and after check-out.",
      ],
    },
    {
      title: "3. Cancellation and refunds",
      body: [
        "To cancel or modify a reservation, contact us by email or phone as soon as possible. Refunds for refundable rates are made through MercadoPago to the same payment method; how quickly the amount appears depends on MercadoPago and your bank.",
        "Non-refundable rates are not refunded, including when you do not arrive.",
        "If we cancel your reservation for reasons attributable to the hotel, you receive a full refund.",
      ],
    },
    {
      title: "4. Guest responsibilities",
      body: [
        "The hotel has non-smoking rooms; smoking in a room may incur a cleaning fee. Damage to facilities or furniture will be charged to the guest.",
        "The maximum capacity of each room must be respected. Extra guests must be registered at reception.",
        "Use the in-room safe for valuables. The hotel's liability is limited to the extent permitted by Peruvian law.",
      ],
    },
    {
      title: "5. Consumer rights and applicable law",
      body: [
        "These terms are governed by the laws of Peru. As a consumer you are protected by the Consumer Protection and Defense Code (Law No. 29571), enforced by INDECOPI, and lodging establishments are regulated by the Lodging Establishments Regulation (Supreme Decree No. 001-2015-MINCETUR, as amended). Nothing in these terms limits the rights the law grants you.",
        "Any dispute will first be addressed directly with the hotel. In Peru you may also file a complaint with INDECOPI.",
      ],
    },
    {
      title: "6. Contact",
      body: [`For questions about reservations, cancellations or these terms, write to ${siteSettings.email} or call us at ${siteSettings.phone}.`],
    },
  ],
  es: [
    {
      title: "1. Reservas y pago",
      body: [
        "Las reservas se realizan en este sitio web a las tarifas publicadas, expresadas en dólares americanos (USD). El cobro se realiza en soles peruanos (PEN) al tipo de cambio que fijamos para tu reserva al cotizarla. Ese tipo de cambio queda fijo para la reserva y es el que se usa para el pago. Si tu tarjeta es de otra moneda, tu banco o emisor puede aplicar su propio tipo de cambio o comisiones.",
        "El pago lo procesa MercadoPago. Tu reserva queda confirmada cuando el pago es aprobado y recibes el correo de confirmación. La habitación se mantiene bloqueada por tiempo limitado mientras pagas; si el bloqueo vence, la reserva no queda confirmada.",
        "Las tarifas no reembolsables están marcadas durante la reserva. Los huéspedes adicionales a la capacidad de la habitación deben registrarse y pueden generar el cargo por huésped adicional mostrado durante la reserva.",
      ],
    },
    {
      title: "2. Check-in y check-out",
      body: [
        `El check-in está disponible desde las ${siteSettings.checkinTime} y el check-out hasta las ${siteSettings.checkoutTime}. El ingreso anticipado y la salida tardía dependen de la disponibilidad y se solicitan en recepción.`,
        "Contamos con custodia de equipaje gratuita antes del check-in y después del check-out.",
      ],
    },
    {
      title: "3. Cancelaciones y reembolsos",
      body: [
        "Para cancelar o modificar una reserva, contáctanos por correo o teléfono lo antes posible. Los reembolsos de tarifas reembolsables se realizan por MercadoPago, al mismo medio de pago; el tiempo en que el monto aparece depende de MercadoPago y de tu banco.",
        "Las tarifas no reembolsables no se reembolsan, tampoco si no llegas.",
        "Si cancelamos tu reserva por razones atribuibles al hotel, recibes el reembolso total.",
      ],
    },
    {
      title: "4. Responsabilidades del huésped",
      body: [
        "El hotel tiene habitaciones para no fumadores; fumar en la habitación puede generar un cargo por limpieza. Los daños a instalaciones o mobiliario se cargan al huésped.",
        "Debe respetarse la capacidad máxima de cada habitación. Los huéspedes adicionales deben registrarse en recepción.",
        "Usa la caja de seguridad para objetos de valor. La responsabilidad del hotel se limita a lo que permita la ley peruana.",
      ],
    },
    {
      title: "5. Derechos del consumidor y ley aplicable",
      body: [
        "Estos términos se rigen por las leyes del Perú. Como consumidor estás protegido por el Código de Protección y Defensa del Consumidor (Ley N. 29571), cuya autoridad es INDECOPI, y los establecimientos de hospedaje se rigen por el Reglamento de Establecimientos de Hospedaje (Decreto Supremo N. 001-2015-MINCETUR, modificado). Nada en estos términos limita los derechos que te reconoce la ley.",
        "Cualquier controversia se atenderá primero directamente con el hotel. En el Perú también puedes presentar un reclamo ante INDECOPI.",
      ],
    },
    {
      title: "6. Contacto",
      body: [`Para consultas sobre reservas, cancelaciones o estos términos, escribe a ${siteSettings.email} o llámanos al ${siteSettings.phone}.`],
    },
  ],
  fr: [
    {
      title: "1. Réservations et paiement",
      body: [
        "Les réservations se font sur ce site aux tarifs publiés, exprimés en dollars américains (USD). Le débit est effectué en soles péruviens (PEN) au taux de change que nous fixons pour votre réservation au moment du devis. Ce taux est figé pour la réservation et c'est celui appliqué au paiement. Si votre carte est dans une autre devise, votre banque ou l'émetteur de la carte peut appliquer son propre taux ou des frais.",
        "Le paiement est traité par MercadoPago. Votre réservation est confirmée lorsque le paiement est approuvé et que vous recevez l'e-mail de confirmation. La chambre est bloquée pendant une durée limitée le temps du paiement ; si le blocage expire, la réservation n'est pas confirmée.",
        "Les tarifs non remboursables sont signalés lors de la réservation. Les voyageurs supplémentaires au-delà de la capacité de la chambre doivent être déclarés et peuvent entraîner les frais de voyageur supplémentaire affichés pendant la réservation.",
      ],
    },
    {
      title: "2. Arrivée et départ",
      body: [
        `L'arrivée est possible à partir de ${siteSettings.checkinTime} et le départ jusqu'à ${siteSettings.checkoutTime}. Une arrivée anticipée ou un départ tardif dépendent des disponibilités et se demandent à la réception.`,
        "Une consigne à bagages gratuite est disponible avant l'arrivée et après le départ.",
      ],
    },
    {
      title: "3. Annulation et remboursements",
      body: [
        "Pour annuler ou modifier une réservation, contactez-nous par e-mail ou par téléphone dès que possible. Les remboursements des tarifs remboursables sont effectués via MercadoPago, sur le même moyen de paiement ; le délai d'apparition du montant dépend de MercadoPago et de votre banque.",
        "Les tarifs non remboursables ne sont pas remboursés, y compris en cas de non-présentation.",
        "Si nous annulons votre réservation pour des raisons imputables à l'hôtel, vous êtes remboursé intégralement.",
      ],
    },
    {
      title: "4. Responsabilités du client",
      body: [
        "L'hôtel dispose de chambres non-fumeurs ; fumer dans une chambre peut entraîner des frais de nettoyage. Les dommages aux installations ou au mobilier sont facturés au client.",
        "La capacité maximale de chaque chambre doit être respectée. Les voyageurs supplémentaires doivent être déclarés à la réception.",
        "Utilisez le coffre-fort pour les objets de valeur. La responsabilité de l'hôtel est limitée dans la mesure permise par la loi péruvienne.",
      ],
    },
    {
      title: "5. Droits du consommateur et droit applicable",
      body: [
        "Ces conditions sont régies par le droit péruvien. En tant que consommateur, vous êtes protégé par le Code de protection et de défense du consommateur (loi n° 29571), appliqué par l'INDECOPI, et les établissements d'hébergement relèvent du Règlement des établissements d'hébergement (décret suprême n° 001-2015-MINCETUR, modifié). Aucune clause de ces conditions ne limite les droits que la loi vous accorde.",
        "Tout litige sera d'abord traité directement avec l'hôtel. Au Pérou, vous pouvez également déposer une réclamation auprès de l'INDECOPI.",
      ],
    },
    {
      title: "6. Contact",
      body: [`Pour toute question sur les réservations, les annulations ou ces conditions, écrivez à ${siteSettings.email} ou appelez le ${siteSettings.phone}.`],
    },
  ],
  pt: [
    {
      title: "1. Reservas e pagamento",
      body: [
        "As reservas são feitas neste site às tarifas publicadas, expressas em dólares americanos (USD). O débito é feito em soles peruanos (PEN) à taxa de câmbio que fixamos para a sua reserva no momento da cotação. Essa taxa fica fixa para a reserva e é a usada no pagamento. Se o seu cartão for de outra moeda, o seu banco ou emissor pode aplicar a sua própria taxa ou comissões.",
        "O pagamento é processado pela MercadoPago. A reserva fica confirmada quando o pagamento é aprovado e você recebe o e-mail de confirmação. O quarto fica bloqueado por tempo limitado enquanto você paga; se o bloqueio expirar, a reserva não fica confirmada.",
        "As tarifas não reembolsáveis estão assinaladas durante a reserva. Os hóspedes adicionais acima da capacidade do quarto devem ser registrados e podem gerar o encargo por hóspede adicional mostrado durante a reserva.",
      ],
    },
    {
      title: "2. Check-in e check-out",
      body: [
        `O check-in está disponível a partir das ${siteSettings.checkinTime} e o check-out até às ${siteSettings.checkoutTime}. A entrada antecipada e a saída tardia dependem da disponibilidade e são pedidas na recepção.`,
        "Temos guarda de bagagem gratuita antes do check-in e depois do check-out.",
      ],
    },
    {
      title: "3. Cancelamentos e reembolsos",
      body: [
        "Para cancelar ou alterar uma reserva, entre em contato por e-mail ou telefone o mais cedo possível. Os reembolsos das tarifas reembolsáveis são feitos pela MercadoPago, no mesmo meio de pagamento; o tempo até o valor aparecer depende da MercadoPago e do seu banco.",
        "As tarifas não reembolsáveis não são reembolsadas, incluindo se você não comparecer.",
        "Se cancelarmos a sua reserva por razões imputáveis ao hotel, você recebe o reembolso total.",
      ],
    },
    {
      title: "4. Responsabilidades do hóspede",
      body: [
        "O hotel tem quartos para não fumantes; fumar no quarto pode gerar um encargo de limpeza. Os danos nas instalações ou mobiliário são cobrados ao hóspede.",
        "A capacidade máxima de cada quarto deve ser respeitada. Os hóspedes adicionais devem ser registrados na recepção.",
        "Use o cofre para objetos de valor. A responsabilidade do hotel está limitada na medida permitida pela lei peruana.",
      ],
    },
    {
      title: "5. Direitos do consumidor e lei aplicável",
      body: [
        "Estes termos regem-se pelas leis do Peru. Como consumidor, você está protegido pelo Código de Proteção e Defesa do Consumidor (Lei n.º 29571), aplicado pelo INDECOPI, e os estabelecimentos de hospedagem regem-se pelo Regulamento de Estabelecimentos de Hospedagem (Decreto Supremo n.º 001-2015-MINCETUR, alterado). Nada nestes termos limita os direitos que a lei lhe reconhece.",
        "Qualquer litígio será tratado primeiro diretamente com o hotel. No Peru você também pode apresentar reclamação ao INDECOPI.",
      ],
    },
    {
      title: "6. Contato",
      body: [`Para dúvidas sobre reservas, cancelamentos ou estes termos, escreva para ${siteSettings.email} ou ligue para ${siteSettings.phone}.`],
    },
  ],
};
