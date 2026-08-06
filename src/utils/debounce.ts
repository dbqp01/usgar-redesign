// Debounce trailing-edge compartido — extraido de la implementacion inline
// duplicada (contrato: tests/Unit/debounce.test.ts, W3/21 behavior lock).
// Copia exacta de explore.astro:207-213: la llamada NO se ejecuta al instante
// (leading) y cada llamada reinicia el timer (solo la ultima serie de
// argumentos llega al fn).

/**
 * Debounce trailing-edge: invoca `fn` `wait` ms despues de la ultima llamada.
 */
export function debounce<T extends (...args: any[]) => void>(fn: T, wait: number): (...args: Parameters<T>) => void {
  let timer: ReturnType<typeof setTimeout>;
  return (...args: Parameters<T>) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), wait);
  };
}
