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
