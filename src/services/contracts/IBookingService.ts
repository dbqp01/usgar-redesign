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
  booking_id: string;
  status: string;
  expires_at: string;
  preference_url?: string;
  init_point?: string;
  total_amount?: number;
  currency?: string;
  mock_mode?: boolean;
  message?: string;
}

export interface BookingStatusData {
  booking_id: string;
  status: string;
  qloapp_order_id?: string;
  channex_status?: string;
  guest_name?: string;
  room_name?: string;
  check_in?: string;
  check_out?: string;
  amount_paid?: number;
  created_at?: string;
}

export type ApiResult<T> =
  | { success: true; data: T }
  | { success: false; error: { code: string; message: string; status?: number; missingCredentials?: boolean } };

export interface IBookingService {
  getAvailableRooms(checkIn?: string, checkOut?: string): Promise<ApiResult<RoomAvailability[]>>;
  createHoldAndPreference(payload: BookingPayload): Promise<ApiResult<BookingResponseData>>;
  extendHoldSession(bookingId: string): Promise<ApiResult<{ extended: boolean; new_expires_at: string }>>;
  getBookingStatus(bookingId: string): Promise<ApiResult<BookingStatusData>>;
}
