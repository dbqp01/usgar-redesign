// src/utils/paymentStatus.ts
// Estados del flujo de pago -> UI (todos 27/28/31).
// El backend expone status: pending/paid/expired/manual_review/fraud_review/
// expired_paid/in_process/approved/rejected (GetBookingStatusAction +
// ProcessPaymentAction, waves 1-4).

export const PAID_STATUSES: readonly string[] = ['paid', 'approved', 'expired_paid'];
export const PENDING_STATUSES: readonly string[] = [
  'pending',
  'provisional',
  'in_process',
  'authorized',
  'manual_review',
  'fraud_review',
];

export type BookingUiStatus = 'paid' | 'pending' | 'error';

/**
 * Mapea un status del backend a la UI de 3 estados.
 * expired_paid -> paid (el enum nuevo de W2); fraud_review/manual_review ->
 * pending con mensaje "en revision" (todo 27); lo demas -> error.
 */
export function mapBookingStatus(status: string | null | undefined): BookingUiStatus {
  const s = String(status || '');
  if (PAID_STATUSES.includes(s)) return 'paid';
  if (PENDING_STATUSES.includes(s)) return 'pending';
  return 'error';
}

export function isPaidStatus(status: string | null | undefined): boolean {
  return PAID_STATUSES.includes(String(status || ''));
}

export function isPendingStatus(status: string | null | undefined): boolean {
  return PENDING_STATUSES.includes(String(status || ''));
}

/**
 * Clave i18n del mensaje de la tarjeta pending (todo 27): manual_review y
 * fraud_review muestran "en revision"; el resto usa el mensaje de verificacion.
 */
export function pendingMessageKey(status: string | null | undefined): string {
  return status === 'manual_review' || status === 'fraud_review'
    ? 'success.inReview'
    : 'success.verifyingPayment';
}

export type PaymentOutcome = 'redirect_success' | 'show_error';

/**
 * Todo 28: decide el outcome del POST process-payment.
 * - status pending/in_process o payment_id presente -> redirect a success
 *   (el polling de success.astro resuelve; NUNCA apilar reintentos).
 * - booking-status confirma payment_id o status pagado -> redirect.
 * - cualquier otro -> error mostrado (reintento gobernado por todo 31).
 */
export function resolvePaymentOutcome(
  processError: { paymentStatus?: string; paymentId?: string } | null,
  bookingStatus: any
): PaymentOutcome {
  if (processError) {
    if (processError.paymentId) return 'redirect_success';
    if (
      processError.paymentStatus &&
      (isPendingStatus(processError.paymentStatus) || isPaidStatus(processError.paymentStatus))
    ) {
      return 'redirect_success';
    }
  }
  if (bookingStatus) {
    if (bookingStatus.payment_id) return 'redirect_success';
    if (isPaidStatus(bookingStatus.status)) return 'redirect_success';
  }
  return 'show_error';
}

// ---------------------------------------------------------------------------
// Todo 31: plan de reintento tras fallo de red/timeout.
// 1) booking-status -> payment_id -> redirect success.
// 2) payment-check (MP por external_reference) -> pago activo -> redirect.
// 3) ambos vacios -> retry con cooldown deterministico >= 60s; tras N=2
//    bloqueos consecutivos -> revision manual.
// ---------------------------------------------------------------------------

export type RetryAction = 'redirect_success' | 'retry' | 'cooldown' | 'manual_review';

export interface RetryPlan {
  action: RetryAction;
  cooldownSeconds?: number;
}

export interface RetryPlanInput {
  /** Respuesta de GET /api/booking-status (raw) o null si fallo. */
  localStatus: any;
  /** Respuesta de GET /api/payment-check (raw) o null si fallo. */
  paymentCheck: any;
  /** Bloqueos consecutivos previos (fallo de red verificado sin pago). */
  blockedStreak: number;
  /** Epoch ms hasta el que el boton queda en cooldown (0 = sin cooldown). */
  cooldownEndsAt: number;
  /** Epoch ms actual. */
  now: number;
  /** Maximo de bloqueos consecutivos antes de guiar a revision manual. */
  maxBlockedStreak?: number;
}

/** Segundos (ceil) restantes del cooldown; 0 si ya termino o no existe. */
export function cooldownSecondsRemaining(cooldownEndsAt: number, now: number): number {
  if (!cooldownEndsAt || cooldownEndsAt <= now) return 0;
  return Math.ceil((cooldownEndsAt - now) / 1000);
}

/** True si el pago encontrado en MP esta activo (no final-rejected). */
export function isActiveMpPayment(payment: any): boolean {
  if (!payment || !payment.payment_id) return false;
  const s = String(payment.status || '');
  return s === '' || isPaidStatus(s) || isPendingStatus(s);
}

export function planRetryAfterFailure(input: RetryPlanInput): RetryPlan {
  const { localStatus, paymentCheck, blockedStreak, cooldownEndsAt, now } = input;
  const maxStreak = input.maxBlockedStreak ?? 2;

  // 1) Estado local: pago en curso o ya pagado -> redirect.
  if (localStatus && (localStatus.payment_id || isPaidStatus(localStatus.status))) {
    return { action: 'redirect_success' };
  }

  // 2) MP por external_reference: pago activo -> redirect; pago final
  //    rechazado/cancelado -> retry seguro (el pago murio).
  if (paymentCheck) {
    if (isActiveMpPayment(paymentCheck)) return { action: 'redirect_success' };
    if (paymentCheck.payment_id) return { action: 'retry' };
  }

  // 3) Ambos vacios (o no verificables): cooldown deterministico.
  const remaining = cooldownSecondsRemaining(cooldownEndsAt, now);
  if (remaining > 0) return { action: 'cooldown', cooldownSeconds: remaining };
  if (blockedStreak >= maxStreak) return { action: 'manual_review' };
  return { action: 'retry' };
}
