import { gsap } from 'gsap';

export function initMouseTilt(): gsap.MatchMedia {
  // gsap.matchMedia() crea su propio context internamente (docs GSAP).
  const mm = gsap.matchMedia();

  mm.add('(min-width: 768px)', () => {
    const tiltContainers = document.querySelectorAll('[data-tilt-container]');

    tiltContainers.forEach((container) => {
      const layers = container.querySelectorAll('[data-tilt-layer]');
      if (!layers.length) return;

      const setters = Array.from(layers).map((layer) => {
        const el = layer as HTMLElement;
        el.style.willChange = 'transform';
        const factor = parseFloat(el.getAttribute('data-tilt-layer') || '1');
        return {
          xTo: gsap.quickTo(el, 'x', { duration: 0.5, ease: 'power2.out' }),
          yTo: gsap.quickTo(el, 'y', { duration: 0.5, ease: 'power2.out' }),
          factor
        };
      });

      const handleMouseMove = (e: Event) => {
        const mouseEvent = e as MouseEvent;
        const rect = (container as HTMLElement).getBoundingClientRect();
        const relX = (mouseEvent.clientX - rect.left) / rect.width - 0.5;
        const relY = (mouseEvent.clientY - rect.top) / rect.height - 0.5;

        setters.forEach(({ xTo, yTo, factor }) => {
          xTo(relX * 30 * factor);
          yTo(relY * 30 * factor);
        });
      };

      const handleMouseLeave = () => {
        setters.forEach(({ xTo, yTo }) => {
          xTo(0);
          yTo(0);
        });
        layers.forEach((layer) => {
          (layer as HTMLElement).style.willChange = 'auto';
        });
      };

      const handleMouseEnter = () => {
        layers.forEach((layer) => {
          (layer as HTMLElement).style.willChange = 'transform';
        });
      };

      container.addEventListener('mousemove', handleMouseMove);
      container.addEventListener('mouseleave', handleMouseLeave);
      container.addEventListener('mouseenter', handleMouseEnter);

      // gsap.Context only reverts tweens/ScrollTriggers, not native DOM
      // listeners — remove them on revert or SPA navigations accumulate them.
      return () => {
        container.removeEventListener('mousemove', handleMouseMove);
        container.removeEventListener('mouseleave', handleMouseLeave);
        container.removeEventListener('mouseenter', handleMouseEnter);
        layers.forEach((layer) => {
          (layer as HTMLElement).style.willChange = 'auto';
        });
      };
    });
  });

  return mm;
}
