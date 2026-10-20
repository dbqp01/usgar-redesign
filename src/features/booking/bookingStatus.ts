// src/features/booking/bookingStatus.ts
// Util compartido de consulta de estado de reserva (polling del pago).
// Extraido de success.astro para que PaymentStep (wizard) y
// success.astro usen la MISMA maquinaria: fetch + polling.
// Intervalo fijo -> backoff exponencial con jitter, pausa mientras
// la pestana esta oculta (visibilitychange) y respeto de Retry-After (429/503).
// El render del resultado es responsabilidad de cada pagina (DOM distinto);
// aqui solo vive la consulta.

export const POLL_INTERVAL_MS = 15000;
export const MAX_POLL_ATTEMPTS = 40;
/** Techo del backoff (evita esperas de minutos ante 429/503 sostenidos). */
const MAX_BACKOFF_MS = 60000;
/** Techo de reloj del polling completo (preserva el contrato de ~10 min). */
const MAX_POLL_DURATION_MS = 10 * 60 * 1000;
/** Jitter simetrico: delay final en [base*(1-j), base*(1+j)]. */
const JITTER_RATIO = 0.2;

export interface BookingStatusPayload {
  status?: string;
  payment_id?: string | null;
  [key: string]: unknown;
}

/** Error HTTP del endpoint de estado; transporta Retry-After para el backoff. */
export class BookingStatusHttpError extends Error {
  readonly status: number;
  readonly retryAfterMs: number | null;

  constructor(status: number, retryAfterMs: number | null) {
    super('BOOKING_STATUS_ERROR');
    this.name = 'BookingStatusHttpError';
    this.status = status;
    this.retryAfterMs = retryAfterMs;
  }
}

/** Retry-After: segundos (entero/decimal) o fecha HTTP. null si no viene o es invalido. */
function parseRetryAfter(headerValue: string | null): number | null {
  if (!headerValue) return null;
  const seconds = Number(headerValue);
  if (Number.isFinite(seconds)) return Math.max(0, seconds * 1000);
  const dateMs = Date.parse(headerValue);
  if (!Number.isNaN(dateMs)) return Math.max(0, dateMs - Date.now());
  return null;
}

/** Fetch del estado de la reserva (GET /api/booking-status con token opcional). */
export async function fetchBookingStatus(
  bookingId: string,
  token: string,
  signal?: AbortSignal
): Promise<BookingStatusPayload> {
  const API_BASE_URL = (import.meta.env?.PUBLIC_API_URL || window.location.origin + '/api').replace(/\/$/, '');
  const tokenParam = token ? `&token=${encodeURIComponent(token)}` : '';
  const response = await fetch(`${API_BASE_URL}/booking-status?cart_id=${encodeURIComponent(bookingId)}${tokenParam}`, { signal });
  if (!response.ok) {
    throw new BookingStatusHttpError(response.status, parseRetryAfter(response.headers.get('Retry-After')));
  }
  const payload = await response.json();
  return payload.data || payload;
}

export function wait(ms: number, signal: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    if (signal.aborted) {
      reject(new Error('Aborted'));
      return;
    }
    const onAbort = () => {
      clearTimeout(timeout);
      signal.removeEventListener('abort', onAbort);
      reject(new Error('Aborted'));
    };
    const timeout = setTimeout(() => {
      signal.removeEventListener('abort', onAbort);
      resolve();
    }, ms);
    signal.addEventListener('abort', onAbort, { once: true });
  });
}

/**
 * Espera a que la pestana vuelva a ser visible antes de seguir consultando:
 * no tiene sentido golpear la API desde una pestana en segundo plano.
 * Resuelve inmediatamente si ya es visible (o si no hay DOM, p.ej. SSR).
 */
export function waitUntilVisible(signal: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    if (typeof document === 'undefined' || document.visibilityState !== 'hidden') {
      resolve();
      return;
    }
    if (signal.aborted) {
      reject(new Error('Aborted'));
      return;
    }
    const cleanup = () => {
      document.removeEventListener('visibilitychange', onVisibility);
      signal.removeEventListener('abort', onAbort);
    };
    const onVisibility = () => {
      if (document.visibilityState !== 'hidden') {
        cleanup();
        resolve();
      }
    };
    const onAbort = () => {
      cleanup();
      reject(new Error('Aborted'));
    };
    document.addEventListener('visibilitychange', onVisibility);
    signal.addEventListener('abort', onAbort, { once: true });
  });
}

/** Delay con jitter simetrico (evita que clientes sincronicen sus reintentos). */
function withJitter(ms: number): number {
  const delta = ms * JITTER_RATIO;
  return ms - delta + Math.random() * delta * 2;
}

/**
 * Poll hasta que el status deja de ser pendiente.
 * - Intervalo base 15s con backoff exponencial (x2) ante 429/5xx/red, techo 60s
 *   y jitter +-20%; una respuesta exitosa reinicia el backoff.
 * - Si el backend manda Retry-After, ese valor fija el delay del intento.
 * - Errores 4xx distintos de 429 no se reintentan (se propagan, igual que abort).
 * - Pausa el fetch mientras la pestana esta oculta (visibilitychange).
 * - Tope doble: MAX_POLL_ATTEMPTS intentos o 10 min de reloj; despues devuelve
 *   el ultimo estado conocido (la UI decide como renderizarlo).
 */
export async function pollForPayment(
  bookingId: string,
  token: string,
  signal: AbortSignal,
  isPendingStatus: (status: string | null | undefined) => boolean
): Promise<BookingStatusPayload> {
  let last: BookingStatusPayload | null = null;
  let consecutiveErrors = 0;
  const startedAt = Date.now();

  for (let attempt = 0; attempt < MAX_POLL_ATTEMPTS && Date.now() - startedAt < MAX_POLL_DURATION_MS; attempt++) {
    if (signal.aborted) throw new Error('Aborted');

    await waitUntilVisible(signal);
    if (signal.aborted) throw new Error('Aborted');

    let retryAfterMs: number | null = null;
    try {
      const booking = await fetchBookingStatus(bookingId, token, signal);
      last = booking;
      consecutiveErrors = 0;

      // Si el status ya no es pendiente, devolver inmediatamente.
      if (booking.status && !isPendingStatus(booking.status)) {
        return booking;
      }
    } catch (error) {
      // Abort (fetch o espera): propagar siempre.
      if (signal.aborted) throw error;
      if (error instanceof BookingStatusHttpError) {
        // 4xx (salvo 429) no se recuperan reintentando: mismo contrato que antes.
        if (error.status < 500 && error.status !== 429) throw error;
        retryAfterMs = error.retryAfterMs;
      }
      consecutiveErrors++;
    }

    const backoffMs = Math.min(
      withJitter(POLL_INTERVAL_MS) * Math.pow(2, consecutiveErrors),
      MAX_BACKOFF_MS
    );
    const delayMs = retryAfterMs !== null
      ? Math.min(Math.max(retryAfterMs, 1000), MAX_BACKOFF_MS)
      : backoffMs;
    // Keep the documented ten-minute wall-clock ceiling even when the final
    // backoff would otherwise sleep past it by a full minute.
    const remainingMs = MAX_POLL_DURATION_MS - (Date.now() - startedAt);
    if (remainingMs <= 0) break;
    await wait(Math.min(delayMs, remainingMs), signal);
  }

  // Fin del polling: devolver el ultimo estado conocido (la UI decide).
  return last ?? {};
}
