// src/utils/holdCountdown.ts
// Countdown del hold de reserva (todo 29): el backend expone
// time_left_seconds en GetBookingStatusAction; el UI muestra el countdown y
// llama extend-hold UNA vez al llegar a ~60s.
// (audit 2026-08-12: time_left_seconds confirmado en GetBookingStatusAction;
// POST /api/extend-hold registrado en public/index.php)

/** Formatea segundos a mm:ss para el countdown visible. */
export function formatCountdown(totalSeconds: number): string {
  const s = Math.max(0, Math.floor(totalSeconds));
  const minutes = Math.floor(s / 60);
  const seconds = s % 60;
  return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
}

/**
 * Decide si hay que llamar extend-hold: true SOLO cuando quedan
 * <= threshold segundos, aun no se extendio y el hold sigue vivo.
 */
export function shouldExtendHold(
  timeLeftSeconds: number,
  thresholdSeconds = 60,
  alreadyExtended = false
): boolean {
  if (alreadyExtended) return false;
  if (timeLeftSeconds <= 0) return false;
  return timeLeftSeconds <= thresholdSeconds;
}
