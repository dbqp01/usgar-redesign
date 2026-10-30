import { describe, it, expect } from 'vitest';
import en from '../../src/i18n/en.json';
import es from '../../src/i18n/es.json';
import fr from '../../src/i18n/fr.json';
import pt from '../../src/i18n/pt.json';

const dictionaries = { en, es, fr, pt } as const;

// El aviso de la newsletter debe permitir un consentimiento informado:
// finalidad, frecuencia y como retirarlo, en los 4 idiomas del sitio.
const WITHDRAWAL = {
  en: /unsubscribe/i,
  es: /baja/i,
  fr: /désabonnement/i,
  pt: /cancelar/i,
} as const;

describe('Newsletter consent copy (4 locales)', () => {
  it('has consent, notice, confirmation and consent-error text in every locale', () => {
    for (const locale of Object.keys(dictionaries) as Array<keyof typeof dictionaries>) {
      const footer = dictionaries[locale].footer as Record<string, string>;
      for (const key of ['newsletterConsent', 'newsletterNotice', 'newsletterConfirmSent', 'newsletterConsentRequired']) {
        expect(footer[key], `${locale}.footer.${key}`).toBeTruthy();
        expect(footer[key].trim().length, `${locale}.footer.${key}`).toBeGreaterThan(20);
      }
    }
  });

  it('notice is informed: mentions email, monthly frequency and how to opt out', () => {
    for (const locale of Object.keys(dictionaries) as Array<keyof typeof dictionaries>) {
      const notice = (dictionaries[locale].footer as Record<string, string>).newsletterNotice;
      expect(notice).toMatch(/e-?mail|correo/i);
      expect(notice).toMatch(/mês|mes|month|mois/i);
      expect(notice).toMatch(WITHDRAWAL[locale]);
    }
  });
});
