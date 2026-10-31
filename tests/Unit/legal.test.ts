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

  it('names WhatsApp and Meta as the enquiry channel in every locale', () => {
    for (const locale of LOCALES) {
      expect(privacy[locale]).toContain('WhatsApp');
      expect(privacy[locale]).toContain('Meta');
    }
  });

  it('describes the auth synchronization signal without claiming it stores a profile', () => {
    for (const locale of LOCALES) {
      expect(privacy[locale]).toContain('usgar_auth_event');
    }
    expect(privacy.en).toContain('a signal without profile data');
    expect(privacy.es).toContain('una señal sin datos de perfil');
    expect(privacy.fr).toContain('un signal sans données de profil');
    expect(privacy.pt).toContain('um sinal sem dados de perfil');
  });

  it('does not describe CSRF tokens as protecting every form', () => {
    expect(source).not.toMatch(/CSRF tokens in forms|tokens CSRF en los formularios|jetons CSRF dans les formulaires|tokens CSRF nos formulários/i);
    for (const locale of LOCALES) {
      expect(privacy[locale]).toContain('X-CSRF-Token');
    }
  });

  it('describes payment data without claiming the entire site cannot process payments', () => {
    expect(source).not.toMatch(/this website does not process payments|este sitio no procesa pagos|ce site ne traite pas de paiements|este site não processa pagamentos/i);
    for (const locale of LOCALES) expect(privacy[locale]).toContain('MercadoPago');
  });
});

describe('terms copy matches the manual WhatsApp enquiry flow', () => {
  it('does not promise an online checkout or a payment processed on the site', () => {
    expect(source).not.toMatch(
      /Payment is processed by MercadoPago|El pago lo procesa MercadoPago|Le paiement est traité par MercadoPago|O pagamento é processado pela MercadoPago/i,
    );
    expect(source).not.toMatch(
      /confirmed when the payment is approved|queda confirmada cuando el pago es aprobado|est confirmée lorsque le paiement est approuvé|fica confirmada quando o pagamento é aprovado/i,
    );
    expect(source).not.toMatch(
      /room is held for a limited time while you pay|habitación se mantiene bloqueada por tiempo limitado mientras pagas|chambre est bloquée pendant une durée limitée le temps du paiement|quarto fica bloqueado por tempo limitado enquanto você paga/i,
    );
    expect(source).not.toMatch(
      /rate applied by MercadoPago|tipo de cambio que aplique MercadoPago|taux appliqué par MercadoPago|taxa aplicada pela MercadoPago/i,
    );
  });

  it('routes enquiries to WhatsApp and leaves price and payment to the hotel', () => {
    for (const locale of LOCALES) {
      expect(terms[locale]).toContain('WhatsApp');
      expect(terms[locale]).toContain('USD');
      expect(terms[locale]).toMatch(/confirm/i);
      expect(terms[locale]).toMatch(/payment|pago|paiement|pagamento/i);
    }
    for (const locale of LOCALES) {
      expect(terms[locale]).toContain('${USD_TO_PEN.toFixed(2)}');
    }
    expect(source).not.toMatch(/until then there is no contract|hasta entonces no hay contrato|jusque-là, il n'y a ni contrat|até lá não há contrato/i);
    expect(source).not.toMatch(/extra guests beyond the room capacity|huéspedes adicionales a la capacidad|voyageurs supplémentaires au-delà de la capacité|hóspedes adicionais acima da capacidade/i);
  });

  it('keeps the consumer rights and INDECOPI route in every locale', () => {
    for (const locale of LOCALES) {
      expect(terms[locale]).toContain('29571');
      expect(terms[locale]).toContain('INDECOPI');
    }
  });
});
