import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

// Todo 30: el flujo de pago NO debe contener literales en ingles visibles.
// Todo texto del wizard sale del i18n (src/i18n/*.json, es-PE default).
// El test lee los componentes del flujo y falla si aparece un literal
// prohibido fuera de comentarios y llamadas internas de consola.

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..', '..', 'src');

const FLOW_FILES = [
  resolve(root, 'components/booking/PaymentStep.astro'),
  resolve(root, 'pages/book/success.astro'),
];

// Literales en ingles que el usuario ve en el wizard/pantalla de exito.
const FORBIDDEN_LITERALS = [
  'Pay Now',
  'Card Number',
  'Cardholder Name',
  'Document Type',
  'Document Number',
  'Expiration',
  'Preparing secure payment',
  'Processing payment',
  'Processing...',
  'Payment Details',
  'John Doe',
  '0000 0000 0000 0000',
  'MM/YY',
  'Payment failed',
  'Payment processing failed',
  'Payment could not be processed',
  'Payment gateway could not load',
  'Missing required payment data',
  'Card could not be verified',
  'We are verifying your payment',
  'Confirming payment with the bank',
  'Booking not found',
];

function isAllowedLine(line: string): boolean {
  const trimmed = line.trim();
  if (trimmed.startsWith('//') || trimmed.startsWith('*') || trimmed.startsWith('/*')) return true;
  if (trimmed.includes('console.')) return true;
  // Fallbacks de strings internos del SDK (LABELS viene de book.astro i18n).
  if (trimmed.includes('LABELS.')) return true;
  return false;
}

describe('i18n del flujo de pago (todo 30: sin literales en ingles visibles)', () => {
  for (const file of FLOW_FILES) {
    it(`no contiene literales en ingles en ${file.split('/src/')[1]}`, () => {
      const lines = readFileSync(file, 'utf8').split(/\r?\n/);
      const hits: string[] = [];
      lines.forEach((line, idx) => {
        if (isAllowedLine(line)) return;
        for (const literal of FORBIDDEN_LITERALS) {
          if (line.includes(literal)) hits.push(`linea ${idx + 1}: "${literal}" -> ${line.trim().slice(0, 90)}`);
        }
      });
      expect(hits, `Literales en ingles encontrados:\n${hits.join('\n')}`).toEqual([]);
    });
  }
});
