// src/features/animations/modules/exploreCards.ts
// Entrada en batch de las cards de explore (patron roomsGallery).

import { gsap } from 'gsap';
import { revealOnEnter } from './globalReveals';

export function initExploreCards(root: HTMLElement): () => void {
  const cards = gsap.utils.toArray('[data-explore-card]', root) as HTMLElement[];
  if (!cards.length) return () => {};

  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return () => {};

  return revealOnEnter(cards, { y: 28, duration: 0.8, stagger: 0.08, ease: 'power3.out' });
}
