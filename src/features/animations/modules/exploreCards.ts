// src/features/animations/modules/exploreCards.ts
// Entrada en batch de las cards de explore (patron roomsGallery).

import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

export function initExploreCards(root: HTMLElement): () => void {
  const context = gsap.context((scope) => {
    const cards = gsap.utils.toArray('[data-explore-card]') as HTMLElement[];
    if (!cards.length) return;

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduceMotion) return;

    gsap.set(cards, { autoAlpha: 0, y: 28 });

    const animateBatch = scope.add('animateBatch', (batch: Element[]) => {
      gsap.to(batch, {
        autoAlpha: 1,
        y: 0,
        duration: 0.8,
        ease: 'power3.out',
        stagger: 0.08,
        overwrite: true,
      });
    });

    ScrollTrigger.batch(cards, {
      start: 'top 88%',
      once: true,
      onEnter: (batch) => animateBatch(batch),
    });
  }, root);

  return () => context.revert();
}
