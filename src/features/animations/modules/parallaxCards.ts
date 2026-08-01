import { gsap } from 'gsap';

export function initParallaxCards(): gsap.Context {
  return gsap.context(() => {
    const mm = gsap.matchMedia();

    mm.add('(min-width: 768px)', () => {
      const parallaxElements = document.querySelectorAll('[data-story-parallax]');

      parallaxElements.forEach((el) => {
        const speed = parseFloat(el.getAttribute('data-story-parallax') || '0.15');
        gsap.to(el, {
          y: () => -100 * speed,
          ease: 'none',
          scrollTrigger: {
            trigger: el,
            start: 'top bottom',
            end: 'bottom top',
            scrub: true,
          }
        });
      });
    });
  });
}
