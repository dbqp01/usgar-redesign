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
      y: 24,
    });

    const animateBatch = scope.add('animateBatch', (batch: Element[]) => {
      gsap.to(batch, {
        autoAlpha: 1,
        y: 0,
        duration: 0.65,
        ease: 'power2.out',
        stagger: 0.08,
        overwrite: 'auto',
      });
    });

    ScrollTrigger.batch(scenes, {
      start: 'top 90%',
      once: true,
      onEnter: (batch) => animateBatch(batch),
    });
  }, root);

  return () => context.revert();
}
