import { describe, it, expect } from 'vitest';
import {
  mapBookingStatus,
  isPaidStatus,
  isPendingStatus,
  resolvePaymentOutcome,
  planRetryAfterFailure,
  cooldownSecondsRemaining,
  type PaymentOutcome,
} from '../../src/utils/paymentStatus';

/** Helper tipado del outcome para los tests (payload del error de processPayment). */
function outcomeFor(processError: { paymentStatus?: string; paymentId?: string; statusDetail?: string } | null, bookingStatus: any): PaymentOutcome {
  return resolvePaymentOutcome(processError, bookingStatus);
}

describe('mapBookingStatus (todo 27: estados del flujo -> UI)', () => {
  it('expired_paid se muestra como pagado (enum nuevo de W2)', () => {
    expect(mapBookingStatus('expired_paid')).toBe('paid');
    expect(isPaidStatus('expired_paid')).toBe(true);
  });

  it('fraud_review y manual_review se muestran como pendiente (no error)', () => {
    expect(mapBookingStatus('fraud_review')).toBe('pending');
    expect(mapBookingStatus('manual_review')).toBe('pending');
    expect(isPendingStatus('fraud_review')).toBe(true);
    expect(isPendingStatus('manual_review')).toBe(true);
  });

  it('paid/approved -> pagado; pending/in_process -> pendiente; expired/rejected -> error', () => {
    expect(mapBookingStatus('paid')).toBe('paid');
    expect(mapBookingStatus('approved')).toBe('paid');
    expect(mapBookingStatus('pending')).toBe('pending');
    expect(mapBookingStatus('in_process')).toBe('pending');
    expect(mapBookingStatus('provisional')).toBe('pending');
    expect(mapBookingStatus('expired')).toBe('error');
    expect(mapBookingStatus('rejected')).toBe('error');
    expect(mapBookingStatus('failed')).toBe('error');
  });

  it('status vacio/desconocido -> error sin crash', () => {
    expect(mapBookingStatus('')).toBe('error');
    expect(mapBookingStatus('whatever')).toBe('error');
  });
});

describe('resolvePaymentOutcome (todo 28: pending -> redirect a success, no apilar)', () => {
  it('processPayment con status pending/in_process -> redirect a success', () => {
    expect(outcomeFor({ paymentStatus: 'pending', paymentId: '987' }, null)).toBe('redirect_success');
    expect(outcomeFor({ paymentStatus: 'in_process' }, null)).toBe('redirect_success');
  });

  it('processPayment con payment_id (commit-falla) -> redirect a success', () => {
    expect(outcomeFor({ paymentStatus: 'pending', paymentId: '987' }, null)).toBe('redirect_success');
  });

  it('reintento bloqueado: booking-status con payment_id -> redirect a success', () => {
    expect(outcomeFor(null, { payment_id: '555', status: 'pending' })).toBe('redirect_success');
  });

  it('booking-status paid/approved/expired_paid -> redirect a success', () => {
    expect(outcomeFor(null, { status: 'paid' })).toBe('redirect_success');
    expect(outcomeFor(null, { status: 'expired_paid' })).toBe('redirect_success');
  });

  it('error de rechazo sin pago en curso -> show_error (reintento permitido tras cooldown)', () => {
    expect(outcomeFor({ paymentStatus: 'rejected', statusDetail: 'cc_rejected_other_reason' }, null)).toBe('show_error');
    expect(outcomeFor({ paymentStatus: 'expired' }, null)).toBe('show_error');
    expect(outcomeFor(null, null)).toBe('show_error');
  });
});

describe('planRetryAfterFailure (todo 31: pre-retry booking-status + payment-check + cooldown)', () => {
  const now = 1_800_000_000_000;

  it('booking-status con payment_id -> redirect a success (nunca segundo pago)', () => {
    const plan = planRetryAfterFailure({
      localStatus: { payment_id: '555', status: 'pending' },
      paymentCheck: null,
      blockedStreak: 0,
      cooldownEndsAt: 0,
      now,
    });
    expect(plan.action).toBe('redirect_success');
  });

  it('payment-check con pago activo (commit-falla sin attach) -> redirect a success', () => {
    const plan = planRetryAfterFailure({
      localStatus: { status: 'pending' },
      paymentCheck: { payment_id: '777', status: 'approved' },
      blockedStreak: 0,
      cooldownEndsAt: 0,
      now,
    });
    expect(plan.action).toBe('redirect_success');
  });

  it('payment-check con pago en pending/in_process -> redirect a success', () => {
    const plan = planRetryAfterFailure({
      localStatus: { status: 'pending' },
      paymentCheck: { payment_id: '777', status: 'in_process' },
      blockedStreak: 0,
      cooldownEndsAt: 0,
      now,
    });
    expect(plan.action).toBe('redirect_success');
  });

  it('payment-check con pago final rechazado -> retry permitido (el pago murio)', () => {
    const plan = planRetryAfterFailure({
      localStatus: { status: 'pending' },
      paymentCheck: { payment_id: '777', status: 'rejected', status_detail: 'cc_rejected_other_reason' },
      blockedStreak: 0,
      cooldownEndsAt: 0,
      now,
    });
    expect(plan.action).toBe('retry');
  });

  it('ambos vacios y sin cooldown -> retry seguro', () => {
    const plan = planRetryAfterFailure({
      localStatus: { status: 'pending' },
      paymentCheck: { payment_id: null, status: '' },
      blockedStreak: 0,
      cooldownEndsAt: 0,
      now,
    });
    expect(plan.action).toBe('retry');
  });

  it('cooldown activo -> cooldown con segundos restantes visibles', () => {
    const plan = planRetryAfterFailure({
      localStatus: { status: 'pending' },
      paymentCheck: { payment_id: null, status: '' },
      blockedStreak: 0,
      cooldownEndsAt: now + 45_000,
      now,
    });
    expect(plan.action).toBe('cooldown');
    expect(plan.cooldownSeconds).toBe(45);
  });

  it('tras N=2 bloqueos consecutivos -> guiar a revision manual', () => {
    const plan = planRetryAfterFailure({
      localStatus: { status: 'pending' },
      paymentCheck: { payment_id: null, status: '' },
      blockedStreak: 2,
      cooldownEndsAt: 0,
      now,
    });
    expect(plan.action).toBe('manual_review');
  });

  it('primer bloqueo (streak 1) aun permite retry', () => {
    const plan = planRetryAfterFailure({
      localStatus: { status: 'pending' },
      paymentCheck: { payment_id: null, status: '' },
      blockedStreak: 1,
      cooldownEndsAt: 0,
      now,
    });
    expect(plan.action).toBe('retry');
  });
});

describe('cooldownSecondsRemaining (todo 31: cooldown deterministico >= 60s)', () => {
  it('devuelve 0 cuando el cooldown ya termino o no existe', () => {
    expect(cooldownSecondsRemaining(0, 1_800_000_000_000)).toBe(0);
    expect(cooldownSecondsRemaining(1_800_000_000_000, 1_800_000_000_000)).toBe(0);
  });

  it('devuelve segundos restantes (ceil) cuando el cooldown esta activo', () => {
    expect(cooldownSecondsRemaining(1_800_000_000_000 + 61_000, 1_800_000_000_000)).toBe(61);
    expect(cooldownSecondsRemaining(1_800_000_000_000 + 1_000, 1_800_000_000_000)).toBe(1);
  });
});
