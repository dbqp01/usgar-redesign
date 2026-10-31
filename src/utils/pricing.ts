import { formatPrice } from './format';

// Tasa de vitrina (build estatico), misma clave que app/Core/Config.php; el
// cobro real se congela al cotizar en el backend. Fallback 3.80 sin env.
const configuredRate = Number(import.meta.env.EXCHANGE_RATE_USD_PEN);
export const USD_TO_PEN = Number.isFinite(configuredRate) && configuredRate > 0 ? configuredRate : 3.8;

/** USD -> PEN redondeando a centavos sobre centavos enteros (sin drift binario). */
export function usdToPen(usd: number): number {
  return Math.round(Math.round(usd * 100) * USD_TO_PEN) / 100;
}

/** Etiqueta la tarifa publicada en USD y su equivalente PEN en la misma linea. */
export function formatUsdPen(usd: number, locale: string): { usd: string; pen: string } {
  return {
    usd: formatPrice(usd, 'USD', locale),
    pen: formatPrice(usdToPen(usd), 'PEN', locale),
  };
}
