import gsap from 'gsap';
import { createAutoMotion } from '../utils/autoMotion';

export function initVelocityMarquee(): gsap.Context {
  return gsap.context(() => {
    const track = document.getElementById('velocity-marquee-track');
    const container = document.getElementById('velocity-marquee-section');
    if (!track || !container) return;
    createAutoMotion(track, container, { baseSpeed: 1, velocityFactor: 0.0025 });
  });
}
