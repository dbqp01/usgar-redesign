import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

export function initRoomsGallery(root: HTMLElement): () => void {
  const context = gsap.context((scope) => {
    const cards = gsap.utils.toArray('[data-room-card]') as HTMLElement[];
    if (!cards.length) return;

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduceMotion) return;

    gsap.set(cards, {
      autoAlpha: 0,
      y: 36,
      clipPath: 'inset(0 0 100% 0)',
    });

    const animateBatch = scope.add('animateBatch', (batch: Element[]) => {
      gsap.to(batch, {
        autoAlpha: 1,
        y: 0,
        clipPath: 'inset(0 0 0% 0)',
        duration: 0.9,
        ease: 'power3.out',
        stagger: 0.1,
        overwrite: true,
      });
    });

    ScrollTrigger.batch(cards, {
      start: 'top 86%',
      once: true,
      onEnter: (batch) => animateBatch(batch),
    });
  }, root);

  return () => context.revert();
}
