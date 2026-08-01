import gsap from 'gsap';
import { createAutoMotion } from '../utils/autoMotion';

// Horizontal magazine carousel: seamless track, continuous autoplay,
// drag with inertia, scroll velocity boost — never pauses on hover.
export function initMagazineCarousel(container: HTMLElement, reverse = false): () => void {
  const track = container.querySelector<HTMLElement>('.magazine-track');
  if (!track) return () => {};
  return createAutoMotion(track, container, {
    baseSpeed: 0.8,
    direction: reverse ? -1 : 1,
    velocityFactor: 0.004,
    draggable: true,
    dragFactor: 0.6,
  });
}
