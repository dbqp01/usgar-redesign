// src/data/legal.ts
// Contenido legal del sitio en 4 idiomas: privacidad (Ley 29733 + su reglamento
// DS 016-2024-JUS + GDPR) y términos (Ley 29571 / INDECOPI + DS 001-2015-MINCETUR).
// Cada afirmación de esta copia se contrasta con el código (cookies, almacenamiento,
// encargados, cifrado, consulta por WhatsApp) y con la norma vigente: ver tests/Unit/legal.test.ts.
// Nota: el texto ES lleva tildes (documento legal); el resto del sitio usa ASCII
// por convención de los JSON de i18n.
import { siteSettings } from "./settings";
import { USD_TO_PEN } from "../utils/pricing";

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
  en: "October 30, 2026",
  es: "30 de octubre de 2026",
  fr: "30 octobre 2026",
  pt: "30 de outubro de 2026",
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
        "Booking enquiry: name, email address, phone number, check-in and check-out dates, number of guests, room and rate you enquire about, language, special requests and, if you ask for the airport transfer, your flight details. You send this data through WhatsApp, the contact form, email or phone. When you use WhatsApp, your message is also processed by WhatsApp (Meta) under its own privacy policy. We use this data to answer your enquiry, confirm availability and price and, if you go ahead, manage your stay.",
        "Contact form: name, email address, subject and message, so that we can answer your request.",
        "Account, if you create one: email address, first and last name, phone number and, when you sign in with Google, Microsoft or Facebook, the profile photo and account identifier those services share with us. If you set a password, we store only a hash of it.",
        "Newsletter, if you subscribe: email address, language, the IP address and browser identifier of the subscription request, and the date and version of the consent notice you accepted.",
        "Payments: the payment method is agreed with the hotel. If you pay online through MercadoPago, card details are entered in MercadoPago's payment form and sent to MercadoPago. Payment processing may also involve the payer's name, email, phone, identification, device information, payment result, amount and transaction identifier. We do not store your full card number.",
        "Technical data: IP address, browser information and security logs, used to keep the site online and prevent abuse.",
      ],
    },
    {
      title: "3. Why we use your data",
      body: [
        "To answer your enquiry and, if you go ahead, perform the booking contract: confirm availability and price, register the reservation, send the confirmation and pre-arrival information, and register your stay in our property management system.",
        "To comply with Peruvian law, including tax and lodging records.",
        "With your consent, to send the newsletter. We only send it after you confirm your address by email, and you can withdraw consent at any time with the unsubscribe link or by writing to us.",
        "For our legitimate interest in keeping the website secure, preventing fraud and limiting abusive requests.",
        "If you create an account, to authenticate you and show your bookings.",
      ],
    },
    {
      title: "4. Cookies and local storage",
      body: [
        "Strictly necessary cookies: usgar_session keeps you signed in and authorises your account actions (HttpOnly, 30 days); usgar_csrf protects authenticated actions against cross-site request forgery: our JavaScript reads it and sends it in the X-CSRF-Token header (30 days); usgar_panel and usgar_panel_csrf are used only by hotel staff in the internal panel (12 hours); usgar_auth_provider and usgar_auth_redirect (5 minutes) plus PHPSESSID (until you close the browser) exist only while you sign in with a social account.",
        "The site also stores your theme preference, the acknowledgement of the cookie notice and, when your sign-in state changes, a signal without profile data under usgar_auth_event in local storage so that other tabs update. In session storage it keeps a preloader flag, a summary of your profile, a tab identifier and the access token of a booking in progress. Session storage is erased when you close the tab.",
        "Third parties: when you contact us on WhatsApp, the message is processed by WhatsApp (Meta) under its privacy policy. The map loads tiles from CARTO and OpenStreetMap, which receive your IP address. If the hotel agrees an online payment with you, MercadoPago loads its payment form and may set its own cookies.",
        "We do not use analytics, advertising or marketing cookies. Strictly necessary cookies do not require consent under the GDPR and the ePrivacy Directive; we show a notice that you can acknowledge.",
      ],
    },
    {
      title: "5. Who receives your data",
      body: [
        "WhatsApp (Meta), when you write to us on that channel, under its own privacy policy. MercadoPago, if the hotel agrees an online payment with you. QloApps, the property management system where we record your reservation, at cms.usgarhoteles.com. Hostinger, which hosts the website and sends our email. Google, Microsoft or Facebook, only if you choose to sign in with them. CARTO and OpenStreetMap, which serve the map tiles. Public authorities, when the law requires it.",
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
        "We retain booking, enquiry, contact, account and newsletter records and, when a payment is agreed with the hotel, payment records. Retention depends on the purpose, applicable obligations and any outstanding requests or disputes. After you unsubscribe, your newsletter record remains marked as unsubscribed. Contact us for information about your records or to exercise your rights.",
        "We use HTTPS with HSTS, signed session tokens (HMAC-SHA256) in HttpOnly, Secure and SameSite=Lax cookies, double-submit CSRF tokens (X-CSRF-Token header) on authenticated actions, hashed passwords, access controls and request rate limits.",
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
        "Consulta de reserva: nombre, correo electrónico, teléfono, fechas de entrada y salida, número de huéspedes, habitación y tarifa consultadas, idioma, pedidos especiales y, si solicitas el traslado desde el aeropuerto, los datos de tu vuelo. Estos datos nos llegan por WhatsApp, el formulario de contacto, correo electrónico o teléfono. Si usas WhatsApp, tu mensaje también lo trata WhatsApp (Meta) según su propia política de privacidad. Usamos estos datos para responder a tu consulta, confirmar disponibilidad y precio y, si concretas la reserva, gestionar tu estadía.",
        "Formulario de contacto: nombre, correo electrónico, asunto y mensaje, para poder responderte.",
        "Cuenta, si la creas: correo electrónico, nombres y apellidos, teléfono y, si ingresas con Google, Microsoft o Facebook, la foto de perfil y el identificador de cuenta que esos servicios comparten con nosotros. Si defines una contraseña, guardamos solo un hash.",
        "Newsletter, si te suscribes: correo electrónico, idioma, dirección IP e identificador del navegador de la solicitud, y la fecha y versión del aviso de consentimiento que aceptaste.",
        "Pagos: la forma de pago se acuerda con el hotel. Si pagas en línea mediante MercadoPago, los datos de tarjeta se introducen en su formulario y se envían a MercadoPago. El procesamiento también puede incluir nombre, correo, teléfono, identificación del pagador, información del dispositivo, resultado, monto e identificador de la transacción. No almacenamos el número completo de tu tarjeta.",
        "Datos técnicos: dirección IP, información del navegador y registros de seguridad, usados para mantener el sitio disponible y prevenir abusos.",
      ],
    },
    {
      title: "3. Para qué usamos tus datos",
      body: [
        "Para atender tu consulta y, si concretas la reserva, ejecutar el contrato de reserva: confirmar disponibilidad y precio, registrar la reserva, enviar la confirmación y la información previa a la llegada, y registrar tu estadía en nuestro sistema de gestión hotelera.",
        "Para cumplir obligaciones legales peruanas, incluidos los registros tributarios y de hospedaje.",
        "Con tu consentimiento, para enviarte la newsletter. Solo la enviamos después de que confirmes tu dirección por correo, y puedes retirar el consentimiento en cualquier momento con el enlace de baja o escribiéndonos.",
        "Por interés legítimo, para mantener la seguridad del sitio, prevenir fraudes y limitar solicitudes abusivas.",
        "Si creas una cuenta, para autenticarte y mostrarte tus reservas.",
      ],
    },
    {
      title: "4. Cookies y almacenamiento local",
      body: [
        "Cookies estrictamente necesarias: usgar_session mantiene tu sesión iniciada y autoriza las acciones de tu cuenta (HttpOnly, 30 días); usgar_csrf protege las acciones autenticadas contra la falsificación de solicitudes: nuestro JavaScript lo lee y lo envía en el encabezado X-CSRF-Token (30 días); usgar_panel y usgar_panel_csrf los usa solo el personal del hotel en el panel interno (12 horas); usgar_auth_provider y usgar_auth_redirect (5 minutos) y PHPSESSID (hasta que cierras el navegador) existen solo mientras ingresas con una cuenta social.",
        "El sitio también guarda tu preferencia de tema, la confirmación del aviso de cookies y, cuando cambia tu estado de sesión, una señal sin datos de perfil bajo usgar_auth_event en almacenamiento local para actualizar las demás pestañas. En almacenamiento de sesión guarda una marca del preloader, un resumen de tu perfil, un identificador de pestaña y el token de acceso de una reserva en curso. El almacenamiento de sesión se borra al cerrar la pestaña.",
        "Terceros: cuando nos escribes por WhatsApp, el mensaje lo trata WhatsApp (Meta) según su política de privacidad. El mapa carga teselas de CARTO y OpenStreetMap, que reciben tu dirección IP. Si el hotel acuerda contigo un pago en línea, MercadoPago carga su formulario de pago y puede establecer sus propias cookies.",
        "No usamos cookies de analítica, publicidad ni marketing. Las cookies estrictamente necesarias no requieren consentimiento según el GDPR y la Directiva ePrivacy; mostramos un aviso que puedes confirmar.",
      ],
    },
    {
      title: "5. Quién recibe tus datos",
      body: [
        "WhatsApp (Meta), cuando nos escribes por ese canal, según su propia política de privacidad. MercadoPago, si el hotel acuerda contigo un pago en línea. QloApps, el sistema de gestión hotelera donde registramos tu reserva, en cms.usgarhoteles.com. Hostinger, que aloja el sitio web y envía nuestro correo. Google, Microsoft o Facebook, solo si eliges ingresar con ellos. CARTO y OpenStreetMap, que sirven las teselas del mapa. Autoridades públicas, cuando la ley lo exige.",
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
        "Conservamos registros de reservas, consultas, contacto, cuentas y newsletter y, cuando se acuerda un pago con el hotel, registros de pago. La conservación depende de la finalidad, las obligaciones aplicables y las solicitudes o controversias pendientes. Al darte de baja, tu registro de newsletter permanece marcado como dado de baja. Contáctanos para consultar sobre tus datos o ejercer tus derechos.",
        "Usamos HTTPS con HSTS, tokens de sesión firmados (HMAC-SHA256) en cookies HttpOnly, Secure y SameSite=Lax, tokens CSRF de doble envío (encabezado X-CSRF-Token) en las acciones autenticadas, contraseñas con hash, control de accesos y límites de peticiones.",
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
        "Demande de réservation : nom, adresse e-mail, téléphone, dates d'arrivée et de départ, nombre de voyageurs, chambre et tarif souhaités, langue, demandes particulières et, si vous demandez la navette aéroport, les informations de votre vol. Vous nous transmettez ces données par WhatsApp, le formulaire de contact, e-mail ou téléphone. Lorsque vous utilisez WhatsApp, votre message est aussi traité par WhatsApp (Meta) selon sa propre politique de confidentialité. Nous utilisons ces données pour répondre à votre demande, confirmer la disponibilité et le prix et, si vous donnez suite, gérer votre séjour.",
        "Formulaire de contact : nom, adresse e-mail, objet et message, afin de répondre à votre demande.",
        "Compte, si vous en créez un : adresse e-mail, prénom et nom, téléphone et, si vous vous connectez avec Google, Microsoft ou Facebook, la photo de profil et l'identifiant de compte transmis par ces services. Si vous définissez un mot de passe, nous n'en stockons qu'un hachage.",
        "Newsletter, si vous vous abonnez : adresse e-mail, langue, adresse IP et identifiant du navigateur de la demande d'abonnement, ainsi que la date et la version de l'avis de consentement accepté.",
        "Paiements : le moyen de paiement est convenu avec l'hôtel. Si vous payez en ligne via MercadoPago, les données de carte sont saisies dans son formulaire et transmises à MercadoPago. Le traitement peut aussi inclure le nom, l'adresse e-mail, le téléphone, l'identification du payeur, des informations sur l'appareil, le résultat, le montant et l'identifiant de transaction. Nous ne conservons pas le numéro complet de votre carte.",
        "Données techniques : adresse IP, informations sur le navigateur et journaux de sécurité, utilisés pour maintenir le site disponible et prévenir les abus.",
      ],
    },
    {
      title: "3. Pourquoi nous utilisons vos données",
      body: [
        "Pour répondre à votre demande et, si vous donnez suite, exécuter le contrat de réservation : confirmer la disponibilité et le prix, enregistrer la réservation, envoyer la confirmation et les informations avant l'arrivée, et enregistrer votre séjour dans notre système de gestion hôtelière.",
        "Pour respecter nos obligations légales péruviennes, notamment fiscales et d'enregistrement des voyageurs.",
        "Avec votre consentement, pour envoyer la newsletter. Nous ne l'envoyons qu'après confirmation de votre adresse par e-mail ; vous pouvez retirer votre consentement à tout moment via le lien de désabonnement ou en nous écrivant.",
        "Pour notre intérêt légitime à sécuriser le site, prévenir la fraude et limiter les demandes abusives.",
        "Si vous créez un compte, pour vous authentifier et afficher vos réservations.",
      ],
    },
    {
      title: "4. Cookies et stockage local",
      body: [
        "Cookies strictement nécessaires : usgar_session maintient votre session et autorise les actions de votre compte (HttpOnly, 30 jours) ; usgar_csrf protège les actions authentifiées contre la falsification de requêtes : notre JavaScript le lit et l'envoie dans l'en-tête X-CSRF-Token (30 jours) ; usgar_panel et usgar_panel_csrf ne servent qu'au personnel de l'hôtel dans le panneau interne (12 heures) ; usgar_auth_provider et usgar_auth_redirect (5 minutes) ainsi que PHPSESSID (jusqu'à la fermeture du navigateur) n'existent que pendant une connexion via un compte social.",
        "Le site stocke aussi votre préférence de thème, l'accusé de réception de l'avis cookies et, lorsque l'état de connexion change, un signal sans données de profil sous usgar_auth_event dans le stockage local pour actualiser les autres onglets. Dans le stockage de session, il conserve un indicateur de préchargement, un résumé de votre profil, un identifiant d'onglet et le jeton d'accès d'une réservation en cours. Le stockage de session est effacé à la fermeture de l'onglet.",
        "Tiers : lorsque vous nous contactez sur WhatsApp, le message est traité par WhatsApp (Meta) selon sa politique de confidentialité. La carte charge des tuiles depuis CARTO et OpenStreetMap, qui reçoivent votre adresse IP. Si l'hôtel convient avec vous d'un paiement en ligne, MercadoPago charge son formulaire et peut déposer ses propres cookies.",
        "Nous n'utilisons pas de cookies d'analyse, de publicité ou de marketing. Les cookies strictement nécessaires ne nécessitent pas de consentement au titre du RGPD et de la directive ePrivacy ; nous affichons un avis que vous pouvez confirmer.",
      ],
    },
    {
      title: "5. Qui reçoit vos données",
      body: [
        "WhatsApp (Meta), lorsque vous nous écrivez sur ce canal, selon sa propre politique de confidentialité. MercadoPago, si l'hôtel convient avec vous d'un paiement en ligne. QloApps, le système de gestion hôtelière où nous enregistrons votre réservation, sur cms.usgarhoteles.com. Hostinger, qui héberge le site et envoie nos e-mails. Google, Microsoft ou Facebook, uniquement si vous choisissez de vous connecter avec eux. CARTO et OpenStreetMap, qui fournissent les tuiles de la carte. Les autorités publiques, lorsque la loi l'exige.",
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
        "Nous conservons les données de réservation, de demande, de contact, de compte et de newsletter et, lorsqu'un paiement est convenu avec l'hôtel, les données de paiement. Leur conservation dépend de la finalité, des obligations applicables et des demandes ou litiges en cours. Après désabonnement, votre inscription reste marquée comme résiliée. Contactez-nous pour vous informer sur vos données ou exercer vos droits.",
        "Nous utilisons HTTPS avec HSTS, des jetons de session signés (HMAC-SHA256) dans des cookies HttpOnly, Secure et SameSite=Lax, des jetons CSRF à double soumission (en-tête X-CSRF-Token) sur les actions authentifiées, des mots de passe hachés, des contrôles d'accès et une limitation du débit des requêtes.",
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
        "Consulta de reserva: nome, e-mail, telefone, datas de entrada e saída, número de hóspedes, quarto e tarifa pretendidos, idioma, pedidos especiais e, se solicitar o transporte do aeroporto, os dados do seu voo. Estes dados chegam-nos pelo WhatsApp, formulário de contato, e-mail ou telefone. Se usar o WhatsApp, a sua mensagem também é tratada pelo WhatsApp (Meta) segundo a sua própria política de privacidade. Usamos estes dados para responder à sua consulta, confirmar disponibilidade e preço e, se avançar, gerir a sua estadia.",
        "Formulário de contato: nome, e-mail, assunto e mensagem, para podermos responder ao seu pedido.",
        "Conta, se criar uma: e-mail, nome e sobrenome, telefone e, se entrar com Google, Microsoft ou Facebook, a foto de perfil e o identificador de conta que esses serviços compartilham conosco. Se definir uma senha, guardamos apenas um hash.",
        "Newsletter, se assinar: e-mail, idioma, endereço IP e identificador do navegador do pedido de assinatura, e a data e versão do aviso de consentimento aceito.",
        "Pagamentos: a forma de pagamento é combinada com o hotel. Se pagar online pela MercadoPago, os dados do cartão são inseridos no formulário e enviados à MercadoPago. O processamento também pode incluir nome, e-mail, telefone, identificação do pagador, informações do dispositivo, resultado, valor e identificador da transação. Não armazenamos o número completo do cartão.",
        "Dados técnicos: endereço IP, informações do navegador e registros de segurança, usados para manter o site disponível e prevenir abusos.",
      ],
    },
    {
      title: "3. Para que usamos seus dados",
      body: [
        "Para atender à sua consulta e, se avançar, executar o contrato de reserva: confirmar disponibilidade e preço, registrar a reserva, enviar a confirmação e as informações antes da chegada, e registrar a sua estadia no nosso sistema de gestão hoteleira.",
        "Para cumprir obrigações legais peruanas, incluindo registros fiscais e de hospedagem.",
        "Com o seu consentimento, para enviar a newsletter. Só a enviamos depois que você confirmar o endereço por e-mail, e você pode retirar o consentimento a qualquer momento pelo link de cancelamento ou escrevendo para nós.",
        "Por interesse legítimo, para manter a segurança do site, prevenir fraudes e limitar pedidos abusivos.",
        "Se criar uma conta, para autenticar você e mostrar as suas reservas.",
      ],
    },
    {
      title: "4. Cookies e armazenamento local",
      body: [
        "Cookies estritamente necessários: usgar_session mantém a sessão iniciada e autoriza as ações da sua conta (HttpOnly, 30 dias); usgar_csrf protege as ações autenticadas contra falsificação de pedidos: o nosso JavaScript lê o token e envia-o no cabeçalho X-CSRF-Token (30 dias); usgar_panel e usgar_panel_csrf são usados apenas pelo pessoal do hotel no painel interno (12 horas); usgar_auth_provider e usgar_auth_redirect (5 minutos), além do PHPSESSID (até fechar o navegador), existem apenas durante a entrada com uma conta social.",
        "O site também guarda a sua preferência de tema, a confirmação do aviso de cookies e, quando o estado de sessão muda, um sinal sem dados de perfil sob usgar_auth_event no armazenamento local para atualizar as outras abas. No armazenamento de sessão guarda uma marca do preloader, um resumo do seu perfil, um identificador de aba e o token de acesso de uma reserva em andamento. O armazenamento de sessão é apagado ao fechar a aba.",
        "Terceiros: quando nos escreve pelo WhatsApp, a mensagem é tratada pelo WhatsApp (Meta) segundo a sua política de privacidade. O mapa carrega blocos da CARTO e do OpenStreetMap, que recebem o seu endereço IP. Se o hotel combinar consigo um pagamento online, a MercadoPago carrega o seu formulário e pode definir os seus próprios cookies.",
        "Não usamos cookies de análise, publicidade ou marketing. Os cookies estritamente necessários não exigem consentimento ao abrigo do RGPD e da Diretiva ePrivacy; mostramos um aviso que você pode confirmar.",
      ],
    },
    {
      title: "5. Quem recebe seus dados",
      body: [
        "WhatsApp (Meta), quando nos escreve por esse canal, segundo a sua própria política de privacidade. MercadoPago, se o hotel combinar consigo um pagamento online. QloApps, o sistema de gestão hoteleira onde registramos a sua reserva, em cms.usgarhoteles.com. Hostinger, que hospeda o site e envia os nossos e-mails. Google, Microsoft ou Facebook, apenas se escolher entrar com eles. CARTO e OpenStreetMap, que fornecem os blocos do mapa. Autoridades públicas, quando a lei exige.",
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
        "Conservamos registros de reservas, consultas, contato, contas e newsletter e, quando um pagamento é combinado com o hotel, registros de pagamento. A conservação depende da finalidade, das obrigações aplicáveis e de solicitações ou disputas pendentes. Após o cancelamento, seu registro da newsletter permanece marcado como cancelado. Entre em contato para consultar seus dados ou exercer seus direitos.",
        "Usamos HTTPS com HSTS, tokens de sessão assinados (HMAC-SHA256) em cookies HttpOnly, Secure e SameSite=Lax, tokens CSRF de dupla submissão (cabeçalho X-CSRF-Token) nas ações autenticadas, senhas com hash, controle de acessos e limites de pedidos.",
      ],
    },
  ],
};

