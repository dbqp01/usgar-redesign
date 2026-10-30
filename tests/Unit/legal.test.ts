import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

// El texto legal cita hechos del sistema (cookies, encargados, cifrado, tipo de
// cambio, plazos). Este test es el guardia contra la deriva: si el código o la
// ley cambian y la copia no, falla aquí. Se lee el fuente porque legal.ts
// importa siteSettings (astro:content), que Vitest no puede resolver.

const source = readFileSync('src/data/legal.ts', 'utf8');
const LOCALES = ['en', 'es', 'fr', 'pt'] as const;

function localesIn(startMarker: string, endMarker: string): Record<string, string> {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start + startMarker.length);
  const body = source.slice(start, end === -1 ? source.length : end);
  const found: Record<string, string> = {};
  for (const locale of LOCALES) {
    const at = body.indexOf(`\n  ${locale}: [`);
    if (at === -1) continue;
    const later = LOCALES.map((l) => body.indexOf(`\n  ${l}: [`))
      .filter((i) => i > at)
      .sort((a, b) => a - b)[0] ?? body.length;
    found[locale] = body.slice(at, later);
  }
  return found;
}

const privacy = localesIn('export const privacySections', 'export const termsSections');
const terms = localesIn('export const termsSections', 'export const legalMeta');

describe.each([
  ['privacy', privacy],
  ['terms', terms],
])('%s policy copy', (_name, doc) => {
  it('exists in all four locales with the same number of sections', () => {
    expect(Object.keys(doc).sort()).toEqual([...LOCALES].sort());
    const counts = LOCALES.map((l) => (doc[l].match(/title:/g) ?? []).length);
    expect(counts.every((n) => n === counts[0])).toBe(true);
    expect(counts[0]).toBeGreaterThanOrEqual(6);
  });

  it('has a title and at least one paragraph in every section', () => {
    for (const locale of LOCALES) {
      const blocks = doc[locale].split(/\n    \{\n/).slice(1);
      for (const block of blocks) {
        expect(block).toMatch(/title: "/);
        expect(block).toMatch(/body: \[\s*["`]/);
      }
    }
  });
});

describe('privacy copy matches the data practices in code', () => {
  it('names every cookie the backend actually sets', () => {
    for (const locale of LOCALES) {
      for (const name of [
        'usgar_session',
        'usgar_csrf',
        'usgar_panel',
        'usgar_panel_csrf',
        'usgar_auth_provider',
        'usgar_auth_redirect',
        'PHPSESSID',
      ]) {
        expect(privacy[locale]).toContain(name);
      }
    }
  });

  it('names the processors and third parties that receive data', () => {
    for (const locale of LOCALES) {
      for (const name of ['MercadoPago', 'QloApps', 'Hostinger', 'Google', 'Microsoft', 'Facebook', 'CARTO', 'OpenStreetMap']) {
        expect(privacy[locale]).toContain(name);
      }
    }
  });

  it('does not claim the session token is encrypted', () => {
    expect(source).not.toMatch(/encrypted session|tokens? de sesión cifrados|jetons? de session chiffrés|tokens? de sessão cifrados/i);
  });

  it('does not promise calendar-day deadlines for Peruvian rights', () => {
    for (const locale of LOCALES) {
      expect(privacy[locale]).toContain('016-2024-JUS');
    }
    expect(source).not.toMatch(/calendar days|días calendario|jours calendaires|dias corridos/i);
  });

  it('does not claim an unverified transfer mechanism or automatic deletion', () => {
    expect(source).not.toMatch(/rely on the transfer mechanism|nos apoyamos en el mecanismo|nous nous appuyons sur le mécanisme|contamos com o mecanismo/i);
    expect(source).not.toMatch(/we keep your address only|conservamos tu dirección solo|conservons votre adresse uniquement|guardamos o seu endereço apenas/i);
  });

  it('describes the newsletter double opt-in and the unsubscribe route', () => {
    for (const locale of LOCALES) {
      expect(privacy[locale]).toMatch(/confirm|confirmes|confirmar|confirmation/i);
      expect(privacy[locale]).toMatch(/unsubscribe|baja|désabonnement|cancelamento/i);
    }
  });
});

describe('terms copy matches the checkout implementation', () => {
  it('does not attribute the exchange rate to MercadoPago at payment time', () => {
    expect(source).not.toMatch(/rate applied by MercadoPago|tipo de cambio que aplique MercadoPago|taux appliqué par MercadoPago|taxa aplicada pela MercadoPago/i);
  });

  it('states the charge currency and the fixed rate in every locale', () => {
    for (const locale of LOCALES) {
      expect(terms[locale]).toContain('PEN');
      expect(terms[locale]).toContain('USD');
      expect(terms[locale]).toMatch(/fix|fij|fixé|fixad/i);
    }
  });
});
