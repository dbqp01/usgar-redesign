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

export function initGlobalRevealsInstant(): gsap.Context {
  return gsap.context(() => {
    const elements = gsap.utils.toArray('.animate-on-scroll') as HTMLElement[];
    elements.forEach((el) => {
      el.classList.add('visible');
    });
  });
}

