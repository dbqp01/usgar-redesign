import gsap from 'gsap';
import { createAutoMotion } from '../utils/autoMotion';

export function initVelocityMarquee(): gsap.Context {
  // Solo desktop: la traslacion infinita del marquee (ticker GSAP continuo)
  // es caro en moviles de gama baja — en movil el texto queda estatico
  // (fix rendimiento movil 2026-08-16).
  if (typeof window !== 'undefined' && window.matchMedia('(max-width: 767px)').matches) {
    return gsap.context(() => {});
  }
  return gsap.context(() => {
    const track = document.getElementById('velocity-marquee-track');
    const container = document.getElementById('velocity-marquee-section');
    if (!track || !container) return;
    const cleanup = createAutoMotion(track, container, { baseSpeed: 1, velocityFactor: 0.0025 });
    // gsap.Context ejecuta el retorno al revert(): libera el ticker y el IntersectionObserver
    return () => cleanup?.();
  });
}
