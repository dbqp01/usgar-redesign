import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { USD_TO_PEN, usdToPen, formatUsdPen } from '../../src/utils/pricing';

// Contrato de presentacion publica de tarifas:
// - USD es la tarifa publicada; PEN es una equivalencia referencial.
// - Tipo de cambio comunicado: US$1 = S/3.80.
// - La nota debe decir que el hotel confirma el precio final (no es precio
//   final confirmado) y NO debe inventar impuestos ni cargos.

const root = process.cwd();
const read = (file: string) => readFileSync(resolve(root, file), 'utf8');

describe('USD -> PEN reference conversion', () => {
  it('uses the communicated rate of 3.8 soles per dollar', () => {
    expect(USD_TO_PEN).toBe(3.8);
  });

  it('converts whole-dollar published rates exactly', () => {
    expect(usdToPen(90)).toBe(342);
    expect(usdToPen(75)).toBe(285);
  });

  it('rounds to cents without binary drift', () => {
    expect(usdToPen(89.99)).toBe(341.96);
    expect(usdToPen(0.01)).toBe(0.04);
    expect(usdToPen(0)).toBe(0);
  });
});

describe('formatUsdPen', () => {
  it('labels the published amount USD and the equivalent PEN', () => {
    expect(formatUsdPen(90, 'en')).toEqual({ usd: '$90.00 USD', pen: 'S/.342.00 PEN' });
  });

  it('keeps the same amount labels in every locale', () => {
    expect(formatUsdPen(90, 'es')).toEqual(formatUsdPen(90, 'en'));
    expect(formatUsdPen(90, 'fr')).toEqual(formatUsdPen(90, 'en'));
    expect(formatUsdPen(90, 'pt')).toEqual(formatUsdPen(90, 'en'));
  });
});

const RATE_FILES = [
  'src/components/RoomsEditorialGrid.astro',
  'src/pages/rooms/index.astro',
  'src/components/RoomDetail.astro',
  'src/components/room/RoomBookingSidebar.astro',
];

describe('static rate presentation', () => {
  for (const file of RATE_FILES) {
    it(`${file} shows USD and its PEN equivalent`, () => {
      const source = read(file);
      expect(source).toContain('formatUsdPen');
      expect(source).toContain('price.usd');
      expect(source).toContain('price.pen');
      // El glifo ≈ se lee como "=" a 10px; la etiqueta textual evita ambiguedad.
      expect(source).not.toContain('≈');
    });
  }

  for (const file of RATE_FILES) {
    it(`${file} communicates the 3.8 rate and who confirms the final price`, () => {
      const source = read(file);
      expect(source).toContain('rooms.exchangeRateNote');
      expect(source).toContain("USD_TO_PEN.toFixed(2)");
    });
  }
});

describe('exchange-rate note translations', () => {
  for (const locale of ['en', 'es', 'fr', 'pt']) {
    it(`${locale} names USD, PEN and the rate placeholder`, () => {
      const note = JSON.parse(read(`src/i18n/${locale}.json`)).rooms.exchangeRateNote;
      expect(note).toContain('USD');
      expect(note).toContain('PEN');
      expect(note).toContain('{rate}');
      expect(note.toLowerCase()).not.toMatch(/\b(tax|taxes|impuesto|impuestos|iva|tva|imposto|impostos)\b/);
    });

    it(`${locale} has a textual approximation label`, () => {
      const approx = JSON.parse(read(`src/i18n/${locale}.json`)).rooms.approx;
      expect(approx).toBeTruthy();
    });
  }
});
