// src/data/legal.ts
// Contenido legal del sitio: privacidad (Ley 29733 + GDPR) y términos (Ley 29571).
// Solo en/es: fr -> en y pt -> es vía fallback de rutas (astro.config.mjs).
// Nota: el texto ES lleva tildes (documento legal); el resto del sitio usa ASCII
// por convención de los JSON de i18n.
import { siteSettings } from "./settings";

export interface LegalSection {
  title: string;
  body: string[];
}

export const privacySections: Record<"en" | "es", LegalSection[]> = {
  en: [
    {
      title: "1. Data controller",
      body: [
        `USGAR Hotels (${siteSettings.address.en}, Peru). For any question about this policy or your personal data, contact us at ${siteSettings.email}.`,
      ],
    },
    {
      title: "2. Data we collect",
      body: [
        "Booking form: name, email address, phone number, travel dates and number of guests. This data is required to create and manage your reservation.",
        "Newsletter form: email address, only if you subscribe voluntarily.",
        "Payment: card payments are processed by MercadoPago on their secure platform. We never see or store your card number.",
        "Technical data: a session cookie (HTTP-only) keeps you logged in and secures your booking. IP address may be logged for security and fraud prevention.",
      ],
    },
    {
      title: "3. Why we process your data (legal basis)",
      body: [
        "To execute the booking contract you request (accommodation, payments, confirmations).",
        "To comply with Peruvian legal obligations (tax and lodging records).",
        "For the newsletter, only with your explicit consent, which you can withdraw at any time.",
        "Legitimate interest in securing the website and preventing fraud.",
      ],
    },
    {
      title: "4. Cookies",
      body: [
        "This website only uses strictly necessary cookies (the session cookie that keeps your booking and login secure). Under Peruvian law (Law No. 29733) and the GDPR, necessary cookies do not require consent.",
        "When you use the payment form, MercadoPago may set its own cookies on their domain, governed by their privacy policy.",
        "We do not use marketing, advertising or analytics cookies.",
      ],
    },
    {
      title: "5. Your rights",
      body: [
        "Peru - Law No. 29733: you may request access, rectification, cancellation or opposition to the processing of your data. We will respond within the legal deadlines (20 business days for access, 10 for rectification, cancellation or opposition).",
        "EU/EEA guests - GDPR: right of access, rectification, erasure, restriction, portability and objection. You may file a complaint with your local data protection authority.",
        "To exercise any right, write to us at " + siteSettings.email + " identifying yourself.",
      ],
    },
    {
      title: "6. Retention and security",
      body: [
        "Booking data is kept for the duration of your stay and for the period required by Peruvian tax and lodging regulations. Newsletter data is kept until you unsubscribe.",
        "We use HTTPS, hashed/encrypted session tokens and access controls. Data is not sold or transferred to third parties except for payment processing (MercadoPago) and legal requirements.",
      ],
    },
  ],
  es: [
    {
      title: "1. Titular del tratamiento",
      body: [
        `USGAR Hotels (${siteSettings.address.es}, Perú). Para cualquier consulta sobre esta política o tus datos personales, escríbenos a ${siteSettings.email}.`,
      ],
    },
    {
      title: "2. Datos que recopilamos",
      body: [
        "Formulario de reserva: nombre, correo electrónico, teléfono, fechas de viaje y número de huéspedes. Estos datos son necesarios para crear y gestionar tu reserva.",
        "Formulario de newsletter: correo electrónico, solo si te suscribes voluntariamente.",
        "Pagos: los pagos con tarjeta los procesa MercadoPago en su plataforma segura. Nunca vemos ni almacenamos el número de tu tarjeta.",
        "Datos técnicos: una cookie de sesión (HTTP-only) mantiene tu sesión iniciada y protege tu reserva. La dirección IP puede registrarse por seguridad y prevención de fraude.",
      ],
    },
    {
      title: "3. Finalidad del tratamiento (base legal)",
      body: [
        "Ejecutar el contrato de reserva que solicitas (alojamiento, pagos, confirmaciones).",
        "Cumplir obligaciones legales peruanas (registros tributarios y de hospedaje).",
        "Para la newsletter, solo con tu consentimiento expreso, que puedes retirar en cualquier momento.",
        "Interés legítimo en proteger el sitio web y prevenir el fraude.",
      ],
    },
    {
      title: "4. Cookies",
      body: [
        "Este sitio web solo usa cookies estrictamente necesarias (la cookie de sesión que protege tu reserva e inicio de sesión). Conforme a la Ley N. 29733 y al GDPR, las cookies necesarias no requieren consentimiento.",
        "Al usar el formulario de pago, MercadoPago puede establecer sus propias cookies en su dominio, regidas por su política de privacidad.",
        "No usamos cookies de marketing, publicidad ni analítica.",
      ],
    },
    {
      title: "5. Tus derechos",
      body: [
        "Perú - Ley N. 29733: puedes solicitar acceso, rectificación, cancelación u oposición al tratamiento de tus datos. Responderemos dentro de los plazos legales (20 días hábiles para acceso, 10 para rectificación, cancelación u oposición).",
        "Huéspedes UE/EEE - GDPR: derecho de acceso, rectificación, supresión, limitación, portabilidad y oposición. Puedes presentar una reclamación ante tu autoridad de protección de datos.",
        "Para ejercer cualquier derecho, escríbenos a " + siteSettings.email + " identificándote.",
      ],
    },
    {
      title: "6. Conservación y seguridad",
      body: [
        "Los datos de reserva se conservan durante tu estadía y el período exigido por la normativa tributaria y de hospedaje peruana. Los datos de newsletter se conservan hasta que te des de baja.",
        "Usamos HTTPS, tokens de sesión cifrados y control de accesos. No vendemos ni cedemos datos a terceros, salvo para el procesamiento de pagos (MercadoPago) y obligaciones legales.",
      ],
    },
  ],
};

