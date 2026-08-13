// src/features/booking/bookingStatus.ts
// Util compartido de consulta de estado de reserva (polling del pago).
// Extraido de success.astro (2026-08-13) para que PaymentStep (wizard) y
// success.astro usen la MISMA maquinaria: fetch + polling con intervalo fijo.
// El render del resultado es responsabilidad de cada pagina (DOM distinto);
// aqui solo vive la consulta.

export const POLL_INTERVAL_MS = 15000;
export const MAX_POLL_ATTEMPTS = 40; // 15s x 40 = 10 min

export interface BookingStatusPayload {
  status?: string;
  payment_id?: string | null;
  [key: string]: unknown;
}

/** Fetch del estado de la reserva (GET /api/booking-status con token opcional). */
export async function fetchBookingStatus(
  bookingId: string,
  token: string,
  signal?: AbortSignal
): Promise<BookingStatusPayload> {
  const API_BASE_URL = (import.meta.env.PUBLIC_API_URL || window.location.origin + '/api').replace(/\/$/, '');
  const tokenParam = token ? `&token=${encodeURIComponent(token)}` : '';
  const response = await fetch(`${API_BASE_URL}/booking-status?cart_id=${bookingId}${tokenParam}`, { signal });
  if (!response.ok) throw new Error('BOOKING_STATUS_ERROR');
  const payload = await response.json();
  return payload.data || payload;
}

export function wait(ms: number, signal: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    const timeout = setTimeout(resolve, ms);
    signal.addEventListener('abort', () => {
      clearTimeout(timeout);
      reject(new Error('Aborted'));
    });
  });
}

/**
 * Poll hasta que el status deja de ser pendiente (intervalo fijo 15s).
 * Devuelve el ultimo estado conocido (la UI decide como renderizarlo).
 */
export async function pollForPayment(
  bookingId: string,
  token: string,
  signal: AbortSignal,
  isPendingStatus: (status: string | null | undefined) => boolean
): Promise<BookingStatusPayload> {
  let last: BookingStatusPayload | null = null;

  for (let attempt = 0; attempt < MAX_POLL_ATTEMPTS; attempt++) {
    if (signal.aborted) throw new Error('Aborted');
    const booking = await fetchBookingStatus(bookingId, token, signal);
    last = booking;

    // Si el status ya no es pendiente, devolver inmediatamente.
    if (booking.status && !isPendingStatus(booking.status)) {
      return booking;
    }

    await wait(POLL_INTERVAL_MS, signal);
  }

  // Tras 10 min, devolver el ultimo estado conocido (la UI decide).
  return last ?? {};
}
