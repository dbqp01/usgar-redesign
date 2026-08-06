import { describe, it, expect } from 'vitest';
import { normalizePaymentError, statusDetailKey } from '../../src/utils/paymentErrors';

describe('normalizePaymentError (todo 26: respuesta plana -> {code,message,status_detail})', () => {
  it('normaliza la forma Response::error (error anidado con details.status_detail)', () => {
    const res = normalizePaymentError({
      success: false,
      error: {
        code: 'PAYMENT_REJECTED',
        message: 'El pago fue rechazado por la pasarela.',
        details: { status_detail: 'cc_rejected_bad_filled_security_code' },
      },
    });
    expect(res).toEqual({
      code: 'PAYMENT_REJECTED',
      message: 'El pago fue rechazado por la pasarela.',
      status_detail: 'cc_rejected_bad_filled_security_code',
    });
  });

  it('normaliza la forma plana con status/status_detail (gate rejected sin excepcion)', () => {
    const res = normalizePaymentError({
      success: false,
      status: 'rejected',
      status_detail: 'cc_rejected_insufficient_amount',
      message: 'El pago fue rechazado por la pasarela.',
    });
    expect(res).toEqual({
      code: 'PAYMENT_REJECTED',
      message: 'El pago fue rechazado por la pasarela.',
      status_detail: 'cc_rejected_insufficient_amount',
      status: 'rejected',
    });
  });

  it('conserva payment_id y status en la forma plana pending (commit-falla / in_process)', () => {
    const res = normalizePaymentError({
      success: false,
      status: 'pending',
      payment_id: '987',
      message: 'El pago se proceso, pero la confirmacion local fallo; se reconciliara automaticamente.',
    });
    expect(res).toEqual({
      code: 'PAYMENT_REJECTED',
      message: 'El pago se proceso, pero la confirmacion local fallo; se reconciliara automaticamente.',
      status: 'pending',
      payment_id: '987',
    });
  });

  it('respuesta sin status_detail -> generico sin crash', () => {
    const res = normalizePaymentError({ success: false, error: { code: 'PAYMENT_FAILED' } });
    expect(res).not.toBeNull();
    expect(res!.code).toBe('PAYMENT_FAILED');
    expect(res!.status_detail).toBeUndefined();
    expect(res!.message).toBeTruthy();
  });

  it('respuesta sin error ni status (forma plana minima) -> generico sin crash', () => {
    const res = normalizePaymentError({ success: false });
    expect(res).not.toBeNull();
    expect(res!.code).toBe('PAYMENT_FAILED');
    expect(res!.message).toBeTruthy();
  });

  it('respuesta success:true o null -> null (no es un error)', () => {
    expect(normalizePaymentError({ success: true, status: 'approved' })).toBeNull();
    expect(normalizePaymentError(null)).toBeNull();
    expect(normalizePaymentError(undefined)).toBeNull();
  });

  it('error con code ausente -> code por defecto PAYMENT_FAILED', () => {
    const res = normalizePaymentError({ success: false, error: { message: 'boom' } });
    expect(res!.code).toBe('PAYMENT_FAILED');
    expect(res!.message).toBe('boom');
  });
});

describe('statusDetailKey (todo 26/30: status_detail conocido -> key i18n accionable)', () => {
  it('mapea status_detail conocidos de la lista estandar MP', () => {
    expect(statusDetailKey('cc_rejected_bad_filled_security_code')).toBe('payment.statusDetails.cc_rejected_bad_filled_security_code');
    expect(statusDetailKey('cc_rejected_insufficient_amount')).toBe('payment.statusDetails.cc_rejected_insufficient_amount');
    expect(statusDetailKey('cc_rejected_call_for_authorize')).toBe('payment.statusDetails.cc_rejected_call_for_authorize');
    expect(statusDetailKey('cc_rejected_duplicated_payment')).toBe('payment.statusDetails.cc_rejected_duplicated_payment');
    expect(statusDetailKey('cc_rejected_other_reason')).toBe('payment.statusDetails.cc_rejected_other_reason');
  });

  it('status_detail desconocido -> null (mensaje generico)', () => {
    expect(statusDetailKey('cc_rejected_some_future_code')).toBeNull();
    expect(statusDetailKey('')).toBeNull();
    expect(statusDetailKey(null as unknown as string)).toBeNull();
  });

  it('mapea detalles de pending a la misma clave de detalle', () => {
    expect(statusDetailKey('pending_challenge')).toBe('payment.statusDetails.pending_challenge');
  });
});
