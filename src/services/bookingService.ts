import { normalizePaymentError } from '../utils/paymentErrors';

export interface RoomAvailability {
  id: string;
  slug: string;
  name: string;
  pricePerNight: number;
  available: boolean;
  maxGuests: number;
  description?: string;
  images?: string[];
  /** Tarifas servidas por el backend (fuente única). standard = precio QloApps, non_refundable = -descuento config. */
  rate_plans?: { standard: number; non_refundable: number };
}

export interface GuestDetails {
  firstName: string;
  lastName: string;
  email: string;
  phone: string;
  documentType?: string;
  documentNumber?: string;
  specialRequests?: string;
}

export interface BookingPayload {
  roomSlug: string;
  checkIn: string;
  checkOut: string;
  guests: number;
  guestDetails: GuestDetails;
  /** Tarifa elegida; el backend calcula y congela el precio (nunca confiar en precios del cliente). */
  rateType?: 'standard' | 'non_refundable';
}

export interface BookingResponseData {
  booking_id?: string;
  cart_id?: string;
  access_token?: string;
  mp_public_key?: string;
  status?: string;
  expires_at?: string;
  total_amount?: number;
  gateway_price?: number;
  price?: number;
  currency?: string;
  mock_mode?: boolean;
  message?: string;
}

export type ApiResult<T> =
  | { success: true; data: T }
  | { success: false; error: { code: string; message: string; status?: number; missingCredentials?: boolean; statusDetail?: string; paymentStatus?: string; paymentId?: string } };

export type CalendarAvailability = Record<string, Record<string, number>>;

async function request(url: string, init: RequestInit = {}): Promise<{ ok: boolean; status: number; data: any }> {
  try {
    const res = await fetch(url, {
      ...init,
      headers: {
        'Accept': 'application/json',
        ...(init.body ? { 'Content-Type': 'application/json' } : {}),
        ...init.headers,
      },
      signal: init.signal ?? AbortSignal.timeout(10000),
    });

    let data: any;
    const contentType = res.headers.get('content-type') || '';
    if (contentType.includes('application/json')) {
      try {
        data = await res.json();
      } catch {
        data = { message: 'Invalid JSON response' };
      }
    } else {
      const text = await res.text();
      data = text ? { message: text } : {};
    }

    return { ok: res.ok, status: res.status, data };
  } catch (error: any) {
    const isAbort = error?.name === 'AbortError' || error?.name === 'TimeoutError';
    return {
      ok: false,
      status: isAbort ? 408 : 503,
      data: {
        success: false,
        error: {
          code: isAbort ? 'TIMEOUT_ERROR' : 'NETWORK_ERROR',
          message: isAbort
            ? 'La peticion excedio el tiempo limite de espera (timeout).'
            : (error?.message || 'Error de conexion de red al servidor.'),
        },
      },
    };
  }
}

/**
 * Servicio de Negocio de Reservas para el Frontend Astro.
 * Utiliza fetch nativo con AbortSignal.timeout() de forma directa.
 */
