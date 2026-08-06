/**
 * Interfaces y contratos de Dominio para Servicios de Reserva y Consulta de Disponibilidad.
 */

export interface RoomAvailability {
  id: string;
  slug: string;
  name: string;
  pricePerNight: number;
  available: boolean;
  maxGuests: number;
  description?: string;
  images?: string[];
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

/** Disponibilidad por día y por habitación para pintar el calendario. */
export type CalendarAvailability = Record<string, Record<string, number>>;

export interface IBookingService {
  getAvailableRooms(checkIn?: string, checkOut?: string): Promise<ApiResult<RoomAvailability[]>>;
  getAvailabilityCalendar(from?: string, to?: string): Promise<ApiResult<CalendarAvailability>>;
  createHold(payload: BookingPayload): Promise<ApiResult<BookingResponseData>>;
  processPayment(cartId: string, accessToken: string, paymentData: any): Promise<ApiResult<any>>;
  getBookingStatus(cartId: string, accessToken: string): Promise<ApiResult<any>>;
  checkPayment(cartId: string, accessToken: string): Promise<ApiResult<any>>;
}