export const termsSections: Record<"en" | "es", LegalSection[]> = {
  en: [
    {
      title: "1. Bookings and payment",
      body: [
        `Reservations are made on this website at the published rates. Prices are shown in US dollars (USD); payment is collected in Peruvian soles (PEN) through MercadoPago. The amount charged may vary with the exchange rate applied by MercadoPago at the moment of payment.`,
        "Your reservation is confirmed once payment is approved and you receive the confirmation email. Non-refundable rates are clearly marked at the time of booking.",
      ],
    },
    {
      title: "2. Check-in and check-out",
      body: [
        `Check-in is available from ${siteSettings.checkinTime} and check-out until ${siteSettings.checkoutTime}. Early check-in or late check-out depend on availability and can be requested at the front desk.`,
        "Free luggage storage is available before check-in and after check-out.",
      ],
    },
    {
      title: "3. Cancellation and refunds",
      body: [
        "To cancel or modify a reservation, contact us by email or phone as soon as possible. Refunds for refundable rates are processed through the same payment method used, within the periods established by MercadoPago.",
        "Non-refundable rates are not reimbursed. No-shows are charged in full.",
        "If we must cancel your reservation for reasons attributable to the hotel, you will be refunded in full.",
      ],
    },
    {
      title: "4. Guest responsibilities",
      body: [
        "The hotel has non-smoking rooms; smoking in the room may incur a cleaning fee. Any damage to facilities or furniture will be charged to the guest.",
        "Rooms have a maximum capacity that must be respected. Extra guests must be registered at reception.",
        "The hotel is not responsible for valuables left in the room; use the in-room safe.",
      ],
    },
    {
      title: "5. Consumer rights and applicable law",
      body: [
        "These terms are governed by the laws of Peru. As a consumer, you are protected by the Consumer Protection and Defense Code (Law No. 29571), enforced by INDECOPI, and by the Regulations for Lodging Establishments (Supreme Decree No. 001-2015-MINCETUR).",
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
        `Las reservas se realizan en este sitio web a las tarifas publicadas. Los precios se muestran en dólares americanos (USD); el cobro se realiza en soles peruanos (PEN) a través de MercadoPago. El monto cobrado puede variar según el tipo de cambio que aplique MercadoPago al momento del pago.`,
        "Tu reserva queda confirmada cuando el pago es aprobado y recibes el correo de confirmación. Las tarifas no reembolsables están claramente indicadas al momento de reservar.",
      ],
    },
    {
      title: "2. Check-in y check-out",
      body: [
        `El check-in está disponible desde las ${siteSettings.checkinTime} y el check-out hasta las ${siteSettings.checkoutTime}. El ingreso anticipado o salida tardía dependen de la disponibilidad y pueden solicitarse en recepción.`,
        "Contamos con custodia de equipaje gratuita antes del check-in y después del check-out.",
      ],
    },
    {
      title: "3. Cancelaciones y reembolsos",
      body: [
        "Para cancelar o modificar una reserva, contáctanos por correo o teléfono lo antes posible. Los reembolsos de tarifas reembolsables se procesan por el mismo medio de pago utilizado, dentro de los plazos que establece MercadoPago.",
        "Las tarifas no reembolsables no se reembolsan. Las ausencias sin aviso (no-show) se cobran en su totalidad.",
        "Si debemos cancelar tu reserva por razones atribuibles al hotel, se te reembolsará la totalidad.",
      ],
    },
    {
      title: "4. Responsabilidades del huésped",
      body: [
        "El hotel cuenta con habitaciones para no fumadores; fumar en la habitación puede generar un cargo por limpieza. Cualquier daño a las instalaciones o muebles será cargado al huésped.",
        "Las habitaciones tienen una capacidad máxima que debe respetarse. Los huéspedes adicionales deben registrarse en recepción.",
        "El hotel no se responsabiliza por objetos de valor dejados en la habitación; utiliza la caja de seguridad.",
      ],
    },
    {
      title: "5. Derechos del consumidor y ley aplicable",
      body: [
        "Estos términos se rigen por las leyes del Perú. Como consumidor, estás protegido por el Código de Protección y Defensa del Consumidor (Ley N. 29571), cuya autoridad es INDECOPI, y por el Reglamento de Establecimientos de Hospedaje (Decreto Supremo N. 001-2015-MINCETUR).",
        "Cualquier controversia se resolverá primero directamente con el hotel. En el Perú también puedes presentar una queja ante INDECOPI.",
      ],
    },
    {
      title: "6. Contacto",
      body: [`Para consultas sobre reservas, cancelaciones o estos términos, escribe a ${siteSettings.email} o llámanos al ${siteSettings.phone}.`],
    },
  ],
};
