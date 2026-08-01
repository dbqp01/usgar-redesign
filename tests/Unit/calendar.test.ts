import { describe, it, expect } from 'vitest';
import { buildMonthGrid, nightsBetween, clampRange, formatISODate } from '../../src/features/booking/calendar';

describe('calendar', () => {
  it('buildMonthGrid: 42 celdas y dias pasados marcados', () => {
    const days = buildMonthGrid(2026, 7, '2026-08-10'); // agosto 2026
    expect(days).toHaveLength(42);
    expect(days.filter((d) => d.inMonth).length).toBe(31);
    const past = days.find((d) => d.date === '2026-08-05');
    expect(past?.past).toBe(true);
    const future = days.find((d) => d.date === '2026-08-15');
    expect(future?.past).toBe(false);
  });

  it('nightsBetween: diferencia correcta', () => {
    expect(nightsBetween('2026-08-10', '2026-08-13')).toBe(3);
    expect(nightsBetween('2026-08-13', '2026-08-10')).toBe(-3);
  });

  it('clampRange: invierte fechas si checkOut <= checkIn', () => {
    const r = clampRange('2026-08-13', '2026-08-10');
    expect(r).toEqual({ checkIn: '2026-08-10', checkOut: '2026-08-13' });
  });

  it('clampRange: limita a maxNights (30 por defecto)', () => {
    const r = clampRange('2026-08-01', '2026-09-15');
    expect(nightsBetween(r.checkIn, r.checkOut)).toBeLessThanOrEqual(30);
  });

  it('formatISODate: yyyy-mm-dd local', () => {
    expect(formatISODate(new Date(2026, 7, 5))).toBe('2026-08-05');
  });
});
