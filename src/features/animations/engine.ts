import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { register, isHomePage } from './animationLifecycle';
import { initHeroStory } from './modules/heroStory';
import { initRoomsStory } from './modules/roomsStory';
import { initHeritageStory } from './modules/heritageStory';
import { initParallaxCards } from './modules/parallaxCards';
import { initMouseTilt } from './modules/mouseTilt';
import { initVelocityMarquee } from './modules/velocityMarquee';
import { initGlobalReveals, initGlobalRevealsInstant } from './modules/globalReveals';
import { initPageTransitions } from './modules/pageTransition';

gsap.registerPlugin(ScrollTrigger);

const PREFERS_REDUCED_MOTION = '(prefers-reduced-motion: reduce)';
const PREFERS_NO_REDUCE = '(prefers-reduced-motion: no-preference)';

export function bootHomeAnimations(): void {
  const mm = gsap.matchMedia();

  const ctx = gsap.context(() => {
    register('marquee', initVelocityMarquee());

    mm.add(
      {
        isDesktop: '(min-width: 768px)',
        reduceMotion: PREFERS_REDUCED_MOTION,
      },
      (context) => {
        const { isDesktop, reduceMotion } = context.conditions!;

        if (reduceMotion) {
          register('reveals', initGlobalRevealsInstant());
          return;
        }

        register('hero', initHeroStory());

        if (isDesktop) {
          register('rooms', initRoomsStory());
          register('parallax', initParallaxCards());
          register('tilt', initMouseTilt());
        }

        register('heritage', initHeritageStory());
        register('reveals', initGlobalReveals());

        ScrollTrigger.refresh(true);
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
