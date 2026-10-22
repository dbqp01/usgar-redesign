import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import {
  getLifecycleGeneration,
  isLifecycleGenerationCurrent,
  register,
  isHomePage,
} from './animationLifecycle';
import { initGlobalReveals, initGlobalRevealsInstant } from './modules/globalReveals';

gsap.registerPlugin(ScrollTrigger);

const PREFERS_REDUCED_MOTION = '(prefers-reduced-motion: reduce)';
const PREFERS_NO_REDUCE = '(prefers-reduced-motion: no-preference)';

// Módulos exclusivos de la home: se cargan bajo demanda para no inflar el bundle
// base que comparten todas las páginas (heritage, marquee, tilt...).
async function loadHomeModules(generation: number): Promise<boolean> {
  const [heritage, marquee] = await Promise.all([
    import('./modules/heritageStory'),
    import('./modules/velocityMarquee'),
  ]);
  if (!isLifecycleGenerationCurrent(generation)) return false;
  register('marquee', marquee.initVelocityMarquee());
  register('heritage', heritage.initHeritageStory());
  register('reveals', initGlobalReveals());
  // Sin ScrollTrigger.refresh(true): no queda ningun trigger con scrub que
  // necesite recalcular posiciones, y refresh(true) fuerza un layout
  // sincrono de toda la pagina (~150ms de bloqueo en load).
  return true;
}

export function bootHomeAnimations(): void {
  // gsap.matchMedia() crea su propio context internamente (docs GSAP): anidarlo
  // en gsap.context() es redundante; mm.revert() equivale a ctx.revert().
  const mm = gsap.matchMedia();

  mm.add(
    {
      isDesktop: '(min-width: 768px)',
      isMobile: '(max-width: 767px)',
      reduceMotion: PREFERS_REDUCED_MOTION,
    },
    (context) => {
      const { isDesktop, reduceMotion } = context.conditions!;

      if (reduceMotion) {
        register('reveals', initGlobalRevealsInstant());
        return;
      }

      const generation = getLifecycleGeneration();
      void loadHomeModules(generation).then((loaded) => {
        if (!loaded || !isDesktop || !isLifecycleGenerationCurrent(generation)) return;
        void import('./modules/mouseTilt').then((tilt) => {
          if (!isLifecycleGenerationCurrent(generation)) return;
          register('tilt', tilt.initMouseTilt());
        });
      });
    }
  );

  register('home', mm);
}

export function bootPageReveals(): void {
  const mm = gsap.matchMedia();

  mm.add(PREFERS_NO_REDUCE, () => {
    register('reveals', initGlobalReveals());
    ScrollTrigger.refresh(true);
  });

  mm.add(PREFERS_REDUCED_MOTION, () => {
    register('reveals', initGlobalRevealsInstant());
  });

  register('page', mm);
}

export function bootAnimations(): void {
  if (isHomePage()) {
    bootHomeAnimations();
  } else {
    bootPageReveals();
  }
}
