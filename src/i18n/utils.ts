import en from './en.json';
import es from './es.json';

export type Locale = 'en' | 'es';

export function useTranslations(lang: Locale) {
  return function t(key: string): string {
    const keys = key.split('.');
    let translation: any = lang === 'es' ? es : en;
    
    for (const k of keys) {
      if (translation && translation[k] !== undefined) {
        translation = translation[k];
      } else {
        // Fallback to English if key doesn't exist in Spanish
        let fallback: any = en;
        for (const fk of keys) {
          if (fallback && fallback[fk] !== undefined) {
            fallback = fallback[fk];
          } else {
            return key; // Return the key as string if not found
          }
        }
        return typeof fallback === 'string' ? fallback : key;
      }
    }
    
    return typeof translation === 'string' ? translation : key;
  };
}
