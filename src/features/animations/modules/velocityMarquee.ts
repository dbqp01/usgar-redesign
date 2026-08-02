import gsap from 'gsap';
import { createAutoMotion } from '../utils/autoMotion';

export function initVelocityMarquee(): gsap.Context {
  return gsap.context(() => {
    const track = document.getElementById('velocity-marquee-track');
    const container = document.getElementById('velocity-marquee-section');
    if (!track || !container) return;
    const cleanup = createAutoMotion(track, container, { baseSpeed: 1, velocityFactor: 0.0025 });
    // gsap.Context ejecuta el retorno al revert(): libera el ticker y el IntersectionObserver
    return () => cleanup?.();
  });
}
