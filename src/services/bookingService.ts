import type { IHttpClient } from './contracts/IHttpClient';
import { defaultHttpClient } from './httpClient';
import { normalizePaymentError } from '../utils/paymentErrors';
import type {
  IBookingService,
  ApiResult,
  RoomAvailability,
  BookingPayload,
  BookingResponseData,
  CalendarAvailability,
} from './contracts/IBookingService';

/**
 * Servicio de Negocio de Reservas para el Frontend Astro.
 */
export class BookingService implements IBookingService {
  private readonly httpClient: IHttpClient;
  private readonly baseUrl: string;

  constructor(httpClient: IHttpClient = defaultHttpClient, baseUrl = '/api') {
    this.httpClient = httpClient;
    this.baseUrl = baseUrl;
  }

  async getAvailableRooms(checkIn?: string, checkOut?: string): Promise<ApiResult<RoomAvailability[]>> {
    const query = new URLSearchParams();
    if (checkIn) query.append('checkIn', checkIn);
    if (checkOut) query.append('checkOut', checkOut);

    const url = `${this.baseUrl}/rooms${query.toString() ? '?' + query.toString() : ''}`;
    const response = await this.httpClient.get<any>(url);

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
  }

  async getAvailabilityCalendar(from?: string, to?: string): Promise<ApiResult<CalendarAvailability>> {
    const query = new URLSearchParams();
    if (from) query.append('from', from);
    if (to) query.append('to', to);

    const url = `${this.baseUrl}/rooms/calendar${query.toString() ? '?' + query.toString() : ''}`;
    const response = await this.httpClient.get<any>(url);

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
  }

  async createHold(payload: BookingPayload): Promise<ApiResult<BookingResponseData>> {
    const url = `${this.baseUrl}/booking`;
    const response = await this.httpClient.post<any>(url, payload);

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
  }

  async processPayment(cartId: string, accessToken: string, paymentData: any): Promise<ApiResult<any>> {
    const url = `${this.baseUrl}/process-payment`;
    const response = await this.httpClient.post<any>(url, {
      cart_id: cartId,
      access_token: accessToken,
      payment_data: paymentData
    });

    if (!response.ok || !response.data?.success) {
      // Todo 26: normalizar cualquier forma plana -> {code, message, status_detail}.
      // El backend responde Response::error (error.details.status_detail) o
      // plano (status/status_detail/payment_id) — unificados aqui.
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
  }

  /** GET /api/booking-status?cart_id&token — estado del hold (todos 27/28/29/31). */
  async getBookingStatus(cartId: string, accessToken: string): Promise<ApiResult<any>> {
    const query = new URLSearchParams({ cart_id: cartId });
    if (accessToken) query.append('token', accessToken);
    const response = await this.httpClient.get<any>(`${this.baseUrl}/booking-status?${query.toString()}`);

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
  }

  /** GET /api/payment-check?cart_id&token — pago en MP por external_reference (todo 31). */
  async checkPayment(cartId: string, accessToken: string): Promise<ApiResult<any>> {
    const query = new URLSearchParams({ cart_id: cartId });
    if (accessToken) query.append('token', accessToken);
    const response = await this.httpClient.get<any>(`${this.baseUrl}/payment-check?${query.toString()}`);

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
  }
}

// Instancia global por defecto
export const bookingService = new BookingService();
