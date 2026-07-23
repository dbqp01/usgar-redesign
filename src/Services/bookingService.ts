import type { IHttpClient } from './interfaces/IHttpClient';
import { defaultHttpClient } from './httpClient';
import type {
  IBookingService,
  ApiResult,
  RoomAvailability,
  BookingPayload,
  BookingResponseData,
  BookingStatusData,
} from './interfaces/IBookingService';

/**
 * Servicio de Negocio de Reservas.
 * Inyecta IHttpClient para desacoplamiento (DIP).
 * NUNCA genera mockups o placeholders si la API falla: si funciona la API la muestra;
 * si faltan credenciales o falla, notifica explícitamente el error real.
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
            ? 'Faltan credenciales de configuración en el backend para consultar la disponibilidad en tiempo real.'
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

  async createHoldAndPreference(payload: BookingPayload): Promise<ApiResult<BookingResponseData>> {
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
            ? 'Faltan credenciales activas (Mercado Pago / QloApps) en el backend para procesar el pago y retención.'
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

  async extendHoldSession(bookingId: string): Promise<ApiResult<{ extended: boolean; new_expires_at: string }>> {
    const url = `${this.baseUrl}/extend-hold`;
    const response = await this.httpClient.post<any>(url, { cart_id: bookingId });

    if (!response.ok || !response.data?.success) {
      const err = response.data?.error || {};
      return {
        success: false,
        error: {
          code: err.code || 'EXTEND_HOLD_FAILED',
          message: err.message || 'No se pudo extender el temporizador de retención de la reserva.',
          status: response.status,
        },
      };
    }

    return {
      success: true,
      data: {
        extended: true,
        new_expires_at: response.data.expires_at ?? response.data.data?.expires_at,
      },
    };
  }

  async getBookingStatus(bookingId: string): Promise<ApiResult<BookingStatusData>> {
    const url = `${this.baseUrl}/booking-status?cart_id=${encodeURIComponent(bookingId)}`;
    const response = await this.httpClient.get<any>(url);

    if (!response.ok || !response.data?.success) {
      const err = response.data?.error || {};
      return {
        success: false,
        error: {
          code: err.code || 'STATUS_CHECK_FAILED',
          message: err.message || 'No se pudo verificar el estado de la reserva.',
          status: response.status,
        },
      };
    }

    return {
      success: true,
      data: (response.data.data ?? response.data) as BookingStatusData,
    };
  }
}

// Instancia global por defecto
export const bookingService = new BookingService();
