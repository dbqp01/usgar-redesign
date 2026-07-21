import type { Locale } from '../i18n/utils';

/**
 * Translates English bed configuration strings to the target locale.
 * Single source of truth — avoids copy-pasting this logic in 4+ files.
 */
export function translateBeds(beds: string, lang: Locale): string {
  if (lang === 'es') {
    return beds
      .replace('double beds', 'camas dobles')
      .replace('double bed', 'cama doble')
      .replace('single beds', 'camas individuales')
      .replace('single bed', 'cama individual')
      .replace('king bed', 'cama king')
      .replace('king +', 'king +');
  }
  if (lang === 'fr') {
    return beds
      .replace('double beds', 'lits doubles')
      .replace('double bed', 'lit double')
      .replace('single beds', 'lits simples')
      .replace('single bed', 'lit simple')
      .replace('king bed', 'lit king-size')
      .replace('king +', 'king-size +');
  }
  if (lang === 'pt') {
    return beds
      .replace('double beds', 'camas duplas')
      .replace('double bed', 'cama dupla')
      .replace('single beds', 'camas de solteiro')
      .replace('single bed', 'cama de solteiro')
      .replace('king bed', 'cama king')
      .replace('king +', 'king +');
  }
  return beds;
}

/**
 * Calculates the number of nights between two date strings.
 * Shared between server (channex.ts) and client scripts.
 */
export function daysBetween(date1: string, date2: string): number {
  if (!date1 || !date2) return 0;
  const d1 = new Date(date1 + 'T00:00:00');
  const d2 = new Date(date2 + 'T00:00:00');
  const diffTime = d2.getTime() - d1.getTime();
  if (isNaN(diffTime) || diffTime <= 0) return 0;
  return Math.ceil(diffTime / (1000 * 60 * 60 * 24));
}
