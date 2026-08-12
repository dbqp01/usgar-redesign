// Formateo compartido de precio y fecha — extraido de las implementaciones
// inline duplicadas (contrato: tests/Unit/format.test.ts, W3/21 behavior lock).
// (audit 2026-08-12: format.test.ts existe; profile.astro, my-bookings.astro,
// success.astro y BookingCalendarStep importan estos helpers desde utils/format)
//
//   formatDate  <- success.astro:321-326 (dateLocales:284 + opciones:324) y
//                  los aria-label full de BookingWidget.astro:188 /
//                  BookingCalendarStep.astro:190 (mismas opciones full).
//   formatPrice <- profile.astro:450 + my-bookings.astro:180
//                  ("$" + toFixed(2) + " USD", insensible a locale) con el
//                  simbolo PEN de success.astro:329 (currency === 'PEN' ? 'S/.' : '$').

/** Locale de la UI ('en'|'es'|'fr'|'pt') -> locale Intl (success.astro:284). Desconocido -> 'en-US'. */
const DATE_LOCALES: Record<string, string> = {
  en: 'en-US',
  es: 'es-PE',
  fr: 'fr-FR',
  pt: 'pt-PT',
};

/** Opciones full (success.astro:324). */
const FULL_DATE_OPTIONS: Intl.DateTimeFormatOptions = {
  weekday: 'long',
  year: 'numeric',
  month: 'long',
  day: 'numeric',
};

/**
 * Fecha en formato largo localizado: "jueves, 6 de agosto de 2026".
 * Acepta Date o string ISO 'YYYY-MM-DD' (se le agrega 'T00:00:00', igual que
 * success.astro:323). String vacio -> '' (guard de success.astro:322).
 */
export function formatDate(date: Date | string, locale: string): string {
  if (!date) return '';
  const d = typeof date === 'string' ? new Date(`${date}T00:00:00`) : date;
  return d.toLocaleDateString(DATE_LOCALES[locale] ?? 'en-US', FULL_DATE_OPTIONS);
}

/**
 * Precio: simbolo + Number(amount).toFixed(2) + " " + currency.
 * Sin agrupacion de miles y SIN Intl.NumberFormat (igual que profile.astro:450 /
 * my-bookings.astro:180); locale NO altera la salida hoy.
 */
export function formatPrice(amount: number, currency: string, locale: string = 'en'): string {
  const intlLocale = DATE_LOCALES[locale] ?? 'en-US';
  try {
    return new Intl.NumberFormat(intlLocale, {
      style: 'currency',
      currency: currency || 'USD',
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(Number(amount));
  } catch {
    const symbol = currency === 'PEN' ? 'S/.' : '$';
    return `${symbol}${Number(amount).toFixed(2)} ${currency}`;
  }
}
