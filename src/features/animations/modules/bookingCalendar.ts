// src/features/animations/modules/bookingCalendar.ts
// Entrada animada de los dias del calendario custom (patron batch).

import { gsap } from 'gsap';

export function animateCalendarDays(container: HTMLElement): () => void {
  const context = gsap.context(() => {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const days = gsap.utils.toArray<HTMLElement>('[data-calendar-day]', container);
    if (!days.length) return;

    if (reduceMotion) {
      gsap.set(days, { autoAlpha: 1 });
      return;
    }

    gsap.from(days, {
      autoAlpha: 0,
      y: 8,
      scale: 0.96,
      duration: 0.35,
      ease: 'power2.out',
      stagger: 0.012,
      overwrite: true,
    });
  }, container);

  return () => context.revert();
}
