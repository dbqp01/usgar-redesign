import type { IHttpClient } from './contracts/IHttpClient';
import { defaultHttpClient } from './httpClient';
import type {
  IBookingService,
  ApiResult,
  RoomAvailability,
  BookingPayload,
  BookingResponseData,
  BookingStatusData,
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
            ? 'Faltan credenciales de configuraciÃ³n en el backend para consultar la disponibilidad en tiempo real.'
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
            ? 'Faltan credenciales activas (Mercado Pago / QloApps) en el backend para procesar el pago y retenciÃ³n.'
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
      const err = response.data?.error || {};
      return {
        success: false,
        error: {
          code: err.code || 'PAYMENT_FAILED',
          message: err.message || 'Error al procesar el pago.',
          status: response.status,
        },
      };
    }

    return {
      success: true,
      data: response.data,
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
          message: err.message || 'No se pudo extender el temporizador de retenciÃ³n de la reserva.',
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

  subscribeToRoomAvailability(
    checkIn?: string,
    checkOut?: string,
    callback?: (rooms: RoomAvailability[]) => void,
    intervalMs = 4000
  ): () => void {
    if (!callback) return () => {};

    const fetchAndUpdate = async () => {
      const res = await this.getAvailableRooms(checkIn, checkOut);
      if (res.success && Array.isArray(res.data)) {
        callback(res.data);
      }
    };

    // Ejecucion inicial inmediata
    fetchAndUpdate();

    // Auto-polling en segundo plano
    const timerId = setInterval(fetchAndUpdate, intervalMs);

    return () => {
      clearInterval(timerId);
    };
  }
}

// Instancia global por defecto
export const bookingService = new BookingService();
