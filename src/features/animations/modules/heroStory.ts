import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

/**
 * Hero "periódico" (efecto del dueño 2026-08-15: "se mantiene estático y el
 * resto de la página sube como un periódico frente a los ojos"). Pin de
 * ScrollTrigger con pinSpacing:false — el hero queda fijo y el contenido
 * siguiente (ReviewMarquee, z-10) se desliza POR ENCIMA. El pin es la vía
 * compatible con Lenis: el sticky CSS no funciona (Lenis transforma el
 * scroll, nunca activa el sticky del navegador). El fondo oscuro 3-5%
 * permanente del hero evita el "blanco detrás" al pasar el contenido.
 *
 * 2026-08-15 (fix de raíz): la animación de entrada con SplitText se
 * ELIMINÓ. Causa: usaba `new SplitText(...)` sin importar el módulo →
 * `ReferenceError: SplitText is not defined` → el `gsap.set([subtitle,
 * buttons], {autoAlpha: 0})` previo dejaba subtítulo y botones ocultos
 * para siempre (el hero se veía "en línea recta", solo el h1). La
 * estructura del hero (h1 + subtítulo + 2 botones) debe ser visible por
 * CSS desde el primer momento, sin depender de ninguna animación.
 */
export function initHeroStory(): gsap.MatchMedia {
  const mm = gsap.matchMedia();

  mm.add(
    {
      isDesktop: '(min-width: 768px)',
      isMobile: '(max-width: 767px)',
      reduceMotion: '(prefers-reduced-motion: reduce)',
    },
    (context) => {
      const { reduceMotion } = context.conditions!;
      if (reduceMotion) return;

      const heroSec = document.getElementById('hero');
      if (!heroSec) return;

      context.add(() => {
        ScrollTrigger.create({
          trigger: heroSec,
          start: 'top top',
          end: () => `+=${window.innerHeight}`,
          pin: true,
          pinSpacing: false,
        });
      });
    }
  );

  return mm;
}
