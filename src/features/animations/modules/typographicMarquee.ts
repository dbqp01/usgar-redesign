import gsap from 'gsap';
import { createAutoMotion } from '../utils/autoMotion';

// Typographic editorial marquees — continuous, scroll-boosted, never pause on hover.
export function initTypographicMarquee(container: HTMLElement, reverse = false): gsap.Context {
  return gsap.context(() => {
    const track = container.querySelector<HTMLElement>('.marquee-tipographic-track');
    if (!track) return;
    createAutoMotion(track, container, {
      baseSpeed: 1.4,
      direction: reverse ? -1 : 1,
      velocityFactor: 0.004,
    });
  });
}
