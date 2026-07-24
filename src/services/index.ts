/**
 * Barrel exports — punto de entrada centralizado para todos los servicios del frontend.
 * Import: import { bookingService, defaultHttpClient } from '@/services';
 */

// Contracts (types only)
export type {
  IBookingService,
  ApiResult,
  RoomAvailability,
  GuestDetails,
  BookingPayload,
  BookingResponseData,
  BookingStatusData,
} from './contracts/IBookingService';

export type { IHttpClient, HttpResponse } from './contracts/IHttpClient';

// Implementations
export { FetchHttpClient, defaultHttpClient } from './httpClient';
export { BookingService, bookingService } from './bookingService';
