import { describe, it, expect } from 'vitest';
import { formatCountdown, shouldExtendHold } from '../../src/utils/holdCountdown';

describe('formatCountdown (todo 29: countdown del hold desde time_left_seconds)', () => {
  it('formatea mm:ss', () => {
    expect(formatCountdown(900)).toBe('15:00');
    expect(formatCountdown(61)).toBe('01:01');
    expect(formatCountdown(59)).toBe('00:59');
    expect(formatCountdown(0)).toBe('00:00');
  });

  it('valores negativos -> 00:00 sin crash', () => {
    expect(formatCountdown(-5)).toBe('00:00');
  });
});

describe('shouldExtendHold (todo 29: extender una vez al llegar a ~60s)', () => {
  it('extiende al llegar a 60s si aun no se extendio', () => {
    expect(shouldExtendHold(60, 60, false)).toBe(true);
    expect(shouldExtendHold(45, 60, false)).toBe(true);
    expect(shouldExtendHold(61, 60, false)).toBe(false);
  });

  it('no extiende mas de una vez', () => {
    expect(shouldExtendHold(30, 60, true)).toBe(false);
  });

  it('no extiende con tiempo agotado (0 o negativo)', () => {
    expect(shouldExtendHold(0, 60, false)).toBe(false);
    expect(shouldExtendHold(-1, 60, false)).toBe(false);
  });
});
