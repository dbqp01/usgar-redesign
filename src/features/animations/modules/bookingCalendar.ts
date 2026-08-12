// src/features/animations/modules/bookingCalendar.ts
// Entrada animada de los dias del calendario custom (patron batch).

import { gsap } from 'gsap';

export function animateCalendarDays(container: HTMLElement): () => void {
  const days = gsap.utils.toArray<HTMLElement>('[data-calendar-day]', container);
  if (!days.length) return () => {};

  // Guarantee calendar days are always 100% visible
  gsap.set(days, { autoAlpha: 1 });
  return () => {};
}
