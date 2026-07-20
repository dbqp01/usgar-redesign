import { describe, it, expect } from 'vitest';
import en from '../../src/i18n/en.json';
import es from '../../src/i18n/es.json';

describe('i18n Translations Parity', () => {
  it('should have matching top-level keys between en.json and es.json', () => {
    const enKeys = Object.keys(en).sort();
    const esKeys = Object.keys(es).sort();
    expect(enKeys).toEqual(esKeys);
  });

  it('should have nav section keys defined in both languages', () => {
    expect(en.nav.home).toBe('Home');
    expect(es.nav.home).toBe('Inicio');
    expect(en.nav.bookNow).toBe('Book Now');
    expect(es.nav.bookNow).toBe('Reservar Ahora');
  });

  it('should have room titles defined in both languages', () => {
    expect(en.rooms.title).toBe('Our Rooms');
    expect(es.rooms.title).toBe('Nuestras Habitaciones');
  });
});