export const bookingService = {
  async getAvailableRooms(checkIn?: string, checkOut?: string, lang?: string): Promise<ApiResult<RoomAvailability[]>> {
    const query = new URLSearchParams();
    if (checkIn) query.append('checkIn', checkIn);
    if (checkOut) query.append('checkOut', checkOut);
    if (lang) query.append('lang', lang);

    const url = `/api/rooms${query.toString() ? '?' + query.toString() : ''}`;
    const response = await request(url, { method: 'GET' });

    if (!response.ok || !response.data?.success) {
      const err = response.data?.error || {};
      const isMissingCreds = err.code === 'MISSING_CREDENTIALS' || err.message?.toLowerCase().includes('credenci');
      
      return {
        success: false,
        error: {
          code: err.code || 'API_ERROR',
          message: isMissingCreds
            ? 'Faltan credenciales de configuracion en el backend para consultar la disponibilidad en tiempo real.'
            : (err.message || 'Error al comunicarse con el servicio de habitaciones.'),
          status: response.status,
          missingCredentials: isMissingCreds,
        },
      };
    }

    return {
      success: true,
      data: (response.data.rooms ?? response.data.data) as RoomAvailability[],
    };
  },

  async getAvailabilityCalendar(from?: string, to?: string): Promise<ApiResult<CalendarAvailability>> {
    const query = new URLSearchParams();
    if (from) query.append('from', from);
    if (to) query.append('to', to);

    const url = `/api/rooms/calendar${query.toString() ? '?' + query.toString() : ''}`;
    const response = await request(url, { method: 'GET' });

    if (!response.ok || !response.data?.success) {
      const err = response.data?.error || {};
      return {
        success: false,
        error: {
          code: err.code || 'API_ERROR',
          message: err.message || 'Error al consultar el calendario de disponibilidad.',
          status: response.status,
        },
      };
    }

    return {
      success: true,
      data: (response.data.days ?? {}) as CalendarAvailability,
    };
  },

  async createHold(payload: BookingPayload): Promise<ApiResult<BookingResponseData>> {
    const url = `/api/booking`;
    const response = await request(url, {
      method: 'POST',
      body: JSON.stringify(payload),
    });

    if (!response.ok || !response.data?.success) {
      const err = response.data?.error || {};
      const isMissingCreds = err.code === 'MISSING_CREDENTIALS' || err.message?.toLowerCase().includes('credenci');

      return {
        success: false,
        error: {
          code: err.code || 'BOOKING_FAILED',
          message: isMissingCreds
            ? 'Faltan credenciales activas (Mercado Pago / QloApps) en el backend para procesar el pago y retencion.'
            : (err.message || 'No se pudo crear la reserva en el servidor.'),
          status: response.status,
          missingCredentials: isMissingCreds,
        },
      };
    }

    return {
      success: true,
      data: (response.data.data ?? response.data) as BookingResponseData,
    };
  },

  async processPayment(cartId: string, accessToken: string, paymentData: any): Promise<ApiResult<any>> {
    const url = `/api/process-payment`;
    const response = await request(url, {
      method: 'POST',
      body: JSON.stringify({
        cart_id: cartId,
        access_token: accessToken,
        payment_data: paymentData,
      }),
    });

    if (!response.ok || !response.data?.success) {
      const normalized = normalizePaymentError(response.data) || {
        code: 'PAYMENT_FAILED',
        message: 'Error al procesar el pago.',
      };
      return {
        success: false,
        error: {
          code: normalized.code,
          message: normalized.message,
          status: response.status,
          statusDetail: normalized.status_detail,
          paymentStatus: normalized.status,
          paymentId: normalized.payment_id,
        },
      };
    }

    return {
      success: true,
      data: response.data,
    };
  },

  async getBookingStatus(cartId: string, accessToken: string): Promise<ApiResult<any>> {
    const query = new URLSearchParams({ cart_id: cartId });
    if (accessToken) query.append('token', accessToken);
    const response = await request(`/api/booking-status?${query.toString()}`, { method: 'GET' });

    if (!response.ok || !response.data?.success) {
      return {
        success: false,
        error: {
          code: 'BOOKING_STATUS_FAILED',
          message: 'No se pudo consultar el estado de la reserva.',
          status: response.status,
        },
      };
    }
    return { success: true, data: response.data };
  },

  async checkPayment(cartId: string, accessToken: string): Promise<ApiResult<any>> {
    const query = new URLSearchParams({ cart_id: cartId });
    if (accessToken) query.append('token', accessToken);
    const response = await request(`/api/payment-check?${query.toString()}`, { method: 'GET' });

    if (!response.ok || !response.data?.success) {
      return {
        success: false,
        error: {
          code: 'PAYMENT_CHECK_FAILED',
          message: 'No se pudo verificar el estado del pago.',
          status: response.status,
        },
      };
    }
    return { success: true, data: response.data };
  },
};