export const termsSections: Record<LegalLocale, LegalSection[]> = {
  en: [
    {
      title: "1. Bookings and payment",
      body: [
        "You can enquire about a stay through the WhatsApp buttons on this site, or by email or phone. An enquiry does not hold a room and is not a confirmed reservation.",
        `The site shows rates in US dollars (USD) and an estimated equivalent in Peruvian soles (PEN) at S/ ${USD_TO_PEN.toFixed(2)} per USD. Before you book, the hotel confirms availability, the final price, the rate and cancellation conditions and the payment method. Your card issuer may apply its own exchange rate or fees.`,
        "Non-refundable rates and any extra-guest charges are communicated before you accept the booking. The maximum room capacity must be respected.",
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
        "To cancel or modify a reservation, contact us on WhatsApp, by email or by phone as soon as possible. The cancellation and refund conditions that apply are those the hotel confirms before you book. Refunds, where they apply, are made by the same payment method agreed with the hotel; how quickly the amount appears depends on that method and your bank.",
        "Non-refundable rates, if confirmed, are not refunded, including when you do not arrive.",
        "If we cancel your reservation for reasons attributable to the hotel, you receive a full refund of what you paid.",
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
      body: [`For questions about reservations, cancellations or these terms, write to ${siteSettings.email} or call us at ${siteSettings.phone}; you can also message us on WhatsApp from the buttons on this site.`],
    },
  ],
  es: [
    {
      title: "1. Reservas y pago",
      body: [
        "Puedes consultar tu estadía mediante los botones de WhatsApp de este sitio, por correo electrónico o por teléfono. Una consulta no bloquea la habitación ni constituye una reserva confirmada.",
        `El sitio muestra tarifas en dólares americanos (USD) y una equivalencia estimada en soles peruanos (PEN) al tipo de S/ ${USD_TO_PEN.toFixed(2)} por USD. Antes de contratar, el hotel confirma la disponibilidad, el precio final, las condiciones de la tarifa y cancelación, y la forma de pago. Tu banco puede aplicar su propio tipo de cambio o comisiones.`,
        "Las tarifas no reembolsables y los cargos por huéspedes adicionales se informan antes de que aceptes la reserva. Debe respetarse la capacidad máxima de la habitación.",
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
        "Para cancelar o modificar una reserva, contáctanos por WhatsApp, correo electrónico o teléfono lo antes posible. Las condiciones de cancelación y reembolso aplicables son las que el hotel confirme antes de contratar. Los reembolsos, cuando correspondan, se realizan por el mismo medio de pago acordado con el hotel; el plazo depende de ese medio y de tu banco.",
        "Las tarifas no reembolsables, si se confirman, no se reembolsan, tampoco si no llegas.",
        "Si cancelamos tu reserva por razones atribuibles al hotel, recibes el reembolso total de lo que pagaste.",
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
      body: [`Para consultas sobre reservas, cancelaciones o estos términos, escribe a ${siteSettings.email} o llámanos al ${siteSettings.phone}; también puedes escribirnos por WhatsApp desde los botones de este sitio.`],
    },
  ],
  fr: [
    {
      title: "1. Réservations et paiement",
      body: [
        "Vous pouvez demander un séjour par les boutons WhatsApp du site, par e-mail ou par téléphone. Une demande ne bloque pas la chambre et ne constitue pas une réservation confirmée.",
        `Le site affiche les tarifs en dollars américains (USD) et leur équivalent estimé en soles péruviens (PEN) au taux de S/ ${USD_TO_PEN.toFixed(2)} par USD. Avant la réservation, l'hôtel confirme la disponibilité, le prix final, les conditions tarifaires et d'annulation et le moyen de paiement. Votre banque peut appliquer son propre taux ou des frais.`,
        "Les tarifs non remboursables et les frais pour voyageurs supplémentaires sont communiqués avant votre acceptation. La capacité maximale de la chambre doit être respectée.",
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
        "Pour annuler ou modifier une réservation, contactez-nous sur WhatsApp, par e-mail ou par téléphone dès que possible. Les conditions d'annulation et de remboursement applicables sont celles que l'hôtel confirme avant la réservation. Les remboursements, lorsqu'ils s'appliquent, sont effectués par le même moyen de paiement convenu avec l'hôtel ; le délai d'apparition du montant dépend de ce moyen et de votre banque.",
        "Les tarifs non remboursables, s'ils sont confirmés, ne sont pas remboursés, y compris en cas de non-présentation.",
        "Si nous annulons votre réservation pour des raisons imputables à l'hôtel, vous êtes remboursé intégralement de ce que vous avez payé.",
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
      body: [`Pour toute question sur les réservations, les annulations ou ces conditions, écrivez à ${siteSettings.email} ou appelez le ${siteSettings.phone} ; vous pouvez aussi nous écrire sur WhatsApp depuis les boutons de ce site.`],
    },
  ],
  pt: [
    {
      title: "1. Reservas e pagamento",
      body: [
        "Você pode consultar sua estadia pelos botões de WhatsApp deste site, por e-mail ou por telefone. Uma consulta não bloqueia o quarto nem constitui uma reserva confirmada.",
        `O site mostra tarifas em dólares americanos (USD) e um equivalente estimado em soles peruanos (PEN) à taxa de S/ ${USD_TO_PEN.toFixed(2)} por USD. Antes da reserva, o hotel confirma disponibilidade, preço final, condições da tarifa e do cancelamento e forma de pagamento. Seu banco pode aplicar outra taxa ou comissões.`,
        "As tarifas não reembolsáveis e os encargos por hóspedes adicionais são informados antes de você aceitar a reserva. A capacidade máxima do quarto deve ser respeitada.",
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
        "Para cancelar ou alterar uma reserva, entre em contato pelo WhatsApp, por e-mail ou telefone o mais cedo possível. As condições de cancelamento e reembolso aplicáveis são as que o hotel confirmar antes de contratar. Os reembolsos, quando aplicáveis, são feitos pelo mesmo meio de pagamento combinado com o hotel; o tempo até o valor aparecer depende desse meio e do seu banco.",
        "As tarifas não reembolsáveis, se confirmadas, não são reembolsadas, incluindo se você não comparecer.",
        "Se cancelarmos a sua reserva por razões imputáveis ao hotel, você recebe o reembolso total do que pagou.",
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
      body: [`Para dúvidas sobre reservas, cancelamentos ou estes termos, escreva para ${siteSettings.email} ou ligue para ${siteSettings.phone}; também pode escrever-nos pelo WhatsApp nos botões deste site.`],
    },
  ],
};
