import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

export function initGlobalReveals(): gsap.Context {
  return gsap.context(() => {
    const elements = gsap.utils.toArray('.animate-on-scroll') as HTMLElement[];
    if (!elements.length) return;

    if (typeof IntersectionObserver !== 'undefined') {
      const observer = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              entry.target.classList.add('visible');
              observer.unobserve(entry.target);
            }
          });
        },
        { rootMargin: '0px 0px -40px 0px', threshold: 0.05 }
      );
      elements.forEach((el) => observer.observe(el));
      return () => observer.disconnect();
    } else {
      ScrollTrigger.batch(elements, {
        once: true,
        onEnter: (batch) => {
          batch.forEach((el) => el.classList.add('visible'));
        },
      });
    }
  });
}

interface RevealOptions {
  y?: number;
  duration?: number;
  stagger?: number;
  ease?: string;
}

/**
 * Reveal por IntersectionObserver: mismo efecto que ScrollTrigger.batch pero
 * sin crear un ScrollTrigger por elemento. En la home los ~23 triggers de
 * batch empujaban cada frame por encima del presupuesto de 144 Hz (72 fps);
 * el IO no corre en el hilo de scroll.
 */
export function revealOnEnter(targets: Element[], options: RevealOptions = {}): () => void {
  if (!targets.length) return () => {};
  const { y = 24, duration = 0.7, stagger = 0.08, ease = 'power2.out' } = options;

  const ctx = gsap.context(() => {
    gsap.set(targets, { autoAlpha: 0, y });

    const observer = new IntersectionObserver(
      (entries) => {
        const batch = entries.filter((entry) => entry.isIntersecting).map((entry) => entry.target);
        if (!batch.length) return;
        batch.forEach((entry) => observer.unobserve(entry));
        gsap.to(batch, { autoAlpha: 1, y: 0, duration, ease, stagger, overwrite: 'auto' });
      },
      // Equivale al start 'top 90%' del batch que sustituye.
      { rootMargin: '0px 0px -10% 0px', threshold: 0.05 }
    );

    targets.forEach((target) => observer.observe(target));
    return () => observer.disconnect();
  });

  return () => ctx.revert();
}

export function initGlobalRevealsInstant(): gsap.Context {
  return gsap.context(() => {
    const elements = gsap.utils.toArray('.animate-on-scroll') as HTMLElement[];
    elements.forEach((el) => {
      el.classList.add('visible');
    });
  });
}

