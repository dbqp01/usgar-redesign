import en from './en.json';
import es from './es.json';
import fr from './fr.json';
import pt from './pt.json';

export type Locale = 'en' | 'es' | 'fr' | 'pt';

const dictionaries: Record<Locale, any> = {
  en,
  es,
  fr,
  pt,
};

// Fallback order: fr -> en, pt -> es -> en, es -> en
const fallbackChain: Record<Locale, Locale[]> = {
  en: ['es'],
  es: ['en'],
  fr: ['en', 'es'],
  pt: ['es', 'en'],
};

export function useTranslations(lang: Locale) {
  const currentLang = dictionaries[lang] ? lang : 'en';

  return function t(key: string): string {
    const keys = key.split('.');
    
    // Try primary locale
    const result = resolveKey(dictionaries[currentLang], keys);
    if (result !== undefined) {
      return result;
    }

    // Try fallback locales in chain
    for (const fallbackLang of fallbackChain[currentLang] || ['en']) {
      const fallbackResult = resolveKey(dictionaries[fallbackLang], keys);
      if (fallbackResult !== undefined) {
        return fallbackResult;
      }
    }

    // Return the key itself if no translation is found anywhere
    return key;
  };
}

function resolveKey(dict: any, keys: string[]): string | undefined {
  let current = dict;
  for (const k of keys) {
    if (current && current[k] !== undefined) {
      current = current[k];
    } else {
      return undefined;
    }
  }
  return typeof current === 'string' ? current : undefined;
}
