import type { Locale } from '../i18n/utils';

const BED_DICTIONARY: Record<Locale, Record<string, string>> = {
  es: {
    'double beds': 'camas dobles',
    'double bed': 'cama doble',
    'single beds': 'camas individuales',
    'single bed': 'cama individual',
    'king bed': 'cama king',
    'king +': 'king +',
  },
  fr: {
    'double beds': 'lits doubles',
    'double bed': 'lit double',
    'single beds': 'lits simples',
    'single bed': 'lit simple',
    'king bed': 'lit king-size',
    'king +': 'king-size +',
  },
  pt: {
    'double beds': 'camas duplas',
    'double bed': 'cama dupla',
    'single beds': 'camas de solteiro',
    'single bed': 'cama de solteiro',
    'king bed': 'cama king',
    'king +': 'king +',
  },
  en: {},
};

/**
 * Translates English bed configuration strings to the target locale.
 * Single source of truth using a declarative dictionary map.
 */
export function translateBeds(beds: string, lang: Locale): string {
  const dict = BED_DICTIONARY[lang];
  if (!dict || lang === 'en' || !beds) return beds;
  let translated = beds;
  for (const [englishTerm, localTerm] of Object.entries(dict)) {
    translated = translated.replace(englishTerm, localTerm);
  }
  return translated;
}
