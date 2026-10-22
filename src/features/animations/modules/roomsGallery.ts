import { gsap } from 'gsap';
import { revealOnEnter } from './globalReveals';

export function initRoomsGallery(root: HTMLElement): () => void {
  const cards = gsap.utils.toArray('[data-room-card]', root) as HTMLElement[];
  if (!cards.length) return () => {};

  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return () => {};

  return revealOnEnter(cards, { y: 24, duration: 0.65, stagger: 0.08 });
}
