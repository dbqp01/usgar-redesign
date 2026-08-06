import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { debounce } from '../../src/utils/debounce';

// =============================================================================
// CONTRATO de src/utils/debounce (W3/21 behavior lock -> implementado en W3/23).
// Pin de la implementacion inline EXACTA que hoy vive en explore.astro:207-213:
//
//   function debounce<T extends (...args: any[]) => void>(fn: T, ms: number) {
//     let timer: ReturnType<typeof setTimeout>;
//     return (...args: Parameters<T>) => {
//       clearTimeout(timer);
//       timer = setTimeout(() => fn(...args), ms);
//     };
//   }
//
// Debounce TRAILING edge: la llamada NO se ejecuta al instante (leading) y cada
// llamada reinicia el timer (solo la ultima serie de argumentos llega al fn).
// La variante de animationLifecycle.ts:14-19 (debounceRefresh de ScrollTrigger)
// es el mismo patron hardcodeado a 200ms -> debe pasar a usar este helper.
// =============================================================================

describe('debounce(fn, wait) — contrato de explore.astro:207-213', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('leading-call-not-immediate: no invoca fn hasta que pasa el wait', () => {
    const fn = vi.fn();
    const debounced = debounce(fn, 200);

    debounced();
    expect(fn).not.toHaveBeenCalled();

    vi.advanceTimersByTime(199);
    expect(fn).not.toHaveBeenCalled();

    vi.advanceTimersByTime(1);
    expect(fn).toHaveBeenCalledTimes(1);
  });

  it('trailing-call-after-wait: invoca fn una vez con los argumentos tras el wait', () => {
    const fn = vi.fn();
    const debounced = debounce(fn, 200);

    debounced('a', 1);
    expect(fn).not.toHaveBeenCalled();

    vi.advanceTimersByTime(200);
    expect(fn).toHaveBeenCalledTimes(1);
    expect(fn).toHaveBeenCalledWith('a', 1);
  });

  it('timer-cancel-on-rapid-calls: solo la ULTIMA llamada de la rafaga llega al fn', () => {
    const fn = vi.fn();
    const debounced = debounce(fn, 200);

    debounced('first');
    vi.advanceTimersByTime(100);
    debounced('second');
    vi.advanceTimersByTime(100);
    debounced('third'); // reinicia el timer a 0

    vi.advanceTimersByTime(199);
    expect(fn).not.toHaveBeenCalled(); // el timer fue cancelado y reiniciado

    vi.advanceTimersByTime(1);
    expect(fn).toHaveBeenCalledTimes(1);
    expect(fn).toHaveBeenCalledWith('third');
  });
});
