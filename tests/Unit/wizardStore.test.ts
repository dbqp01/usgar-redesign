import { describe, it, expect } from 'vitest';
import { createWizardStore } from '../../src/features/booking/wizardStore';

describe('createWizardStore', () => {
  it('estado inicial por defecto', () => {
    const s = createWizardStore();
    expect(s.getState().step).toBe(1);
    expect(s.getState().checkIn).toBe('');
    expect(s.getState().guests).toBe(2);
  });

  it('setState notifica a suscriptores', () => {
    const s = createWizardStore();
    let seen: unknown = null;
    const unsub = s.subscribe((st) => { seen = st; });
    s.setState({ guests: 3 });
    expect((seen as { guests: number }).guests).toBe(3);
    unsub();
  });

  it('next avanza y back retrocede dentro de 1..3', () => {
    const s = createWizardStore();
    s.next(); expect(s.getState().step).toBe(2);
    s.next(); expect(s.getState().step).toBe(3);
    s.next(); expect(s.getState().step).toBe(3);
    s.back(); expect(s.getState().step).toBe(2);
    s.back(); expect(s.getState().step).toBe(1);
    s.back(); expect(s.getState().step).toBe(1);
  });

  it('initial parcial es respetado', () => {
    const s = createWizardStore({ checkIn: '2026-08-10', step: 2 });
    expect(s.getState().checkIn).toBe('2026-08-10');
    expect(s.getState().step).toBe(2);
  });
});
