import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { register, isHomePage } from './animationLifecycle';
import { initGlobalReveals, initGlobalRevealsInstant } from './modules/globalReveals';
import { initPageTransitions } from './modules/pageTransition';

gsap.registerPlugin(ScrollTrigger);

const PREFERS_REDUCED_MOTION = '(prefers-reduced-motion: reduce)';
const PREFERS_NO_REDUCE = '(prefers-reduced-motion: no-preference)';

// Módulos exclusivos de la home: se cargan bajo demanda para no inflar el bundle
// base que comparten todas las páginas (heroStory, SplitText, parallax, tilt...).
async function loadHomeModules(): Promise<void> {
  const [heroStory, heritage, marquee] = await Promise.all([
    import('./modules/heroStory'),
    import('./modules/heritageStory'),
    import('./modules/velocityMarquee'),
  ]);
  register('marquee', marquee.initVelocityMarquee());
  register('hero', heroStory.initHeroStory());
  register('heritage', heritage.initHeritageStory());
  register('reveals', initGlobalReveals());
  ScrollTrigger.refresh(true);
}

export function bootHomeAnimations(): void {
  const mm = gsap.matchMedia();

  const ctx = gsap.context(() => {
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

        void loadHomeModules().then(() => {
          if (!isDesktop) return;
          void Promise.all([
            import('./modules/heroTextReveal'),
            import('./modules/parallaxCards'),
            import('./modules/mouseTilt'),
          ]).then(([heroText, parallax, tilt]) => {
            register('hero-text', heroText.initHeroTextReveal());
            register('parallax', parallax.initParallaxCards());
            register('tilt', tilt.initMouseTilt());
          });
        });
      }
    );
  });

  register('home', ctx);
}

export function bootPageReveals(): void {
  const mm = gsap.matchMedia();

  const ctx = gsap.context(() => {
    mm.add(PREFERS_NO_REDUCE, () => {
      register('reveals', initGlobalReveals());
      ScrollTrigger.refresh(true);
    });

    mm.add(PREFERS_REDUCED_MOTION, () => {
      register('reveals', initGlobalRevealsInstant());
    });
  });

  register('page', ctx);
}

export function bootAnimations(): void {
  initPageTransitions();

  if (isHomePage()) {
    bootHomeAnimations();
  } else {
    bootPageReveals();
  }
}
