import { afterEach, describe, expect, it, vi } from 'vitest';
import { readReceiptToken, receiptUrl } from '../../src/features/booking/receiptToken';

describe('receiptUrl (token fuera del query)', () => {
  it('pone el token en el fragmento, que no viaja al servidor ni en Referer', () => {
    const url = receiptUrl('/book/success', 'CART-1', 'a+b/c=');

    expect(url).toBe('/book/success?bookingId=CART-1#token=a%2Bb%2Fc%3D');
    expect(url.split('#')[0]).not.toContain('token');
  });

  it('sin token no inventa fragmento', () => {
    expect(receiptUrl('/es/book/success', 'CART 1', '')).toBe('/es/book/success?bookingId=CART%201');
  });
});

describe('readReceiptToken (nuevo fragmento + enlaces legacy)', () => {
  it('lee el token del fragmento', () => {
    expect(readReceiptToken('?bookingId=CART-1', '#token=abc123')).toBe('abc123');
  });

  it('sigue leyendo el query de enlaces ya emitidos', () => {
    expect(readReceiptToken('?bookingId=CART-1&token=legacy456', '')).toBe('legacy456');
  });

  it('devuelve vacio si no hay token', () => {
    expect(readReceiptToken('?bookingId=CART-1', '')).toBe('');
    expect(readReceiptToken('', '')).toBe('');
  });
});

describe('transporte del token en las consultas de reserva', () => {
  afterEach(() => {
    vi.unstubAllEnvs();
    vi.unstubAllGlobals();
    vi.resetModules();
  });

  function jsonResponse(data: unknown): Response {
    return new Response(JSON.stringify(data), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    });
  }

  it('bookingStatus.fetchBookingStatus manda X-Booking-Token y limpia la URL', async () => {
    vi.stubEnv('PUBLIC_API_URL', 'https://api.test');
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ status: 'paid' }));
    vi.stubGlobal('fetch', fetchMock);

    const { fetchBookingStatus } = await import('../../src/features/booking/bookingStatus');
    await fetchBookingStatus('CART-1', 'tok/en+=');

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('https://api.test/booking-status?cart_id=CART-1');
    expect(url).not.toContain('token');
    expect((init.headers as Record<string, string>)['X-Booking-Token']).toBe('tok/en+=');
  });

  it('bookingService.getBookingStatus y checkPayment usan cabecera, no query', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ success: true, status: 'paid' }))
      .mockResolvedValueOnce(jsonResponse({ success: true, status: 'approved' }));
    vi.stubGlobal('fetch', fetchMock);

    const { bookingService } = await import('../../src/services/bookingService');
    await bookingService.getBookingStatus('CART-1', 'tok-123');
    await bookingService.checkPayment('CART-1', 'tok-123');

    for (const call of fetchMock.mock.calls as [string, RequestInit][]) {
      const [url, init] = call;
      expect(url).not.toContain('token');
      expect((init.headers as Record<string, string>)['X-Booking-Token']).toBe('tok-123');
    }
  });
});
