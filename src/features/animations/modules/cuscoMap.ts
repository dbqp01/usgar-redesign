import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

// CuscoMap — draw route traces on scroll + reveal points with stagger.
export function initCuscoMap(container: HTMLElement): () => void {
  const ctx = gsap.context(() => {
    const routes = Array.from(container.querySelectorAll<SVGPathElement>('.map-route'));
    const points = Array.from(container.querySelectorAll<SVGGElement>('.map-point'));

    const setDash = (path: SVGPathElement) => {
      const len = path.getTotalLength();
      gsap.set(path, { strokeDasharray: `${len} ${len}`, strokeDashoffset: len });
      return len;
    };

    routes.forEach(setDash);

    const tl = gsap.timeline({
      scrollTrigger: {
        trigger: container,
        start: 'top 75%',
        once: true,
      },
    });

    routes.forEach((path, i) => {
      tl.to(path, { strokeDashoffset: 0, duration: 1.6, ease: 'power2.inOut' }, i * 0.35);
    });

    tl.fromTo(
      points,
      { autoAlpha: 0, y: 10 },
      { autoAlpha: 1, y: 0, stagger: 0.25, duration: 0.6, ease: 'power2.out' },
      0.4
    );
  }, container);

  return () => ctx.revert();
}
