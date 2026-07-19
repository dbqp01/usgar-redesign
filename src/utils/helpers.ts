import type { Locale } from '../i18n/utils';

/**
 * Translates English bed configuration strings to the target locale.
 * Single source of truth — avoids copy-pasting this logic in 4+ files.
 */
export function translateBeds(beds: string, lang: Locale): string {
  return beds
    .replace('double beds', lang === 'es' ? 'camas dobles' : 'double beds')
    .replace('double bed', lang === 'es' ? 'cama doble' : 'double bed')
    .replace('single beds', lang === 'es' ? 'camas individuales' : 'single beds')
    .replace('single bed', lang === 'es' ? 'cama individual' : 'single bed')
    .replace('king bed', lang === 'es' ? 'cama king' : 'king bed')
    .replace('king +', lang === 'es' ? 'king +' : 'king +');
}

/**
 * Calculates the number of nights between two date strings.
 * Shared between server (channex.ts) and client scripts.
 */
export function daysBetween(date1: string, date2: string): number {
  const d1 = new Date(date1);
  const d2 = new Date(date2);
  const diffTime = Math.abs(d2.getTime() - d1.getTime());
  const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
  return isNaN(diffDays) ? 1 : diffDays;
}
