// src/utils/paymentErrors.ts
// Normalizacion de errores de pago del backend (todo 26).
// El backend responde en DOS formas planas:
//   1) Response::error  -> { success:false, error:{ code, message, details:{ status_detail } } }
//   2) Gates/estados    -> { success:false, status, status_detail?, message?, payment_id? }
// Esta utilidad unifica ambas a { code, message, status_detail } y expone el
// mapeo status_detail -> clave i18n accionable (lista estandar MP, verificada
// con mercadopago-mcp-server search_documentation "rejected payments
// status_detail" — MPE/es, 2026-08-06).
// (audit 2026-08-12: forma 1 confirmada en Response::error + rama MPApiException
// de ProcessPaymentAction; forma 2 en los gates pending/expired/rejected/commit-falla)

export interface NormalizedPaymentError {
  code: string;
  message: string;
  status_detail?: string;
  /** Estado plano del pago (pending/in_process/rejected/expired/...) cuando viene del backend. */
  status?: string;
  /** payment_id plano (ramas pending/commit-falla) para reconciliacion del todo 31. */
  payment_id?: string;
}

/**
 * Status_detail estandar de la lista MP (rejected-payments / collection-results).
 * Solo los que tienen traduccion accionable para el usuario final.
 */
const KNOWN_STATUS_DETAILS: readonly string[] = [
  'pending_challenge',
  'pending_waiting_transfer',
  'cc_rejected_3ds_challenge',
  'cc_rejected_3ds_mandatory',
  'cc_rejected_bad_filled_card_number',
  'cc_rejected_bad_filled_date',
  'cc_rejected_bad_filled_other',
  'cc_rejected_bad_filled_security_code',
  'cc_rejected_blacklist',
  'cc_rejected_call_for_authorize',
  'cc_rejected_card_disabled',
  'cc_rejected_card_error',
  'cc_rejected_duplicated_payment',
  'cc_rejected_high_risk',
  'cc_rejected_insufficient_amount',
  'cc_rejected_invalid_installments',
  'cc_rejected_max_attempts',
  'cc_rejected_other_reason',
  'cc_amount_rate_limit_exceeded',
  'rejected_insufficient_data',
  'rejected_by_bank',
  'rejected_by_regulations',
  'insufficient_amount',
  'cc_rejected_card_type_not_allowed',
  'bank_error',
];

/**
 * Normaliza cualquier forma plana de error del backend de pagos a
 * { code, message, status_detail }. Nunca lanza; respuestas sin error
 * devuelven null (no es un error).
 */
export function normalizePaymentError(data: unknown): NormalizedPaymentError | null {
  if (!data || typeof data !== 'object') return null;
  const d = data as Record<string, any>;
  if (d.success !== false) return null;

  const err = d.error;
  if (err && typeof err === 'object') {
    const details = (err as Record<string, any>).details;
    const statusDetail = details && typeof details === 'object'
      ? String((details as Record<string, any>).status_detail || '')
      : '';
    return {
      code: String((err as Record<string, any>).code || 'PAYMENT_FAILED'),
      message: String((err as Record<string, any>).message || 'Error al procesar el pago.'),
      status_detail: statusDetail !== '' ? statusDetail : undefined,
    };
  }

  // Forma plana con status (gates de ProcessPaymentAction: pending/expired/
  // rejected/commit-falla). Sin status -> generico (PAYMENT_FAILED).
  const flatStatus = d.status ? String(d.status) : undefined;
  return {
    code: flatStatus ? 'PAYMENT_REJECTED' : 'PAYMENT_FAILED',
    message: String(d.message || 'Error al procesar el pago.'),
    status_detail: d.status_detail ? String(d.status_detail) : undefined,
    status: flatStatus,
    payment_id: d.payment_id ? String(d.payment_id) : undefined,
  };
}

/**
 * Devuelve la clave i18n de un status_detail conocido, o null si el detalle
 * no tiene traduccion accionable (-> mensaje generico).
 */
export function statusDetailKey(statusDetail: string | null | undefined): string | null {
  if (!statusDetail) return null;
  return KNOWN_STATUS_DETAILS.includes(statusDetail)
    ? `payment.statusDetails.${statusDetail}`
    : null;
}

/**
 * Clave i18n del mensaje generico de error de pago (fallback cuando el
 * status_detail es desconocido o no hay detalle).
 */
export function paymentErrorFallbackKey(): string {
  return 'payment.rejectedGeneric';
}
