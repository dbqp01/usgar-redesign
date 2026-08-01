import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

export function initServicesScenes(root: HTMLElement): () => void {
  const context = gsap.context((scope) => {
    const scenes = gsap.utils.toArray('[data-service-scene]') as HTMLElement[];
    if (!scenes.length) return;

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduceMotion) return;

    gsap.set(scenes, {
      autoAlpha: 0,
      y: 42,
      clipPath: 'inset(0 0 100% 0)',
    });

    const animateBatch = scope.add('animateBatch', (batch: Element[]) => {
      gsap.to(batch, {
        autoAlpha: 1,
        y: 0,
        clipPath: 'inset(0 0 0% 0)',
        duration: 0.95,
        ease: 'power3.out',
        stagger: 0.12,
        overwrite: true,
      });
    });

    ScrollTrigger.batch(scenes, {
      start: 'top 86%',
      once: true,
      onEnter: (batch) => animateBatch(batch),
    });
  }, root);

  return () => context.revert();
}
