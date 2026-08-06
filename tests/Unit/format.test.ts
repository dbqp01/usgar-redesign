import { describe, it, expect } from 'vitest';
import { formatPrice, formatDate } from '../../src/utils/format';

// =============================================================================
// CONTRATO de src/utils/format (W3/21 behavior lock -> implementado en W3/23).
// Estas aserciones PINNAN las salidas EXACTAS que la UI actual produce hoy,
// derivadas de las implementaciones inline que reemplaza la extraccion:
//
//   formatDate  <- success.astro:321-326 (dateLocales en:284 + opciones en:324)
//                 + BookingWidget.astro:188 / BookingCalendarStep.astro:190
//                 (mismas opciones full; los 3 producen el mismo string en Node)
//   formatPrice <- profile.astro:450 + my-bookings.astro:180 (patron duplicado
//                 "$" + toFixed(2) + " USD", insensible a locale/agrupacion)
//                 + simbolo PEN de success.astro:329 (currency === 'PEN' ? 'S/.' : '$')
//
// La extraccion DEBE producir estos strings identicos (regresion de UX = 0).
// Valores de referencia capturados en Node 22 (full-icu), el runtime de vitest.
// =============================================================================

describe('formatDate(date, locale) — contrato de success.astro formatDate + BookingWidget dateLabel', () => {
  // Locale mapping: { en:'en-US', es:'es-PE', fr:'fr-FR', pt:'pt-PT' }, fallback 'en-US' (success.astro:284,325)
  // Options: { weekday:'long', year:'numeric', month:'long', day:'numeric' } (success.astro:324)
  // String input: se le agrega 'T00:00:00' antes de parsear (success.astro:323).

  it('en -> "Thursday, August 6, 2026" (mismo output con en y en-US)', () => {
    expect(formatDate('2026-08-06', 'en')).toBe('Thursday, August 6, 2026');
  });

  it('es -> "jueves, 6 de agosto de 2026"', () => {
    expect(formatDate('2026-08-06', 'es')).toBe('jueves, 6 de agosto de 2026');
  });

  it('fr -> "jeudi 6 août 2026"', () => {
    expect(formatDate('2026-08-06', 'fr')).toBe('jeudi 6 août 2026');
  });

  it('pt -> "quinta-feira, 6 de agosto de 2026"', () => {
    expect(formatDate('2026-08-06', 'pt')).toBe('quinta-feira, 6 de agosto de 2026');
  });

  it('locale desconocido -> fallback en-US', () => {
    expect(formatDate('2026-08-06', 'xx')).toBe('Thursday, August 6, 2026');
  });

  it('acepta Date como entrada (mismo output que el string ISO)', () => {
    expect(formatDate(new Date('2026-08-06T00:00:00'), 'en')).toBe('Thursday, August 6, 2026');
  });

  it('string vacio -> "" (guard de success.astro:322 sin crash)', () => {
    expect(formatDate('', 'en')).toBe('');
  });
});

describe('formatPrice(amount, currency, locale) — contrato de profile.astro:450 / my-bookings.astro:180', () => {
  // Patron actual duplicado (2 archivos identicos): "$" + Number(x).toFixed(2) + " USD"
  // -> SIN agrupacion de miles y SIN Intl.NumberFormat en todo el codigo (grep 0 hits).
  // Locale NO altera la salida hoy (codigo hardcodea $ y USD).

  it('USD/en -> "$1234.50 USD" (toFixed(2), sin agrupacion)', () => {
    expect(formatPrice(1234.5, 'USD', 'en')).toBe('$1234.50 USD');
  });

  it('USD/es -> "$1234.50 USD" (la UI actual es insensible a locale: profile.astro:450 hardcodea $ y USD)', () => {
    expect(formatPrice(1234.5, 'USD', 'es')).toBe('$1234.50 USD');
  });

  it('USD/en entero -> "$1234.00 USD" (toFixed(2) rellena ceros)', () => {
    expect(formatPrice(1234, 'USD', 'en')).toBe('$1234.00 USD');
  });

  it('PEN/es -> "S/.1234.50 PEN" (derivado: simbolo de success.astro:329, patron toFixed(2)+codigo de profile.astro:450)', () => {
    // No existe hoy una renderizacion PEN en la UI; este pin fija el unico precedente
    // del simbolo en el codigo (success.astro:329) combinado con el patron dominante.
    expect(formatPrice(1234.5, 'PEN', 'es')).toBe('S/.1234.50 PEN');
  });
});
