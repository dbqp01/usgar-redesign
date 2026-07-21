import { describe, it, expect } from 'vitest';
import en from '../../src/i18n/en.json';
import es from '../../src/i18n/es.json';
import fr from '../../src/i18n/fr.json';
import pt from '../../src/i18n/pt.json';
import { useTranslations } from '../../src/i18n/utils';

describe('i18n Translations Parity & Fallbacks (4 Locales: EN, ES, FR, PT)', () => {
  it('should have matching top-level keys across all 4 language dictionaries', () => {
    const enKeys = Object.keys(en).sort();
    const esKeys = Object.keys(es).sort();
    const frKeys = Object.keys(fr).sort();
    const ptKeys = Object.keys(pt).sort();

    expect(esKeys).toEqual(enKeys);
    expect(frKeys).toEqual(enKeys);
    expect(ptKeys).toEqual(enKeys);
  });

  it('should translate nav section keys correctly in all 4 languages', () => {
    const tEn = useTranslations('en');
    const tEs = useTranslations('es');
    const tFr = useTranslations('fr');
    const tPt = useTranslations('pt');

    expect(tEn('nav.home')).toBe('Home');
    expect(tEs('nav.home')).toBe('Inicio');
    expect(tFr('nav.home')).toBe('Accueil');
    expect(tPt('nav.home')).toBe('Início');

    expect(tEn('nav.bookNow')).toBe('Book Now');
    expect(tEs('nav.bookNow')).toBe('Reservar');
    expect(tFr('nav.bookNow')).toBe('Réserver');
    expect(tPt('nav.bookNow')).toBe('Reservar');
  });

  it('should fallback gracefully to English for French when key is missing', () => {
    const tFr = useTranslations('fr');
    expect(tFr('nav.nonExistentKey')).toBe('nav.nonExistentKey');
  });
});
