import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

export function initGlobalReveals(): gsap.Context {
  return gsap.context(() => {
    const elements = gsap.utils.toArray('.animate-on-scroll') as HTMLElement[];
    if (!elements.length) return;

    ScrollTrigger.batch(elements, {
      interval: 0.05,
      batchMax: 6,
      start: 'top bottom-=20px',
      once: true,
      onEnter: (batch) => {
        gsap.to(batch, {
          autoAlpha: 1,
          y: 0,
          stagger: 0.12,
          duration: 0.8,
          ease: 'power3.out',
          overwrite: true,
          onComplete: function() {
            (this.targets() as HTMLElement[]).forEach((el: HTMLElement) => {
              el.style.willChange = 'auto';
              el.classList.add('visible');
            });
          }
        });
      }
    });
  });
}

export function initGlobalRevealsInstant(): gsap.Context {
  return gsap.context(() => {
    const elements = gsap.utils.toArray('.animate-on-scroll') as HTMLElement[];
    elements.forEach((el) => {
      gsap.set(el, { autoAlpha: 1, y: 0 });
      el.style.willChange = 'auto';
      el.classList.add('visible');
    });
  });
}
